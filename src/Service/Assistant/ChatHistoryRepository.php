<?php

declare(strict_types=1);

namespace App\Service\Assistant;

use App\Entity\ChatConversation;
use App\Entity\ChatFeedback;
use App\Entity\ChatKnowledgeCandidate;
use App\Entity\ChatMessage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class ChatHistoryRepository
{
    private const CONVERSATION_ID_LENGTH = 32;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function ensureConversation(string $conversationId, string $tenant): void
    {
        $conversationId = $this->normalizeConversationId($conversationId);
        $now = $this->utcNow();

        $conversation = $this->entityManager->find(ChatConversation::class, $conversationId);

        if (!$conversation instanceof ChatConversation) {
            $conversation = new ChatConversation($conversationId, $tenant, $now);
            $this->entityManager->persist($conversation);
            $this->entityManager->flush();

            return;
        }

        $conversation->setTenant($tenant);
        $conversation->touch($now);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function appendMessage(string $conversationId, string $tenant, string $role, string $content, array $metadata = []): void
    {
        $conversationId = $this->normalizeConversationId($conversationId);
        $now = $this->utcNow();

        $this->ensureConversation($conversationId, $tenant);

        $message = new ChatMessage(
            conversationId: $conversationId,
            tenant: $tenant,
            role: $role,
            content: $content,
            metadata: $metadata,
            createdAt: $now
        );

        $this->entityManager->persist($message);

        $conversation = $this->entityManager->find(ChatConversation::class, $conversationId);
        if ($conversation instanceof ChatConversation) {
            $conversation->touch($now);
        }

        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function appendFeedback(string $conversationId, string $tenant, bool $helpful, string $question, string $answer, array $metadata = []): void
    {
        $conversationId = $this->normalizeConversationId($conversationId, false);
        $now = $this->utcNow();

        $feedback = new ChatFeedback(
            conversationId: $conversationId,
            tenant: $tenant,
            helpful: $helpful,
            question: $question,
            answer: $answer,
            metadata: $metadata,
            createdAt: $now
        );

        $this->entityManager->persist($feedback);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $metadata
     */
    public function upsertKnowledgeCandidate(
        string $candidateKey,
        string $conversationId,
        string $tenant,
        bool $helpful,
        string $question,
        string $answer,
        string $status,
        array $analysis = [],
        array $metadata = []
    ): void {
        $conversationId = $this->normalizeConversationId($conversationId, false);
        $candidateKey = trim($candidateKey);

        if ($candidateKey === '') {
            throw new RuntimeException('El identificador del candidato no puede estar vacio.');
        }

        $now = $this->utcNow();
        $indexedAt = $this->normalizeDateTimeValue($analysis['indexed_at'] ?? null);

        /** @var ChatKnowledgeCandidate|null $candidate */
        $candidate = $this->entityManager->getRepository(ChatKnowledgeCandidate::class)->findOneBy([
            'candidateKey' => $candidateKey,
        ]);

        if (!$candidate instanceof ChatKnowledgeCandidate) {
            $candidate = new ChatKnowledgeCandidate(
                candidateKey: $candidateKey,
                conversationId: $conversationId,
                tenant: $tenant,
                helpful: $helpful,
                question: $question,
                answer: $answer,
                status: $status,
                createdAt: $now,
                updatedAt: $now
            );
        }

        $candidate->setConversationId($conversationId);
        $candidate->setTenant($tenant);
        $candidate->setHelpful($helpful);
        $candidate->setQuestion($question);
        $candidate->setAnswer($answer);
        $candidate->setStatus($status);
        $candidate->setTitle(trim((string) ($analysis['title'] ?? '')) ?: null);
        $candidate->setSummary(trim((string) ($analysis['summary'] ?? '')) ?: null);
        $candidate->setContent(trim((string) ($analysis['content'] ?? '')) ?: null);
        $candidate->setLanguage(trim((string) ($analysis['language'] ?? '')) ?: null);
        $candidate->setConfidence(isset($analysis['confidence']) ? (string) $analysis['confidence'] : null);
        $candidate->setShouldIndex(isset($analysis['should_index']) ? (bool) $analysis['should_index'] : null);
        $candidate->setDuplicateOf(isset($analysis['duplicate_of']) ? trim((string) $analysis['duplicate_of']) : null);
        $candidate->setAnalysis($analysis);
        $candidate->setMetadata($metadata);
        $candidate->setUpdatedAt($now);
        $candidate->setIndexedAt($indexedAt);
        $candidate->setIndexedPointId(isset($analysis['indexed_point_id']) ? trim((string) $analysis['indexed_point_id']) : null);

        $this->entityManager->persist($candidate);
        $this->entityManager->flush();
    }

    /**
     * @return array<int, array{role:string, content:string, created_at:string, metadata:array<string, mixed>}>
     */
    public function fetchMessages(string $conversationId, string $tenant, int $limit = 20): array
    {
        $conversationId = $this->normalizeConversationId($conversationId);

        $rows = $this->entityManager->createQueryBuilder()
            ->select('message')
            ->from(ChatMessage::class, 'message')
            ->where('message.conversationId = :conversationId')
            ->andWhere('message.tenant = :tenant')
            ->setParameter('conversationId', $conversationId)
            ->setParameter('tenant', $tenant)
            ->orderBy('message.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        $rows = array_reverse($rows);

        return array_map(static function (ChatMessage $message): array {
            return [
                'role' => $message->getRole(),
                'content' => $message->getContent(),
                'created_at' => $message->getCreatedAt()->format(DATE_ATOM),
                'metadata' => $message->getMetadata(),
            ];
        }, $rows);
    }

    public function conversationExists(string $conversationId, string $tenant): bool
    {
        $conversationId = $this->normalizeConversationId($conversationId);

        /** @var ChatConversation|null $conversation */
        $conversation = $this->entityManager->find(ChatConversation::class, $conversationId);

        return $conversation instanceof ChatConversation && $conversation->getTenant() === $tenant;
    }

    public function conversationIdFromClientKey(string $tenant, string $clientKey): string
    {
        $tenant = trim($tenant);
        $clientKey = trim($clientKey);

        if ($tenant === '' || $clientKey === '') {
            throw new RuntimeException('tenant y clientKey son obligatorios para resolver la conversacion.');
        }

        return $this->normalizeConversationId(md5(mb_strtolower($tenant . '|' . $clientKey)));
    }

    /**
     * @return array{conversation_id:string, messages:array<int, array{role:string, content:string, created_at:string, metadata:array<string, mixed>}>}
     */
    public function bootstrapConversation(string $tenant, string $clientKey, int $limit = 20): array
    {
        $conversationId = $this->conversationIdFromClientKey($tenant, $clientKey);
        $this->ensureConversation($conversationId, $tenant);

        return [
            'conversation_id' => $conversationId,
            'messages' => $this->fetchMessages($conversationId, $tenant, $limit),
        ];
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function normalizeConversationId(string $conversationId, bool $strict = true): string
    {
        $conversationId = trim($conversationId);

        if ($conversationId === '') {
            throw new RuntimeException('El identificador de conversacion no puede estar vacio.');
        }

        if (strlen($conversationId) !== self::CONVERSATION_ID_LENGTH) {
            if (!$strict) {
                return md5($conversationId);
            }

            throw new RuntimeException(sprintf(
                'El identificador de conversacion debe tener %d caracteres hexadecimales.',
                self::CONVERSATION_ID_LENGTH
            ));
        }

        if (!ctype_xdigit($conversationId)) {
            if (!$strict) {
                return md5($conversationId);
            }

            throw new RuntimeException('El identificador de conversacion debe ser hexadecimal.');
        }

        return strtolower($conversationId);
    }

    private function normalizeDateTimeValue(mixed $value): ?DateTimeImmutable
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        $formats = [
            'Y-m-d H:i:s',
            DateTimeImmutable::ATOM,
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s.uP',
            'Y-m-d\TH:i:s',
        ];

        foreach ($formats as $format) {
            $dateTime = DateTimeImmutable::createFromFormat($format, $normalized);
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime;
            }
        }

        try {
            return new DateTimeImmutable($normalized);
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\ChatProviderInterface;
use App\DTO\FeedbackAnalysisPromptInput;
use App\DTO\FeedbackRequest;
use App\DTO\IndexDocument;
use App\Service\Ai\Chat\FeedbackAnalysisPromptBuilder;
use Throwable;

final class FeedbackLearningService
{
    private const APPROVAL_CONFIDENCE_THRESHOLD = 0.65;

    public function __construct(
        private readonly ChatHistoryRepository $chatHistoryRepository,
        private readonly ChatProviderInterface $chatProvider,
        private readonly FeedbackAnalysisPromptBuilder $feedbackPromptBuilder,
        private readonly IndexDocumentProcessor $indexDocumentProcessor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function record(FeedbackRequest $feedback): array
    {
        [$conversationId, $clientKey, $metadata] = $this->buildConversationContext($feedback, [
            'helpful' => $feedback->helpful,
            'approval_mode' => 'auto',
        ]);

        $this->chatHistoryRepository->appendFeedback(
            $conversationId,
            $feedback->tenant,
            $feedback->helpful,
            $feedback->question,
            $feedback->answer,
            $metadata
        );

        if (!$feedback->helpful) {
            $this->chatHistoryRepository->upsertKnowledgeCandidate(
                candidateKey: $this->candidateKey($feedback->tenant, $conversationId, $feedback->question, $feedback->answer),
                conversationId: $conversationId,
                tenant: $feedback->tenant,
                helpful: false,
                question: $feedback->question,
                answer: $feedback->answer,
                status: 'rejected',
                analysis: [
                    'should_index' => false,
                    'reason' => 'User marked the answer as not useful.',
                    'confidence' => 0.0,
                ],
                metadata: $metadata
            );

            return [
                'message' => 'Feedback registrado.',
                'helpful' => false,
                'indexed' => false,
                'candidate_status' => 'rejected',
            ];
        }

        $history = $this->chatHistoryRepository->fetchMessages($conversationId, $feedback->tenant, 12);
        $analysis = $this->analyzeFeedback($feedback, $history);
        $candidateStatus = $this->shouldPromote($analysis) ? 'approved' : 'pending_review';
        $indexedPointId = null;
        $indexedAt = null;
        $indexedCollection = null;

        if ($candidateStatus === 'approved') {
            try {
                $document = IndexDocument::fromArray([
                    'id' => $this->candidateKey($feedback->tenant, $conversationId, $feedback->question, $feedback->answer),
                    'type' => 'chat_knowledge',
                    'source' => 'assistant_feedback',
                    'tenant' => $feedback->tenant,
                    'title' => $analysis['title'] ?? $this->buildTitle($feedback->question),
                    'content' => $analysis['content'] ?? $this->buildContent($feedback->question, $feedback->answer),
                    'metadata' => array_merge($metadata, [
                        'document_kind' => 'chat_knowledge',
                        'question' => $feedback->question,
                        'answer' => $feedback->answer,
                        'analysis' => $analysis,
                        'language' => $analysis['language'] ?? $feedback->locale,
                        'candidate_status' => $candidateStatus,
                    ]),
                    'operation' => 'upsert',
                ]);

                $indexResponse = $this->indexDocumentProcessor->process($document);
                $indexedPointId = $indexResponse->pointId;
                $indexedCollection = $indexResponse->collection;
                $indexedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $analysis['indexed_point_id'] = $indexedPointId;
                $analysis['indexed_at'] = $indexedAt;
            } catch (Throwable $exception) {
                $candidateStatus = 'pending_review';
                $analysis['should_index'] = false;
                $analysis['reason'] = trim((string) ($analysis['reason'] ?? '')) !== ''
                    ? $analysis['reason']
                    : 'indexing_failed: ' . $exception->getMessage();
                $analysis['index_error'] = $exception->getMessage();
            }
        }

        $this->chatHistoryRepository->upsertKnowledgeCandidate(
            candidateKey: $this->candidateKey($feedback->tenant, $conversationId, $feedback->question, $feedback->answer),
            conversationId: $conversationId,
            tenant: $feedback->tenant,
            helpful: true,
            question: $feedback->question,
            answer: $feedback->answer,
            status: $candidateStatus,
            analysis: $analysis,
            metadata: $metadata
        );

        return [
            'message' => $candidateStatus === 'approved'
                ? 'Feedback registrado e indexado como conocimiento limpio.'
                : 'Feedback registrado para revisión.',
            'helpful' => true,
            'indexed' => $indexedPointId !== null,
            'candidate_status' => $candidateStatus,
            'analysis' => $analysis,
            'collection' => $indexedCollection,
            'point_id' => $indexedPointId,
            'indexed_at' => $indexedAt,
        ];
    }

    /**
     * @return array{0:string,1:string,2:array<string, mixed>}
     */
    private function buildConversationContext(FeedbackRequest $feedback, array $metadataExtras = []): array
    {
        $conversationId = trim((string) ($feedback->conversationId ?? ''));
        $clientKey = trim((string) ($feedback->clientKey ?? ''));

        if ($conversationId === '' && $clientKey !== '') {
            $conversationId = $this->chatHistoryRepository->conversationIdFromClientKey($feedback->tenant, $clientKey);
        }

        if ($conversationId === '') {
            $conversationId = md5(implode('|', [
                $feedback->tenant,
                $feedback->locale,
                trim(mb_strtolower($feedback->question)),
                trim(mb_strtolower($feedback->answer)),
            ]));
        }

        $metadata = array_filter([
            'conversation_id' => $feedback->conversationId,
            'conversation_id_normalized' => $conversationId,
            'client_key' => $clientKey !== '' ? $clientKey : null,
            'locale' => $feedback->locale,
            'context' => $feedback->context,
            'metadata' => $feedback->metadata,
        ] + $metadataExtras, static fn (mixed $value): bool => $value !== null && $value !== []);

        return [$conversationId, $clientKey, $metadata];
    }

    /**
     * @param array<int, array{role:string, content:string, created_at:string, metadata:array<string, mixed>}> $history
     * @return array<string, mixed>
     */
    private function analyzeFeedback(FeedbackRequest $feedback, array $history): array
    {
        $context = [
            'pathname' => (string) ($feedback->context['pathname'] ?? ''),
            'application_locale' => $feedback->locale,
            'message_locale' => $feedback->locale,
            'response_locale' => $feedback->locale,
        ];

        $analysisPromptInput = new FeedbackAnalysisPromptInput(
            question: $feedback->question,
            answer: $feedback->answer,
            history: $history,
            context: $context,
            tenant: $feedback->tenant,
            locale: $feedback->locale,
        );

        try {
            $response = $this->chatProvider->chat(
                message: '',
                context: $context,
                tenant: $feedback->tenant,
                locale: $feedback->locale,
                history: $history,
                vectorContext: ['ok' => false, 'collection' => '', 'matches' => []],
                qdrantHealth: ['ok' => true],
                extraInstruction: '',
                systemPrompt: $this->feedbackPromptBuilder->buildSystemPrompt(),
                userPrompt: $this->feedbackPromptBuilder->buildUserPrompt($analysisPromptInput),
            );
            $content = trim((string) ($response['content'] ?? ''));
        } catch (Throwable $exception) {
            return [
                'should_index' => false,
                'reason' => 'analysis_failed: ' . $exception->getMessage(),
                'confidence' => 0.0,
            ];
        }

        $decoded = $this->extractJson($content);
        if ($decoded === null) {
            return [
                'should_index' => false,
                'reason' => 'analysis_invalid_json',
                'confidence' => 0.0,
                'raw' => $content,
            ];
        }

        $decoded['should_index'] = $this->normalizeBool($decoded['should_index'] ?? false);
        $decoded['model_confidence'] = isset($decoded['confidence']) ? (float) $decoded['confidence'] : 0.0;
        $decoded['confidence'] = $this->deriveConfidence($feedback, $decoded, $history);
        $decoded['title'] = trim((string) ($decoded['title'] ?? ''));
        $decoded['summary'] = trim((string) ($decoded['summary'] ?? ''));
        $decoded['content'] = trim((string) ($decoded['content'] ?? ''));
        $decoded['language'] = trim((string) ($decoded['language'] ?? $feedback->locale));
        $decoded['reason'] = trim((string) ($decoded['reason'] ?? ''));
        $decoded['duplicate_of'] = trim((string) ($decoded['duplicate_of'] ?? ''));
        $decoded['keywords'] = is_array($decoded['keywords'] ?? null) ? $decoded['keywords'] : [];

        if ($decoded['should_index'] && $decoded['title'] === '') {
            $decoded['should_index'] = false;
            $decoded['reason'] = 'analysis_missing_title';
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<int, array{role:string, content:string, created_at:string, metadata:array<string, mixed>}> $history
     */
    private function deriveConfidence(FeedbackRequest $feedback, array $analysis, array $history): float
    {
        if (!$this->normalizeBool($analysis['should_index'] ?? false)) {
            return 0.0;
        }

        $confidence = 0.35;
        if (trim((string) ($analysis['title'] ?? '')) !== '') {
            $confidence += 0.2;
        }

        if (trim((string) ($analysis['summary'] ?? '')) !== '') {
            $confidence += 0.2;
        }

        if (trim((string) ($analysis['content'] ?? '')) !== '') {
            $confidence += 0.15;
        }

        if (mb_strlen(trim($feedback->question)) >= 12) {
            $confidence += 0.05;
        }

        if (mb_strlen(trim($feedback->answer)) >= 20) {
            $confidence += 0.05;
        }

        if (count($history) >= 4) {
            $confidence += 0.05;
        }

        if (trim((string) ($analysis['duplicate_of'] ?? '')) !== '') {
            $confidence -= 0.2;
        }

        if (mb_strlen(trim((string) ($analysis['reason'] ?? ''))) > 120) {
            $confidence -= 0.05;
        }

        return max(0.0, min(1.0, $confidence));
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function shouldPromote(array $analysis): bool
    {
        if (!($analysis['should_index'] ?? false)) {
            return false;
        }

        return (float) ($analysis['confidence'] ?? 0.0) >= self::APPROVAL_CONFIDENCE_THRESHOLD;
    }

    private function candidateKey(string $tenant, string $conversationId, string $question, string $answer): string
    {
        return hash('sha256', implode('|', [
            $tenant,
            $conversationId,
            trim(mb_strtolower($question)),
            trim(mb_strtolower($answer)),
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($content, $start, $end - $start + 1);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function buildTitle(string $question): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);

        return mb_substr($title, 0, 120);
    }

    private function buildContent(string $question, string $answer): string
    {
        return trim(implode("\n\n", [
            'Pregunta: ' . trim($question),
            'Respuesta: ' . trim($answer),
        ]));
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
    }
}

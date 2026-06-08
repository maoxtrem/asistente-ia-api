<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AssistantResponder;
use App\Service\ChatHistoryRepository;
use App\Service\QdrantClient;
use RuntimeException;
use Throwable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ChatController
{
    public function __construct(
        private readonly QdrantClient $qdrantClient,
        private readonly AssistantResponder $assistantResponder,
        private readonly ChatHistoryRepository $chatHistoryRepository,
        private readonly string $assistantName,
    ) {
    }

    #[Route('/api/chat', name: 'api_chat', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Invalid JSON payload.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = trim((string) ($payload['message'] ?? ''));
        $conversationId = trim((string) ($payload['conversation_id'] ?? ''));
        $tenant = trim((string) ($payload['tenant'] ?? ''));
        $locale = $this->normalizeLocale($payload['locale'] ?? '');

        if ($message === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'The message field is required.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($conversationId !== '' && (strlen($conversationId) !== 32 || !ctype_xdigit($conversationId))) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'El identificador de conversacion no es valido.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conversationId = $this->resolveConversationId($conversationId);
        $this->chatHistoryRepository->ensureConversation($conversationId, $tenant);
        $history = $this->chatHistoryRepository->fetchMessages($conversationId, $tenant, 12);
        $this->chatHistoryRepository->appendMessage($conversationId, $tenant, 'user', $message, [
            'locale' => $locale,
            'context' => is_array($payload['context'] ?? null) ? $payload['context'] : [],
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        ]);

        $qdrantHealth = $this->qdrantClient->health();

        try {
            $assistantReply = $this->assistantResponder->respond(
                message: $message,
                context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
                tenant: $tenant,
                locale: $locale,
                conversationId: $conversationId,
                history: $history,
                qdrantHealth: $qdrantHealth,
            );
        } catch (RuntimeException $exception) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_GATEWAY);
        } catch (Throwable $exception) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'No fue posible generar la respuesta del asistente.',
                'raw' => [
                    'error' => $exception->getMessage(),
                ],
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        $this->chatHistoryRepository->appendMessage($conversationId, $tenant, 'assistant', $assistantReply['message'], [
            'locale' => $locale,
            'message_locale' => $assistantReply['message_locale'] ?? 'unknown',
            'response_locale' => $assistantReply['response_locale'] ?? $locale,
            'intent' => $assistantReply['intent'] ?? null,
            'links' => $assistantReply['links'] ?? [],
            'context_note' => $assistantReply['context_note'] ?? '',
        ]);

        return new JsonResponse([
            'status' => 'success',
            'data' => [
                'message' => $assistantReply['message'],
                'conversation_id' => $conversationId,
                'assistant' => $this->assistantName,
                'tenant' => $tenant,
                'message_locale' => $assistantReply['message_locale'] ?? 'unknown',
                'response_locale' => $assistantReply['response_locale'] ?? $locale,
                'links' => $assistantReply['links'],
                'intent' => $assistantReply['intent'],
                'context_note' => $assistantReply['context_note'],
                'sources' => $assistantReply['sources'],
                'qdrant' => $qdrantHealth,
            ],
        ]);
    }

    private function resolveConversationId(string $conversationId): string
    {
        if ($conversationId === '') {
            return bin2hex(random_bytes(16));
        }

        return strtolower($conversationId);
    }

    private function normalizeLocale(mixed $locale): string
    {
        $normalized = strtolower(trim((string) ($locale ?? '')));

        return str_replace('_', '-', $normalized);
    }
}

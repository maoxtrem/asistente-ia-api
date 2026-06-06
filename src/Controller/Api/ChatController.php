<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AssistantResponder;
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

        if ($message === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'The message field is required.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $qdrantHealth = $this->qdrantClient->health();

        try {
            $assistantReply = $this->assistantResponder->respond(
                message: $message,
                context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
                tenant: $tenant,
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

        return new JsonResponse([
            'status' => 'success',
            'data' => [
                'message' => $assistantReply['message'],
                'conversation_id' => $conversationId !== '' ? $conversationId : bin2hex(random_bytes(8)),
                'assistant' => $this->assistantName,
                'tenant' => $tenant,
                'links' => $assistantReply['links'],
                'intent' => $assistantReply['intent'],
                'context_note' => $assistantReply['context_note'],
                'sources' => $assistantReply['sources'],
                'qdrant' => $qdrantHealth,
            ],
        ]);
    }
}

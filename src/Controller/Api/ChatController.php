<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\QdrantClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ChatController
{
    public function __construct(
        private readonly QdrantClient $qdrantClient,
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

        if ($message === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'The message field is required.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $qdrantHealth = $this->qdrantClient->health();

        return new JsonResponse([
            'status' => 'success',
            'data' => [
                'message' => sprintf(
                    'Recibi tu mensaje: "%s". Qdrant esta %s y este endpoint ya esta listo para conectar la logica del asistente.',
                    $message,
                    $qdrantHealth['ok'] ? 'disponible' : 'no disponible'
                ),
                'conversation_id' => $conversationId !== '' ? $conversationId : bin2hex(random_bytes(8)),
                'assistant' => $this->assistantName,
                'qdrant' => $qdrantHealth,
            ],
        ]);
    }
}

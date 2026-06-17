<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\ChatHistoryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class BootstrapController
{
    public function __construct(
        private readonly ChatHistoryRepository $chatHistoryRepository,
        private readonly string $assistantName,
    ) {
    }

    #[Route('/api/bootstrap', name: 'api_bootstrap', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Invalid JSON payload.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $tenant = trim((string) ($payload['tenant'] ?? ''));
        $clientKey = trim((string) ($payload['client_key'] ?? ''));
        $limit = max(1, min(50, (int) ($payload['limit'] ?? 20)));

        if ($tenant === '' || $clientKey === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'tenant and client_key are required.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $bootstrap = $this->chatHistoryRepository->bootstrapConversation($tenant, $clientKey, $limit);
        $messages = array_map(static function (array $item): array {
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            $links = [];

            if (isset($metadata['links']) && is_array($metadata['links'])) {
                $links = $metadata['links'];
            }

            return [
                'role' => (string) ($item['role'] ?? ''),
                'content' => (string) ($item['content'] ?? ''),
                'created_at' => (string) ($item['created_at'] ?? ''),
                'links' => $links,
                'metadata' => $metadata,
            ];
        }, $bootstrap['messages']);

        return new JsonResponse([
            'status' => 'success',
            'data' => [
                'conversation_id' => $bootstrap['conversation_id'],
                'messages' => $messages,
                'assistant' => $this->assistantName,
                'bundle' => [
                    'widget_url' => '/asistente-ia/widget',
                    'vector_form_url' => '/asistente-ia/vectorial',
                ],
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\QdrantClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(
        private readonly QdrantClient $qdrantClient,
        private readonly string $assistantName,
    ) {
    }

    #[Route('/', name: 'api_root', methods: ['GET'])]
    public function root(): JsonResponse
    {
        return new JsonResponse([
            'service' => $this->assistantName,
            'status' => 'ok',
            'docs' => [
                'health' => '/api/health',
                'chat' => '/api/chat',
            ],
        ]);
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'service' => $this->assistantName,
            'status' => 'ok',
            'qdrant' => $this->qdrantClient->health(),
        ]);
    }
}

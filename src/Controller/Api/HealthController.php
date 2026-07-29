<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Qdrant\Qdrant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(
        #[Autowire(service: 'qdrant.official_client')]
        private readonly Qdrant $qdrant,
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
                'html_templates' => '/api/html-templates',
                'ia_health' => '/api/ia/health',
                'feedback' => '/api/feedback',
                'canvas' => '/api/v1/asistentecamvasia/canvas/generate',
            ],
        ]);
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        try {
            $response = $this->qdrant->collections()->list();
            $collections = $response['result'] ?? [];

            $qdrantHealth = [
                'ok' => true,
                'collections' => is_array($collections) ? $collections : [],
            ];
        } catch (\Throwable $exception) {
            $qdrantHealth = [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }

        return new JsonResponse([
            'service' => $this->assistantName,
            'status' => 'ok',
            'qdrant' => $qdrantHealth,
        ]);
    }
}

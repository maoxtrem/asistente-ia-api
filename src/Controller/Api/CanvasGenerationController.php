<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\CanvasGenerationRequest;
use App\Service\CanvasGenerationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CanvasGenerationController
{
    public function __construct(
        private readonly CanvasGenerationService $canvasGenerationService,
        private readonly string $assistantName,
    ) {
    }

    #[Route('/api/v1/asistentecamvasia/canvas/generate', name: 'asistentecamvasia_canvas_generate', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'El cuerpo debe ser JSON valido.',
                'design' => null,
                'actions' => [],
                'raw' => ['error' => 'invalid_json'],
                'assistant' => $this->assistantName,
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $canvasRequest = CanvasGenerationRequest::fromArray($payload);

        if ($canvasRequest->message === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => 'El campo message es obligatorio.',
                'design' => null,
                'actions' => [],
                'raw' => ['error' => 'message_required'],
                'assistant' => $this->assistantName,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = $this->canvasGenerationService->generate($canvasRequest);

        return new JsonResponse($response->toArray() + [
            'assistant' => $this->assistantName,
        ]);
    }
}

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
    ) {
    }

    #[Route('/api/v1/asistentecamvasia/canvas/generate', name: 'asistentecamvasia_canvas_generate', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse($this->buildErrorPayload('El cuerpo debe ser JSON valido.'), JsonResponse::HTTP_BAD_REQUEST);
        }

        $canvasRequest = CanvasGenerationRequest::fromArray($payload);

        if ($canvasRequest->message === '') {
            return new JsonResponse($this->buildErrorPayload('El campo question o message es obligatorio.'), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = $this->canvasGenerationService->generate($canvasRequest);

        return new JsonResponse($response->toArray());
    }

    /**
     * @return array{ok:bool, message:string, imageUrl:?string, imageKey:?string}
     */
    private function buildErrorPayload(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'imageUrl' => null,
            'imageKey' => null,
        ];
    }
}

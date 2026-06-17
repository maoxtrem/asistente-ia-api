<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\FeedbackRequest;
use App\Service\FeedbackLearningService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class FeedbackController
{
    public function __construct(
        private readonly FeedbackLearningService $feedbackLearningService,
    ) {
    }

    #[Route('/api/feedback', name: 'api_feedback', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Invalid JSON payload.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $feedback = FeedbackRequest::fromArray($payload);

        if ($feedback->question === '' || $feedback->answer === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'question and answer are required.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tenant = trim($feedback->tenant);
        if ($tenant === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'tenant is required.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->feedbackLearningService->record($feedback);

        return new JsonResponse([
            'status' => 'success',
            'data' => $result,
            'bundle' => [
                'widget_url' => '/asistente-ia/widget',
                'vector_form_url' => '/asistente-ia/vectorial',
            ],
        ]);
    }
}

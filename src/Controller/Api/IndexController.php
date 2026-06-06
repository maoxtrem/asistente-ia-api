<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\IndexDocument;
use App\Service\IndexDocumentProcessor;
use RuntimeException;
use Throwable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class IndexController
{
    public function __construct(
        private readonly IndexDocumentProcessor $indexDocumentProcessor,
    ) {
    }

    #[Route('/api/index/documents', name: 'api_index_documents', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Invalid JSON payload.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $document = IndexDocument::fromArray($payload);

        try {
            $response = $this->indexDocumentProcessor->process($document);
        } catch (RuntimeException $exception) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $exception) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'No fue posible procesar el documento de indexacion.',
                'raw' => [
                    'error' => $exception->getMessage(),
                ],
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'status' => 'success',
            'data' => $response->toArray(),
        ]);
    }
}

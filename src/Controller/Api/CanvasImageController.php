<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Canvas\PdfImageClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CanvasImageController
{
    public function __construct(
        private readonly PdfImageClient $imageClient,
    ) {
    }

    #[Route('/api/v1/asistentecamvasia/canvas/image', name: 'asistentecamvasia_canvas_image', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $key = trim((string) $request->query->get('key', ''));
        if ($key === '') {
            return new Response('Missing image key.', Response::HTTP_BAD_REQUEST);
        }

        if (filter_var($key, FILTER_VALIDATE_URL)) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $key,
            ]);
        }

        return new Response('Las imagenes nuevas se sirven desde service-pdf. Usa imageUrl.', Response::HTTP_GONE);
    }

    #[Route('/api/v1/asistentecamvasia/canvas/images', name: 'asistentecamvasia_canvas_images', methods: ['POST'])]
    public function list(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'El cuerpo debe ser JSON valido.',
                'count' => 0,
                'items' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        $tenant = trim((string) ($payload['tenant'] ?? ''));
        $usuario = trim((string) ($payload['usuario'] ?? ''));
        $entorno = trim((string) ($payload['entorno'] ?? ''));
        $limit = isset($payload['limit']) ? (int) $payload['limit'] : 100;

        $missingFields = [];
        if ($tenant === '') {
            $missingFields[] = 'tenant';
        }
        if ($usuario === '') {
            $missingFields[] = 'usuario';
        }
        if ($entorno === '') {
            $missingFields[] = 'entorno';
        }

        if ($missingFields !== []) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Faltan campos obligatorios.',
                'missing_fields' => $missingFields,
                'count' => 0,
                'items' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->imageClient->list([
                'tenant' => $tenant,
                'usuario' => $usuario,
                'entorno' => $entorno,
                'limit' => $limit,
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'No fue posible consultar el microservicio de imágenes.',
                'error' => $exception->getMessage(),
                'count' => 0,
                'items' => [],
            ], Response::HTTP_BAD_GATEWAY);
        }

        $records = is_array($result['body']['records'] ?? null) ? $result['body']['records'] : [];
        $items = array_map(static fn (array $item): array => [
            'reference' => (string) ($item['reference'] ?? ''),
            'uuid' => (string) ($item['uuid'] ?? ''),
            'tenant' => (string) ($item['tenant'] ?? ''),
            'usuario' => (string) ($item['usuario'] ?? ''),
            'entorno' => (string) ($item['entorno'] ?? ''),
            'status' => (string) ($item['status'] ?? ''),
            'image_file_name' => (string) ($item['image_file_name'] ?? ''),
            'image_mime_type' => (string) ($item['image_mime_type'] ?? ''),
            'imageUrl' => (string) ($item['image_url'] ?? ''),
        ], $records);

        return new JsonResponse([
            'ok' => (bool) ($result['ok'] ?? false),
            'count' => count($items),
            'items' => $items,
        ]);
    }
}

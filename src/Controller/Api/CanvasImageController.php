<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Storage\MinioStorage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CanvasImageController
{
    public function __construct(
        private readonly MinioStorage $minioStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/api/v1/asistentecamvasia/canvas/image', name: 'asistentecamvasia_canvas_image', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $key = trim((string) $request->query->get('key', ''));
        if ($key === '') {
            return new Response('Missing image key.', Response::HTTP_BAD_REQUEST);
        }

        $contents = $this->minioStorage->download($key);

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    #[Route('/api/v1/asistentecamvasia/canvas/images', name: 'asistentecamvasia_canvas_images', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $tenant = trim((string) $request->query->get('tenant', ''));
        $locale = trim((string) $request->query->get('locale', ''));
        $prefix = trim((string) $request->query->get('prefix', ''));
        $limit = (int) $request->query->get('limit', 100);

        $effectivePrefix = $prefix;
        if ($tenant !== '') {
            $effectivePrefix = trim(sprintf('canvas/%s/%s', $tenant, $locale !== '' ? $locale : ''), '/') . '/';
            if ($prefix !== '') {
                $effectivePrefix = rtrim($effectivePrefix, '/') . '/' . ltrim($prefix, '/');
            }
        }

        $items = array_map(
            fn (array $item): array => [
                'key' => $item['key'],
                'size' => $item['size'],
                'lastModified' => $item['lastModified'],
                'imageUrl' => $this->urlGenerator->generate('asistentecamvasia_canvas_image', [
                    'key' => $item['key'],
                ], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
            $this->minioStorage->list($effectivePrefix, $limit),
        );

        return new JsonResponse([
            'ok' => true,
            'count' => count($items),
            'items' => $items,
        ]);
    }
}

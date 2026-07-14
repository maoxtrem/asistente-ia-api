<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\HtmlTemplate;
use App\Repository\HtmlTemplateRepository;
use App\Service\HtmlTemplate\HtmlTemplateRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class HtmlTemplateController
{
    public function __construct(
        private readonly HtmlTemplateRepository $htmlTemplateRepository,
        private readonly HtmlTemplateRenderer $htmlTemplateRenderer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/html-templates', name: 'api_html_templates_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $templates = $this->htmlTemplateRepository->findAllOrdered();

        $records = array_map(static function (HtmlTemplate $template): array {
            return [
                'uuid' => $template->getUuid(),
                'name' => $template->getName(),
                'html_content' => $template->getHtmlContent(),
                'json_content' => $template->getJsonContent(),
                'created_at' => $template->getCreatedAt()->format(DATE_ATOM),
                'updated_at' => $template->getUpdatedAt()->format(DATE_ATOM),
            ];
        }, $templates);

        return new JsonResponse([
            'status' => 'success',
            'message' => 'Plantillas obtenidas desde la base de datos.',
            'count' => count($records),
            'records' => $records,
        ]);
    }

    #[Route('/api/html-templates', name: 'api_html_templates_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Invalid JSON payload.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $htmlContent = $this->extractHtmlContent($payload);
        $jsonContent = $this->extractJsonContent($payload);

        if ($name === '' || $htmlContent === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'name and html_content are required.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $template = new HtmlTemplate(
            $this->generateUuid(),
            $name,
            $htmlContent,
            $jsonContent
        );

        try {
            $this->entityManager->persist($template);
            $this->entityManager->flush();
        } catch (Throwable $exception) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'No se pudo guardar la plantilla HTML.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'status' => 'success',
            'data' => [
                'uuid' => $template->getUuid(),
                'name' => $template->getName(),
                'html_content' => $template->getHtmlContent(),
                'json_content' => $template->getJsonContent(),
                'created_at' => $template->getCreatedAt()->format(DATE_ATOM),
                'updated_at' => $template->getUpdatedAt()->format(DATE_ATOM),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/html-templates/{uuid}/{format}', name: 'api_html_templates_show', methods: ['GET'], defaults: ['format' => 'html'], requirements: ['format' => 'html|json'])]
    public function show(string $uuid, string $format = 'html'): Response
    {
        $template = $this->htmlTemplateRepository->findByUuid($uuid);

        if ($template === null) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'No se encontró una plantilla asociada a este uuid.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($format === 'json') {
            return new JsonResponse([
                'status' => 'success',
                'data' => [
                    'uuid' => $template->getUuid(),
                    'name' => $template->getName(),
                    'html_content' => $template->getHtmlContent(),
                    'json_content' => $template->getJsonContent(),
                    'created_at' => $template->getCreatedAt()->format(DATE_ATOM),
                    'updated_at' => $template->getUpdatedAt()->format(DATE_ATOM),
                ],
            ]);
        }

        try {
            $renderedHtml = $this->htmlTemplateRenderer->renderTemplate(
                $template->getHtmlContent(),
                $this->htmlTemplateRenderer->buildContext($template->getJsonContent())
            );
        } catch (Throwable $exception) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'No se pudo renderizar la plantilla HTML.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        return new Response(
            $renderedHtml,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractHtmlContent(array $payload): string
    {
        if (array_key_exists('html_content', $payload) && is_string($payload['html_content'])) {
            return $payload['html_content'];
        }

        if (array_key_exists('html', $payload) && is_string($payload['html'])) {
            return $payload['html'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function extractJsonContent(array $payload): array
    {
        if (array_key_exists('json_content', $payload) && is_array($payload['json_content'])) {
            return $payload['json_content'];
        }

        if (array_key_exists('json', $payload) && is_array($payload['json'])) {
            return $payload['json'];
        }

        return [];
    }
}

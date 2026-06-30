<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\DTO\CanvasGenerationRequest;
use App\DTO\CanvasGenerationResponse;
use App\DTO\IndexDocument;
use App\Event\CanvasGenerationCompleted;
use App\Service\IndexDocumentProcessor;
use JsonException;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final class CanvasGenerationCompletedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly IndexDocumentProcessor $indexDocumentProcessor,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CanvasGenerationCompleted::class => 'onCanvasGenerationCompleted',
        ];
    }

    public function onCanvasGenerationCompleted(CanvasGenerationCompleted $event): void
    {
        try {
            if (!$event->response->ok) {
                return;
            }

            $document = IndexDocument::fromArray([
                'id' => sha1(implode('|', [
                    'canvas-image',
                    $event->request->tenant,
                    $event->request->locale,
                    $event->request->message,
                ])),
                'type' => 'canvas_image',
                'source' => 'asistente_camvasia',
                'tenant' => $event->request->tenant,
                'title' => $this->buildTitle($event->request->message, $event->response->imageKey),
                'content' => $this->buildContent($event->request, $event->response, $event->vectorContext),
                'metadata' => [
                    'document_kind' => 'canvas_image_generation',
                    'message' => $event->request->message,
                    'image_key' => $event->response->imageKey,
                    'image_url' => $event->response->imageUrl,
                    'vector_context' => $event->vectorContext,
                    'response' => $event->response->toArray(),
                ],
                'operation' => 'upsert',
            ]);

            $this->indexDocumentProcessor->process($document);
        } catch (Throwable) {
            // La indexacion no debe romper la generacion del canvas.
        }
    }

    private function buildTitle(string $message, ?string $imageKey): string
    {
        $message = trim($message);
        if ($message === '' && ($imageKey === null || $imageKey === '')) {
            return 'Canvas image generated';
        }

        $title = $message !== '' ? mb_substr($message, 0, 64) : 'Canvas image';

        if ($imageKey !== null && $imageKey !== '') {
            $title .= ' [' . mb_substr($imageKey, 0, 32) . ']';
        }

        return $title;
    }

    /**
     * @param array<string, mixed> $vectorContext
     */
    private function buildContent(
        CanvasGenerationRequest $request,
        CanvasGenerationResponse $response,
        array $vectorContext,
    ): string {
        try {
            return json_encode([
                'summary' => $this->buildCanvasSummary($request, $response),
                'request' => $request->toArray(),
                'response' => $response->toArray(),
                'vector_context' => $vectorContext,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('No fue posible serializar el documento canvas a JSON.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCanvasSummary(CanvasGenerationRequest $request, CanvasGenerationResponse $response): array
    {
        return [
            'tenant' => $request->tenant,
            'locale' => $request->locale,
            'message' => $request->message,
            'image_key' => $response->imageKey,
            'image_url' => $response->imageUrl,
            'note' => 'Canvas image generated and stored in MinIO.',
        ];
    }
}

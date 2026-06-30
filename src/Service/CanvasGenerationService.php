<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CanvasGenerationRequest;
use App\DTO\CanvasGenerationResponse;
use App\Event\CanvasGenerationCompleted;
use App\Service\Ai\Chat\CanvasImagePromptBuilder;
use App\Service\Ai\Image\OpenAiImageGenerationProvider;
use App\Service\Storage\MinioStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

final class CanvasGenerationService
{
    public function __construct(
        private readonly CanvasImagePromptBuilder $promptBuilder,
        private readonly OpenAiImageGenerationProvider $imageProvider,
        private readonly MinioStorage $minioStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function generate(CanvasGenerationRequest $request): CanvasGenerationResponse
    {
        try {
            $prompt = $this->promptBuilder->buildUserPrompt($request);
            $response = $this->imageProvider->generate($prompt);
            $imageBytes = $response['image_bytes'];
            $objectKey = $this->buildObjectKey($request);
            $this->minioStorage->upload($objectKey, $imageBytes, 'image/png', [
                'tenant' => $request->tenant,
                'locale' => $request->locale,
                'message_hash' => sha1($request->message),
            ]);

            $canvasResponse = new CanvasGenerationResponse(
                ok: true,
                message: 'Imagen generada correctamente.',
                design: null,
                actions: [],
                imageUrl: $this->urlGenerator->generate('asistentecamvasia_canvas_image', [
                    'key' => $objectKey,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                imageKey: $objectKey,
                raw: [
                    'assistant_response' => $response['raw'],
                    'prompt' => $prompt,
                    'object_key' => $objectKey,
                ],
            );

            $this->eventDispatcher->dispatch(new CanvasGenerationCompleted($request, $canvasResponse, []));

            return $canvasResponse;
        } catch (Throwable $exception) {
            return $this->buildFailureResponse(
                message: $exception->getMessage(),
                raw: [
                    'reason' => $exception->getMessage(),
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function buildFailureResponse(string $message, array $raw): CanvasGenerationResponse
    {
        return new CanvasGenerationResponse(
            ok: false,
            message: $message,
            design: null,
            actions: [],
            imageUrl: null,
            imageKey: null,
            raw: $raw,
        );
    }

    private function buildObjectKey(CanvasGenerationRequest $request): string
    {
        $safeTenant = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $request->tenant) ?: 'tenant';
        $safeLocale = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $request->locale) ?: 'es';

        return sprintf(
            'canvas/%s/%s/%s.png',
            $safeTenant,
            $safeLocale,
            bin2hex(random_bytes(8)),
        );
    }
}

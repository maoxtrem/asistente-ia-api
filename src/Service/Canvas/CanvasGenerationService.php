<?php

declare(strict_types=1);

namespace App\Service\Canvas;

use App\DTO\CanvasGenerationRequest;
use App\DTO\CanvasGenerationResponse;
use App\Service\Ai\Chat\CanvasImagePromptBuilder;
use App\Service\Ai\Image\OpenAiImageGenerationProvider;
use App\Service\ChatToolPdf\ServicePdfImageClient;
use Throwable;

final class CanvasGenerationService
{
    public function __construct(
        private readonly CanvasImagePromptBuilder $promptBuilder,
        private readonly OpenAiImageGenerationProvider $imageProvider,
        private readonly ServicePdfImageClient $imageClient,
        private readonly string $canvasEnvironment,
    ) {
    }

    public function generate(CanvasGenerationRequest $request): CanvasGenerationResponse
    {
        try {
            $prompt = $this->promptBuilder->buildUserPrompt($request);
            $response = $this->imageProvider->generate($prompt);
            $imageBase64 = $response['image_base64'];
            $fileName = $this->buildFileName($request);
            $imageResult = $this->imageClient->create([
                'tenant' => $request->tenant,
                'usuario' => $request->usuario,
                'entorno' => $this->canvasEnvironment !== '' ? $this->canvasEnvironment : 'dev',
                'mime_type' => 'image/png',
                'file_name' => $fileName,
                'image' => $imageBase64,
                'metadata' => $request->metadata,
            ]);

            $canvasResponse = new CanvasGenerationResponse(
                ok: true,
                message: 'Imagen generada y almacenada correctamente.',
                design: null,
                actions: [],
                imageUrl: (string) ($imageResult['image_url'] ?? ''),
                imageKey: (string) ($imageResult['reference'] ?? $imageResult['uuid'] ?? ''),
                raw: [
                    'assistant_response' => $response['raw'],
                    'prompt' => $prompt,
                    'image_response' => $imageResult,
                ],
            );

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

    private function buildFileName(CanvasGenerationRequest $request): string
    {
        $safeTenant = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $request->tenant) ?: 'tenant';
        $safeLocale = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $request->locale) ?: 'es';

        return sprintf(
            'canvas-%s-%s-%s.png',
            $safeTenant,
            $safeLocale,
            bin2hex(random_bytes(8)),
        );
    }
}

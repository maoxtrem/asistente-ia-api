<?php

declare(strict_types=1);

namespace App\Service\Canvas;

use App\DTO\CanvasGenerationRequest;
use App\DTO\CanvasGenerationResponse;
use App\Service\Ai\Chat\CanvasImagePromptBuilder;
use App\Service\Canvas\CanvasImageStorage;
use Throwable;
use RuntimeException;
use Symfony\AI\Platform\Bridge\OpenAi\Image as OpenAiImageModel;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CanvasGenerationService
{
    public function __construct(
        private readonly CanvasImagePromptBuilder $promptBuilder,
        private readonly CanvasImageStorage $imageStorage,
        #[Autowire(service: 'ai.traceable_platform.openai')]
        private readonly PlatformInterface $platform,
        #[Autowire('%app.canvas_image_model%')]
        private readonly string $imageModel,
        #[Autowire('%app.canvas_image_size%')]
        private readonly string $imageSize,
        #[Autowire('%app.canvas_image_quality%')]
        private readonly string $imageQuality,
        #[Autowire('%app.canvas_image_output_format%')]
        private readonly string $imageOutputFormat,
        #[Autowire('%app.canvas_image_background%')]
        private readonly string $imageBackground,
        #[Autowire('%app.canvas_image_environment%')]
        private readonly string $canvasEnvironment,
    ) {
    }

    public function generate(CanvasGenerationRequest $request): CanvasGenerationResponse
    {
        try {
            $prompt = $this->promptBuilder->buildUserPrompt($request);
            $response = $this->generateImage($prompt);
            $imageBase64 = $response['image_base64'];
            $fileName = $this->buildFileName($request);
            $imageResult = $this->imageStorage->create([
                'tenant' => $request->tenant,
                'usuario' => $request->usuario,
                'entorno' => $this->canvasEnvironment !== '' ? $this->canvasEnvironment : 'dev',
                'mime_type' => 'image/png',
                'file_name' => $fileName,
                'image' => $imageBase64,
                'metadata' => $request->metadata,
            ]);

            if (($imageResult['ok'] ?? false) !== true) {
                $errorMessage = (string) ($imageResult['body']['error'] ?? 'No fue posible guardar la imagen.');
                throw new RuntimeException($errorMessage);
            }

            $canvasResponse = new CanvasGenerationResponse(
                ok: true,
                message: 'Imagen generada y almacenada correctamente.',
                design: null,
                actions: [],
                imageUrl: (string) ($imageResult['image_url'] ?? ''),
                imageKey: (string) ($imageResult['object_key'] ?? $imageResult['reference'] ?? $imageResult['uuid'] ?? ''),
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

    /**
     * @return array{image_base64:string, raw: array<string, mixed>}
     */
    private function generateImage(string $prompt): array
    {
        $result = $this->platform->invoke(
            new OpenAiImageModel($this->imageModel),
            $prompt,
            $this->buildImageOptions(),
        )->getResult();

        $binaryResult = $this->extractBinaryResult($result);
        $imageBase64 = $binaryResult->toBase64();

        if ('' === $imageBase64) {
            throw new RuntimeException('OpenAI no devolvio una imagen valida.');
        }

        return [
            'image_base64' => $imageBase64,
            'raw' => $result->getRawResult()?->getData() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildImageOptions(): array
    {
        $options = [
            'n' => 1,
            'size' => trim($this->imageSize),
            'quality' => trim($this->imageQuality),
            'output_format' => trim($this->imageOutputFormat),
            'background' => trim($this->imageBackground),
        ];

        return array_filter(
            $options,
            static fn (mixed $value): bool => !\is_string($value) || '' !== trim($value),
        );
    }

    private function extractBinaryResult(ResultInterface $result): BinaryResult
    {
        if ($result instanceof BinaryResult) {
            return $result;
        }

        if ($result instanceof MultiPartResult) {
            foreach ($result as $part) {
                if ($part instanceof BinaryResult) {
                    return $part;
                }
            }
        }

        throw new RuntimeException('OpenAI no devolvio una imagen valida.');
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

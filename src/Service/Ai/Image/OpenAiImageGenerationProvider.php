<?php

declare(strict_types=1);

namespace App\Service\Ai\Image;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiImageGenerationProvider
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $providerUrl,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $fallbackApiKey,
        private readonly string $size,
        private readonly string $quality,
        private readonly string $outputFormat,
        private readonly string $background,
    ) {
    }

    /**
     * @return array{image_bytes:string, raw: array<string, mixed>}
     */
    public function generate(string $prompt): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->buildEndpoint('/images/generations'), [
                'json' => [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'size' => $this->size,
                    'quality' => $this->quality,
                    'output_format' => $this->outputFormat,
                    'background' => $this->background,
                    'n' => 1,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->requireApiKey(),
                ],
                'timeout' => 120,
                'max_connect_duration' => 10,
            ]);

            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible generar la imagen en OpenAI: %s', $exception->getMessage()), 0, $exception);
        }

        $this->throwIfProviderError($payload);
        $imageBase64 = $this->extractImageBase64($payload);
        if ($imageBase64 === '') {
            throw new RuntimeException('OpenAI no devolvio una imagen valida.');
        }

        $imageBytes = base64_decode($imageBase64, true);
        if ($imageBytes === false || $imageBytes === '') {
            throw new RuntimeException('No fue posible decodificar la imagen devuelta por OpenAI.');
        }

        return [
            'image_bytes' => $imageBytes,
            'raw' => $payload,
        ];
    }

    private function requireApiKey(): string
    {
        $apiKey = trim($this->apiKey);
        if ($apiKey !== '') {
            return $apiKey;
        }

        $fallbackApiKey = trim($this->fallbackApiKey);
        if ($fallbackApiKey !== '') {
            return $fallbackApiKey;
        }

        throw new RuntimeException('OPENAI_API_KEY o CHAT_API_KEY es obligatorio para generar imagenes.');
    }

    private function buildEndpoint(string $path): string
    {
        $baseUrl = rtrim($this->providerUrl, '/');
        $normalizedPath = '/' . ltrim($path, '/');
        $parsedPath = (string) (parse_url($baseUrl, PHP_URL_PATH) ?? '');

        if ($parsedPath !== '' && str_ends_with($parsedPath, '/v1')) {
            return $baseUrl . $normalizedPath;
        }

        return $baseUrl . '/v1' . $normalizedPath;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function throwIfProviderError(array $payload): void
    {
        $detailMessage = (string) ($payload['error']['message'] ?? $payload['detail']['message'] ?? '');
        if ($detailMessage !== '') {
            throw new RuntimeException($detailMessage);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractImageBase64(array $payload): string
    {
        $image = $payload['data'][0] ?? null;
        if (!is_array($image)) {
            return '';
        }

        return (string) ($image['b64_json'] ?? '');
    }
}

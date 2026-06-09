<?php

declare(strict_types=1);

namespace App\Service\Ai\Embedding;

use App\Contract\EmbeddingProviderAdapterInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiCompatibleEmbeddingProvider implements EmbeddingProviderAdapterInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $providerUrl,
        private readonly string $embeddingModel,
        private readonly string $apiKey,
    ) {
    }

    public function supports(string $providerKind): bool
    {
        return $this->normalizeProviderKind($providerKind) === 'openai_compatible';
    }

    /**
     * @return float[]
     */
    public function embed(string $text): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->buildOpenAiCompatibleEndpoint('/embeddings'), [
                'json' => [
                    'model' => $this->embeddingModel,
                    'input' => $text,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->requireApiKey($this->apiKey),
                ],
            ]);

            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible generar el embedding: %s', $exception->getMessage()), 0, $exception);
        }

        $this->throwIfProviderError($payload);
        $embedding = $this->extractEmbedding($payload);
        if (!is_array($embedding) || $embedding === []) {
            throw new RuntimeException('El servicio de embeddings no devolvio un vector valido.');
        }

        return array_values(array_map('floatval', $embedding));
    }

    private function normalizeProviderKind(string $providerKind): string
    {
        $normalized = strtolower(trim($providerKind));

        return match ($normalized) {
            'openai', 'openai_compatible', 'openai-compatible', 'jina' => 'openai_compatible',
            default => $normalized,
        };
    }

    private function requireApiKey(string $apiKey): string
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new RuntimeException('EMBEDDING_PROVIDER_KIND=openai_compatible requiere EMBEDDING_API_KEY.');
        }

        return $apiKey;
    }

    /**
     * @return float[]|null
     */
    private function extractEmbedding(array $payload): ?array
    {
        if (is_array($payload['data'][0]['embedding'] ?? null)) {
            return $payload['data'][0]['embedding'];
        }

        if (is_array($payload['embedding'] ?? null)) {
            return $payload['embedding'];
        }

        return null;
    }

    private function throwIfProviderError(array $payload): void
    {
        $detailMessage = (string) ($payload['detail']['message'] ?? '');
        if ($detailMessage !== '') {
            throw new RuntimeException($detailMessage);
        }

        $errorMessage = (string) ($payload['error']['message'] ?? '');
        if ($errorMessage !== '') {
            throw new RuntimeException($errorMessage);
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message !== '' && !isset($payload['data'])) {
            throw new RuntimeException($message);
        }
    }

    private function buildOpenAiCompatibleEndpoint(string $path): string
    {
        $baseUrl = rtrim($this->providerUrl, '/');
        $normalizedPath = '/' . ltrim($path, '/');
        $parsedPath = (string) (parse_url($baseUrl, PHP_URL_PATH) ?? '');

        if ($parsedPath !== '' && str_ends_with($parsedPath, '/v1')) {
            return $baseUrl . $normalizedPath;
        }

        return $baseUrl . '/v1' . $normalizedPath;
    }
}

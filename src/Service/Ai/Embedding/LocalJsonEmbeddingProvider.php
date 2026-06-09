<?php

declare(strict_types=1);

namespace App\Service\Ai\Embedding;

use App\Contract\EmbeddingProviderAdapterInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LocalJsonEmbeddingProvider implements EmbeddingProviderAdapterInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $providerUrl,
        private readonly string $embeddingModel,
    ) {
    }

    public function supports(string $providerKind): bool
    {
        return $this->normalizeProviderKind($providerKind) === 'local_json';
    }

    /**
     * @return float[]
     */
    public function embed(string $text): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->providerUrl, '/') . '/api/embeddings', [
                'json' => [
                    'model' => $this->embeddingModel,
                    'prompt' => $text,
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
            'local', 'local_json', 'local-json', 'custom', 'custom_json', 'custom-json' => 'local_json',
            default => $normalized,
        };
    }

    /**
     * @return float[]|null
     */
    private function extractEmbedding(array $payload): ?array
    {
        if (is_array($payload['embedding'] ?? null)) {
            return $payload['embedding'];
        }

        if (is_array($payload['data'][0]['embedding'] ?? null)) {
            return $payload['data'][0]['embedding'];
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
}

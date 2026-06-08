<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\EmbeddingProviderInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class EmbeddingClient implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $providerUrl,
        private readonly string $embeddingModel,
    ) {
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

        $embedding = $payload['embedding'] ?? null;
        if (!is_array($embedding) || $embedding === []) {
            throw new RuntimeException('El servicio de embeddings no devolvio un vector valido.');
        }

        return array_values(array_map('floatval', $embedding));
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class QdrantClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $qdrantUrl,
    ) {
    }

    public function health(): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->qdrantUrl, '/') . '/collections');
            $payload = $response->toArray(false);

            return [
                'ok' => $response->getStatusCode() < 400,
                'url' => $this->qdrantUrl,
                'collections' => $payload['result']['collections'] ?? [],
            ];
        } catch (ExceptionInterface $exception) {
            return [
                'ok' => false,
                'url' => $this->qdrantUrl,
                'error' => $exception->getMessage(),
            ];
        }
    }
}

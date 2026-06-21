<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
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

    public function ensureCollection(string $collectionName, int $vectorSize): void
    {
        try {
            $this->httpClient->request('PUT', rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collectionName), [
                'json' => [
                    'vectors' => [
                        'size' => $vectorSize,
                        'distance' => 'Cosine',
                    ],
                ],
            ])->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible preparar la coleccion vectorial: %s', $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @param float[] $vector
     */
    public function upsertPoint(string $collection, string $pointId, array $vector, array $payload): array
    {
        try {
            $response = $this->httpClient->request('PUT', rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collection) . '/points', [
                'json' => [
                    'points' => [
                        [
                            'id' => $pointId,
                            'vector' => $vector,
                            'payload' => $payload,
                        ],
                    ],
                ],
            ]);

            return $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible guardar el punto vectorial: %s', $exception->getMessage()), 0, $exception);
        }
    }

    public function deletePoint(string $collection, string $pointId): void
    {
        try {
            $this->httpClient->request('POST', rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collection) . '/points/delete', [
                'json' => [
                    'points' => [$pointId],
                ],
            ])->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible eliminar el punto vectorial: %s', $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @param float[] $vector
     * @return array<int, array{id:string, score:float, payload:array<string, mixed>}>
     */
    public function searchPoints(string $collection, array $vector, int $limit = 5, ?string $tenant = null, array $matchFilters = []): array
    {
        $json = [
            'vector' => array_values($vector),
            'limit' => max(1, $limit),
            'with_payload' => true,
            'with_vector' => false,
        ];

        $must = [];
        $tenant = trim((string) ($tenant ?? ''));
        if ($tenant !== '') {
            $must[] = [
                'key' => 'tenant',
                'match' => [
                    'value' => $tenant,
                ],
            ];
        }

        foreach ($matchFilters as $key => $value) {
            $key = trim((string) $key);
            $value = $this->normalizeMatchFilterValue($value);

            if ($key === '' || $value === null || $value === '') {
                continue;
            }

            $must[] = [
                'key' => $key,
                'match' => [
                    'value' => $value,
                ],
            ];
        }

        if ($must !== []) {
            $json['filter'] = [
                'must' => $must,
            ];
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collection) . '/points/search', [
                'json' => $json,
            ]);

            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible consultar la coleccion vectorial: %s', $exception->getMessage()), 0, $exception);
        }

        $results = $payload['result'] ?? [];
        if (!is_array($results)) {
            return [];
        }

        return array_values(array_map(static function (array $item): array {
            return [
                'id' => (string) ($item['id'] ?? ''),
                'score' => (float) ($item['score'] ?? 0.0),
                'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : [],
            ];
        }, $results));
    }

    /**
     * @return array{
     *   points: array<int, array{id:string, payload:array<string, mixed>}>,
     *   next_page_offset: int|string|null
     * }
     */
    public function scrollPoints(string $collection, int $limit = 50, int|string|null $offset = null, ?string $tenant = null, array $matchFilters = []): array
    {
        $json = [
            'limit' => max(1, $limit),
            'with_payload' => true,
            'with_vector' => false,
        ];

        if ($offset !== null) {
            $json['offset'] = $offset;
        }

        $must = [];
        $tenant = trim((string) ($tenant ?? ''));
        if ($tenant !== '') {
            $must[] = [
                'key' => 'tenant',
                'match' => [
                    'value' => $tenant,
                ],
            ];
        }

        foreach ($matchFilters as $key => $value) {
            $key = trim((string) $key);
            $value = $this->normalizeMatchFilterValue($value);

            if ($key === '' || $value === null || $value === '') {
                continue;
            }

            $must[] = [
                'key' => $key,
                'match' => [
                    'value' => $value,
                ],
            ];
        }

        if ($must !== []) {
            $json['filter'] = [
                'must' => $must,
            ];
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collection) . '/points/scroll', [
                'json' => $json,
            ]);

            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible recorrer la coleccion vectorial: %s', $exception->getMessage()), 0, $exception);
        }

        $result = $payload['result'] ?? [];
        if (!is_array($result)) {
            return [
                'points' => [],
                'next_page_offset' => null,
            ];
        }

        $points = $result['points'] ?? [];
        if (!is_array($points)) {
            $points = [];
        }

        return [
            'points' => array_values(array_map(static function (array $item): array {
                return [
                    'id' => (string) ($item['id'] ?? ''),
                    'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : [],
                ];
            }, $points)),
            'next_page_offset' => $result['next_page_offset'] ?? null,
        ];
    }

    private function normalizeMatchFilterValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    public function stablePointId(string $seed): string
    {
        $hash = sha1($seed);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            sprintf('%04x', (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000),
            sprintf('%04x', (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000),
            substr($hash, 20, 12)
        );
    }
}

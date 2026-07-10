<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class QdrantClient
{
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly string $qdrantUrl,
    ) {
        $this->httpClient = $this->configureHttpClient($httpClient, $qdrantUrl);
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
            $collectionUrl = rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collectionName);
            $existsResponse = $this->httpClient->request('GET', $collectionUrl . '/exists');
            try {
                $existsPayload = $existsResponse->toArray(false);
            } catch (ClientExceptionInterface $exception) {
                if ($exception->getResponse()->getStatusCode() === 404) {
                    $existsPayload = ['result' => ['exists' => false]];
                } else {
                    throw new RuntimeException(sprintf(
                        'No fue posible verificar si la coleccion "%s" existe: %s',
                        $collectionName,
                        $this->extractQdrantErrorMessage($exception)
                    ), 0, $exception);
                }
            }

            if (($existsPayload['result']['exists'] ?? false) === true) {
                return;
            }

            $response = $this->httpClient->request('PUT', $collectionUrl, [
                'json' => [
                    'vectors' => [
                        'size' => $vectorSize,
                        'distance' => 'Cosine',
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 400) {
                return;
            }

            $body = strtolower($response->getContent(false));
            if (str_contains($body, 'already exists') || str_contains($body, 'already_exist')) {
                return;
            }

            throw new RuntimeException(sprintf(
                'Qdrant rechazo la creacion de la coleccion "%s" con estado HTTP %d: %s',
                $collectionName,
                $statusCode,
                trim($body) !== '' ? trim($body) : 'sin cuerpo de respuesta'
            ));
        } catch (ClientExceptionInterface $exception) {
            throw new RuntimeException(sprintf(
                'No fue posible preparar la coleccion vectorial: %s',
                $this->extractQdrantErrorMessage($exception)
            ), 0, $exception);
        } catch (ExceptionInterface|RuntimeException $exception) {
            throw new RuntimeException(sprintf('No fue posible preparar la coleccion vectorial: %s', $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @param float[] $vector
     */
    public function upsertPoint(string $collection, string $pointId, array $vector, array $payload): array
    {
        return $this->upsertPointsBatch($collection, [
            [
                'id' => $pointId,
                'vector' => $vector,
                'payload' => $payload,
            ],
        ]);
    }

    /**
     * @param array<int, array{id:string|int, vector:float[], payload:array<string, mixed>}> $points
     */
    public function upsertPointsBatch(string $collection, array $points): array
    {
        if ($points === []) {
            return [
                'result' => [
                    'points' => [],
                ],
                'status' => 'ok',
            ];
        }

        try {
            $response = $this->httpClient->request('PUT', rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collection) . '/points?wait=true', [
                'json' => [
                    'points' => array_values(array_map(static function (array $point): array {
                        return [
                            'id' => $point['id'],
                            'vector' => array_values($point['vector']),
                            'payload' => $point['payload'],
                        ];
                    }, $points)),
                ],
            ]);

            return $response->toArray(false);
        } catch (ClientExceptionInterface $exception) {
            throw new RuntimeException(sprintf(
                'No fue posible guardar el punto vectorial: %s',
                $this->extractQdrantErrorMessage($exception)
            ), 0, $exception);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible guardar el punto vectorial: %s', $exception->getMessage()), 0, $exception);
        }
    }

    public function deletePoint(string $collection, string $pointId): void
    {
        try {
            $this->httpClient->request('POST', rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collection) . '/points/delete?wait=true', [
                'json' => [
                    'points' => [$pointId],
                ],
            ])->getStatusCode();
        } catch (ClientExceptionInterface $exception) {
            throw new RuntimeException(sprintf(
                'No fue posible eliminar el punto vectorial: %s',
                $this->extractQdrantErrorMessage($exception)
            ), 0, $exception);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible eliminar el punto vectorial: %s', $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @param float[] $vector
     * @return array<int, array{id:string, score:float, payload:array<string, mixed>}>
     */
    public function searchPoints(string $collection, array $vector, int $limit = 5, ?string $tenant = null, array $matchFilters = [], array $shouldFilters = []): array
    {
        $json = [
            'vector' => array_values($vector),
            'limit' => max(1, $limit),
            'with_payload' => true,
            'with_vector' => false,
        ];

        $filter = $this->buildFilter($tenant, $matchFilters, $shouldFilters);
        if ($filter !== []) {
            $json['filter'] = $filter;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collection) . '/points/search', [
                'json' => $json,
            ]);

            $payload = $response->toArray(false);
        } catch (ClientExceptionInterface $exception) {
            throw new RuntimeException(sprintf(
                'No fue posible consultar la coleccion vectorial: %s',
                $this->extractQdrantErrorMessage($exception)
            ), 0, $exception);
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

        $filter = $this->buildFilter($tenant, $matchFilters);
        if ($filter !== []) {
            $json['filter'] = $filter;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->qdrantUrl, '/') . '/collections/' . rawurlencode($collection) . '/points/scroll', [
                'json' => $json,
            ]);

            $payload = $response->toArray(false);
        } catch (ClientExceptionInterface $exception) {
            throw new RuntimeException(sprintf(
                'No fue posible recorrer la coleccion vectorial: %s',
                $this->extractQdrantErrorMessage($exception)
            ), 0, $exception);
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

    /**
     * @param array<string, mixed> $matchFilters
     * @param array<int, array{key:string, value:mixed}> $shouldFilters
     * @return array<string, mixed>
     */
    private function buildFilter(?string $tenant, array $matchFilters = [], array $shouldFilters = []): array
    {
        $must = [];
        $should = [];

        $tenant = trim((string) ($tenant ?? ''));
        if ($tenant !== '') {
            $clause = $this->buildMatchClause('tenant', $tenant);
            if ($clause !== null) {
                $must[] = $clause;
            }
        }

        foreach ($matchFilters as $key => $value) {
            $clause = $this->buildMatchClause((string) $key, $value);
            if ($clause !== null) {
                $must[] = $clause;
            }
        }

        foreach ($shouldFilters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $clause = $this->buildMatchClause(
                (string) ($filter['key'] ?? ''),
                $filter['value'] ?? null,
            );

            if ($clause !== null) {
                $should[] = $clause;
            }
        }

        $filter = [];
        if ($must !== []) {
            $filter['must'] = $must;
        }

        if ($should !== []) {
            $filter['should'] = $should;
        }

        return $filter;
    }

    /**
     * @return array{key:string, match:array{value:mixed}}|null
     */
    private function buildMatchClause(string $key, mixed $value): ?array
    {
        $key = trim($key);
        $value = $this->normalizeMatchFilterValue($value);

        if ($key === '' || $value === null || $value === '') {
            return null;
        }

        return [
            'key' => $key,
            'match' => [
                'value' => $value,
            ],
        ];
    }

    public function stablePointId(string $seed): string
    {
        // Genera un UUID v5 basado en un namespace predefinido y tu semilla (seed)
        $namespace = Uuid::fromString(Uuid::NAMESPACE_URL);

        return Uuid::v5($namespace, $seed)->toRfc4122();
    }

    private function extractQdrantErrorMessage(ClientExceptionInterface $exception): string
    {
        $response = $exception->getResponse();
        $statusCode = $response->getStatusCode();
        $body = trim($response->getContent(false));

        if ($body === '') {
            return sprintf('HTTP %d: %s', $statusCode, $exception->getMessage());
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $candidates = [
                $decoded['status']['error'] ?? null,
                $decoded['error'] ?? null,
                $decoded['detail'] ?? null,
                $decoded['message'] ?? null,
            ];

            foreach ($candidates as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return $body;
    }

    /**
     * Qdrant local de desarrollo usa un proxy HTTPS con certificado no confiable.
     * Si el host es local, relajamos TLS solo para este cliente.
     *
     * @return HttpClientInterface
     */
    private function configureHttpClient(HttpClientInterface $httpClient, string $qdrantUrl): HttpClientInterface
    {
        $host = strtolower(trim((string) parse_url($qdrantUrl, PHP_URL_HOST)));

        if ($host === '' || (!str_ends_with($host, '.localhost') && $host !== 'localhost' && $host !== '127.0.0.1')) {
            return $httpClient;
        }

        return $httpClient->withOptions([
            'verify_peer' => false,
            'verify_host' => false,
        ]);
    }
}

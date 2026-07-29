<?php

declare(strict_types=1);

namespace App\Service\Vector;

use App\Contract\EmbeddingProviderInterface;
use RuntimeException;
use Qdrant\Models\Filter\Condition\ConditionInterface;
use Qdrant\Models\Filter\Condition\MatchAny;
use Qdrant\Models\Filter\Condition\MatchBool;
use Qdrant\Models\Filter\Condition\MatchInt;
use Qdrant\Models\Filter\Condition\MatchString;
use Qdrant\Models\Filter\Filter;
use Qdrant\Models\Request\Points\QueryRequest;
use Qdrant\Qdrant;

final class VectorContextRetriever
{
    public function __construct(
        private readonly EmbeddingProviderInterface $embeddingClient,
        private readonly Qdrant $qdrant,
        private readonly string $qdrantCollection,
        private readonly array $allowedDocumentKinds,
    ) {
    }

    /**
     * @return array{
     *   ok: bool,
     *   collection: string,
     *   matches: array<int, array{
     *     id: string,
     *     score: float,
     *     title: string,
     *     content: string,
     *     source: string,
     *     type: string,
     *     tenant: string,
     *     metadata: array<string, mixed>
     *   }>,
     *   error?: string
     * }
     */
    public function retrieve(string $message, ?string $tenant = null, int $limit = 3): array
    {
        try {
            $vector = $this->embeddingClient->embed($message);
            $searchTrace = [];
            $matches = $this->searchAcrossTenants($vector, $tenant, $limit, $searchTrace);
        } catch (RuntimeException $exception) {
            return [
                'ok' => false,
                'collection' => $this->qdrantCollection,
                'matches' => [],
                'search_trace' => [],
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'collection' => $this->qdrantCollection,
            'tenant' => trim((string) ($tenant ?? '')),
            'search_trace' => $searchTrace,
            'matches' => array_map(static function (array $match): array {
                $payload = is_array($match['payload'] ?? null) ? $match['payload'] : [];
                $indexedText = trim((string) ($payload['indexed_text'] ?? $payload['content'] ?? ''));

                return [
                    'id' => (string) ($match['id'] ?? ''),
                    'score' => (float) ($match['score'] ?? 0.0),
                    'title' => trim((string) ($payload['title'] ?? '')),
                    'content' => $indexedText,
                    'source' => trim((string) ($payload['source'] ?? '')),
                    'type' => trim((string) ($payload['type'] ?? '')),
                    'tenant' => trim((string) ($payload['tenant'] ?? '')),
                    'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                ];
            }, $matches),
        ];
    }

    /**
     * @return array<int, array{id:string, score:float, payload:array<string, mixed>}>
     */
    private function searchAcrossTenants(array $vector, ?string $tenant, int $limit, array &$searchTrace = []): array
    {
        $tenant = trim((string) ($tenant ?? ''));
        $searchLimit = max(1, $limit * 4);
        $shouldFilters = [];

        if ($tenant !== '') {
            $shouldFilters[] = ['key' => 'tenant', 'value' => $tenant];
            $shouldFilters[] = ['key' => 'tenant', 'value' => 'global'];
            $shouldFilters[] = ['key' => 'is_global', 'value' => true];
        }

        $request = new QueryRequest();
        $request
            ->setQuery(['nearest' => array_values($vector)])
            ->setLimit($searchLimit)
            ->setWithPayload(true)
            ->setWithVector(false);

        $filter = $this->buildFilter(null, [], $shouldFilters);
        if ($filter !== null) {
            $request->setFilter($filter);
        }

        $response = $this->qdrant->collections($this->qdrantCollection)->points()->query()->query($request);
        $results = $response['result']['points'] ?? [];
        if (!is_array($results)) {
            $results = [];
        }

        $searchTrace[] = [
            'tenant' => $tenant !== '' ? $tenant : null,
            'label' => $tenant !== '' ? 'tenant_global_is_global' : 'all',
            'count' => count($results),
        ];

        $merged = [];
        foreach ($results as $result) {
            if (!$this->matchesDocumentKind($result)) {
                continue;
            }

            $dedupeKey = $this->matchKey($result);
            if ($dedupeKey === '') {
                continue;
            }

            if (!isset($merged[$dedupeKey]) || $result['score'] > $merged[$dedupeKey]['score']) {
                $merged[$dedupeKey] = $result;
            }
        }

        $matches = array_values($merged);
        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice($matches, 0, max(1, $limit));
    }

    /**
     * @param array<string, mixed> $matchFilters
     * @param array<int, array{key:string, value:mixed}> $shouldFilters
     */
    private function buildFilter(?string $tenant, array $matchFilters = [], array $shouldFilters = []): ?Filter
    {
        $filter = new Filter();
        $hasConditions = false;

        $tenant = trim((string) ($tenant ?? ''));
        if ($tenant !== '') {
            $condition = $this->buildCondition('tenant', $tenant);
            if ($condition !== null) {
                $filter->addMust($condition);
                $hasConditions = true;
            }
        }

        foreach ($matchFilters as $key => $value) {
            $condition = $this->buildCondition((string) $key, $value);
            if ($condition !== null) {
                $filter->addMust($condition);
                $hasConditions = true;
            }
        }

        foreach ($shouldFilters as $shouldFilter) {
            if (!is_array($shouldFilter)) {
                continue;
            }

            $condition = $this->buildCondition(
                (string) ($shouldFilter['key'] ?? ''),
                $shouldFilter['value'] ?? null,
            );

            if ($condition !== null) {
                $filter->addShould($condition);
                $hasConditions = true;
            }
        }

        return $hasConditions ? $filter : null;
    }

    private function buildCondition(string $key, mixed $value): ?ConditionInterface
    {
        $key = trim($key);
        if ($key === '' || $value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }

            return new MatchString($key, $value);
        }

        if (is_bool($value)) {
            return new MatchBool($key, $value);
        }

        if (is_int($value)) {
            return new MatchInt($key, $value);
        }

        if (is_float($value)) {
            return new MatchAny($key, [$value]);
        }

        if (is_array($value)) {
            $values = array_values(array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== ''));
            if ($values === []) {
                return null;
            }

            return new MatchAny($key, $values);
        }

        $castValue = trim((string) $value);
        if ($castValue === '') {
            return null;
        }

        return new MatchString($key, $castValue);
    }

    /**
     * Accepta el esquema viejo y el nuevo:
     * - document_kind en la raiz del payload
     * - metadata.document_kind dentro del payload
     */
    private function matchesDocumentKind(array $match): bool
    {
        $payload = is_array($match['payload'] ?? null) ? $match['payload'] : [];
        $kind = trim((string) ($payload['document_kind'] ?? ''));
        $allowedKinds = $this->normalizedAllowedDocumentKinds();

        if ($kind !== '') {
            return in_array($kind, $allowedKinds, true);
        }

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $nestedKind = trim((string) ($metadata['document_kind'] ?? ''));

        return $nestedKind === '' || in_array($nestedKind, $allowedKinds, true);
    }

    /**
     * @param array{id:string, score:float, payload:array<string, mixed>} $match
     */
    private function matchKey(array $match): string
    {
        $payload = is_array($match['payload'] ?? null) ? $match['payload'] : [];
        $indexKey = trim((string) ($payload['index_key'] ?? ''));

        if ($indexKey !== '') {
            return $indexKey;
        }

        $id = trim((string) ($match['id'] ?? ''));

        return $id;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedAllowedDocumentKinds(): array
    {
        $kinds = array_map(
            static fn (mixed $kind): string => trim((string) $kind),
            $this->allowedDocumentKinds
        );

        $kinds = array_values(array_filter($kinds, static fn (string $kind): bool => $kind !== ''));

        if ($kinds === []) {
            return ['chat_knowledge'];
        }

        return array_values(array_unique($kinds));
    }
}

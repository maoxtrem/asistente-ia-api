<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\EmbeddingProviderInterface;
use RuntimeException;

final class VectorContextRetriever
{
    public function __construct(
        private readonly EmbeddingProviderInterface $embeddingClient,
        private readonly QdrantClient $qdrantClient,
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
                if ($indexedText !== '' && mb_strlen($indexedText) > 500) {
                    $indexedText = mb_substr($indexedText, 0, 500) . '…';
                }

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
     * @param float[] $vector
     * @return array<int, array{id:string, score:float, payload:array<string, mixed>}>
     */
    private function searchAcrossTenants(array $vector, ?string $tenant, int $limit, array &$searchTrace = []): array
    {
        $tenant = trim((string) ($tenant ?? ''));
        $queries = [];

        if ($tenant !== '') {
            $queries[] = [
                'label' => $tenant,
                'tenant' => $tenant,
                'matchFilters' => [],
            ];
        }

        if ($tenant !== 'global') {
            $queries[] = [
                'label' => 'global',
                'tenant' => 'global',
                'matchFilters' => [],
            ];
        }

        $queries[] = [
            'label' => 'is_global',
            'tenant' => null,
            'matchFilters' => ['is_global' => true],
        ];

        if ($tenant === '') {
            $queries[] = [
                'label' => 'all',
                'tenant' => null,
                'matchFilters' => [],
            ];
        }

        $merged = [];
        foreach ($queries as $query) {
            $results = $this->qdrantClient->searchPoints(
                $this->qdrantCollection,
                $vector,
                $limit,
                $query['tenant'],
                $query['matchFilters']
            );
            $searchTrace[] = [
                'tenant' => $query['tenant'],
                'label' => $query['label'],
                'count' => count($results),
            ];

            foreach ($results as $result) {
                if (!$this->matchesDocumentKind($result)) {
                    continue;
                }

                $dedupeKey = $this->matchKey($result);
                if ($dedupeKey === '') {
                    continue;
                }

                if (!isset($merged[$dedupeKey])) {
                    $merged[$dedupeKey] = $result;
                    continue;
                }

                if ($result['score'] > $merged[$dedupeKey]['score']) {
                    $merged[$dedupeKey] = $result;
                }
            }
        }

        if ($merged === [] && $tenant !== '') {
            $results = $this->qdrantClient->searchPoints($this->qdrantCollection, $vector, $limit, null);
            $searchTrace[] = [
                'tenant' => null,
                'label' => 'fallback_all',
                'count' => count($results),
                'fallback' => true,
            ];

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
        }

        $matches = array_values($merged);
        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice($matches, 0, max(1, $limit));
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

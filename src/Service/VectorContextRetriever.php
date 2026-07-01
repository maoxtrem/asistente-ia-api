<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\EmbeddingProviderInterface;
use App\Contract\ChatProviderInterface;
use RuntimeException;

final class VectorContextRetriever
{
    public function __construct(
        private readonly EmbeddingProviderInterface $embeddingClient,
        private readonly ChatProviderInterface $chatProvider,
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
    public function retrieve(string $message, ?string $tenant = null, int $limit = 3, ?string $messageLocale = null): array
    {
        try {
            $searchTrace = [];
            $matches = $this->searchAcrossTenants($message, $tenant, $limit, $messageLocale, $searchTrace);
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
     * @param float[] $vector
     * @return array<int, array{id:string, score:float, payload:array<string, mixed>}>
     */
    private function searchAcrossTenants(string $message, ?string $tenant, int $limit, ?string $messageLocale, array &$searchTrace = []): array
    {
        $tenant = trim((string) ($tenant ?? ''));
        $merged = [];
        $queries = [$message];

        $messageLocale = trim(strtolower((string) ($messageLocale ?? '')));
        if ($messageLocale !== '' && $messageLocale !== 'unknown' && !str_starts_with($messageLocale, 'es')) {
            $translatedMessage = $this->translateSearchQueryToSpanish($message, $messageLocale);
            if ($translatedMessage !== '' && mb_strtolower($translatedMessage) !== mb_strtolower($message)) {
                $queries[] = $translatedMessage;
            }
        }

        foreach (array_values(array_unique(array_filter($queries, static fn (string $query): bool => trim($query) !== ''))) as $index => $query) {
            $vector = $this->embeddingClient->embed($query);
            $searchLimit = max(1, $limit * 4);
            $shouldFilters = [];

            if ($tenant !== '') {
                $shouldFilters[] = ['key' => 'tenant', 'value' => $tenant];
                $shouldFilters[] = ['key' => 'tenant', 'value' => 'global'];
                $shouldFilters[] = ['key' => 'is_global', 'value' => true];
            }

            $results = $this->qdrantClient->searchPoints(
                $this->qdrantCollection,
                $vector,
                $searchLimit,
                null,
                [],
                $shouldFilters
            );

            $searchTrace[] = [
                'tenant' => $tenant !== '' ? $tenant : null,
                'label' => $index === 0 ? 'original' : 'translated_es',
                'query' => $query,
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

    private function translateSearchQueryToSpanish(string $message, string $messageLocale): string
    {
        $systemPrompt = <<<'PROMPT'
Eres un traductor especializado en consultas de búsqueda para un sistema empresarial.
Traduce la consulta al español neutro.
Devuelve solo el texto traducido, sin comillas, sin explicaciones y sin añadir información nueva.
PROMPT;

        $userPrompt = sprintf(
            "Idioma de origen: %s\nConsulta: %s",
            $messageLocale,
            $message
        );

        try {
            $response = $this->chatProvider->chat(
                message: $message,
                context: [],
                tenant: '',
                locale: 'es',
                history: [],
                vectorContext: ['ok' => true, 'collection' => $this->qdrantCollection, 'matches' => []],
                qdrantHealth: ['ok' => true],
                extraInstruction: 'Traduce la consulta al español neutro.',
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt
            );
        } catch (\Throwable) {
            return '';
        }

        $translated = trim((string) ($response['content'] ?? ''));
        $translated = trim($translated, " \t\n\r\0\x0B\"'");

        return $translated;
    }
}

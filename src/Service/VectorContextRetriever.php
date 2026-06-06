<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class VectorContextRetriever
{
    public function __construct(
        private readonly EmbeddingClient $embeddingClient,
        private readonly QdrantClient $qdrantClient,
        private readonly string $qdrantCollection,
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
    public function retrieve(string $message, ?string $tenant = null, int $limit = 5): array
    {
        try {
            $vector = $this->embeddingClient->embed($message);
            $matches = $this->qdrantClient->searchPoints($this->qdrantCollection, $vector, $limit, $tenant);
        } catch (RuntimeException $exception) {
            return [
                'ok' => false,
                'collection' => $this->qdrantCollection,
                'matches' => [],
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'collection' => $this->qdrantCollection,
            'tenant' => trim((string) ($tenant ?? '')),
            'matches' => array_map(static function (array $match): array {
                $payload = is_array($match['payload'] ?? null) ? $match['payload'] : [];

                return [
                    'id' => (string) ($match['id'] ?? ''),
                    'score' => (float) ($match['score'] ?? 0.0),
                    'title' => trim((string) ($payload['title'] ?? '')),
                    'content' => trim((string) ($payload['indexed_text'] ?? $payload['content'] ?? '')),
                    'source' => trim((string) ($payload['source'] ?? '')),
                    'type' => trim((string) ($payload['type'] ?? '')),
                    'tenant' => trim((string) ($payload['tenant'] ?? '')),
                    'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                ];
            }, $matches),
        ];
    }
}

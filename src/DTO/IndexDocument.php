<?php

declare(strict_types=1);

namespace App\DTO;

final class IndexDocument
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $source,
        public readonly string $tenant,
        public readonly string $title,
        public readonly string $content,
        public readonly array $metadata = [],
        public readonly string $operation = 'upsert',
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            id: trim((string) ($payload['id'] ?? '')),
            type: trim((string) ($payload['type'] ?? '')),
            source: trim((string) ($payload['source'] ?? '')),
            tenant: trim((string) ($payload['tenant'] ?? '')),
            title: trim((string) ($payload['title'] ?? '')),
            content: trim((string) ($payload['content'] ?? '')),
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            operation: self::normalizeOperation((string) ($payload['operation'] ?? 'upsert')),
        );
    }

    public function isDeletion(): bool
    {
        return $this->operation === 'delete';
    }

    public function indexKey(): string
    {
        return implode(':', array_filter([
            $this->source,
            $this->tenant,
            $this->type,
            $this->id,
        ], static fn (string $value): bool => $value !== ''));
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'tenant' => $this->tenant,
            'title' => $this->title,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'operation' => $this->operation,
        ];
    }

    public function toText(): string
    {
        $parts = array_filter([
            $this->title,
            $this->content,
            $this->metadata !== [] ? json_encode($this->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== '');

        return trim(implode("\n\n", $parts));
    }

    private static function normalizeOperation(string $operation): string
    {
        $normalized = strtolower(trim($operation));

        return in_array($normalized, ['delete', 'upsert'], true) ? $normalized : 'upsert';
    }
}

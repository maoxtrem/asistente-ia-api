<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class CanvasGenerationRequest
{
    /**
     * @param array<string, mixed> $canvas
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, mixed> $context
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $snapshot
     * @param array<int, array{role:string, content:string}> $history
     */
    public function __construct(
        public string $message,
        public string $tenant,
        public string $locale,
        public string $mode,
        public array $canvas,
        public array $elements,
        public array $context,
        public array $metadata,
        public array $vectorContext,
        public array $snapshot,
        public array $history,
    ) {
    }

    public static function fromArray(array $payload, string $tenantFallback = 'marketing'): self
    {
        return new self(
            message: trim((string) ($payload['message'] ?? '')),
            tenant: trim((string) ($payload['tenant'] ?? $tenantFallback)),
            locale: self::normalizeLocale($payload['locale'] ?? 'es'),
            mode: trim((string) ($payload['mode'] ?? 'generate')),
            canvas: is_array($payload['canvas'] ?? null) ? $payload['canvas'] : [],
            elements: is_array($payload['elements'] ?? null) ? $payload['elements'] : [],
            context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            vectorContext: self::normalizeVectorContext($payload),
            snapshot: self::normalizeSnapshot($payload),
            history: self::normalizeHistory($payload['history'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'tenant' => $this->tenant,
            'locale' => $this->locale,
            'mode' => $this->mode,
            'canvas' => $this->canvas,
            'elements' => $this->elements,
            'context' => $this->context,
            'metadata' => $this->metadata,
            'vector_context' => $this->vectorContext,
            'snapshot' => $this->snapshot,
            'history' => $this->history,
        ];
    }

    private static function normalizeLocale(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));

        return $normalized !== '' ? str_replace('_', '-', $normalized) : 'es';
    }

    /**
     * @return array<int, array{role:string, content:string}>
     */
    private static function normalizeHistory(mixed $history): array
    {
        if (!is_array($history)) {
            return [];
        }

        return array_values(array_map(static function (array $item): array {
            return [
                'role' => (string) ($item['role'] ?? ''),
                'content' => (string) ($item['content'] ?? ''),
            ];
        }, $history));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizeSnapshot(array $payload): array
    {
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $snapshot = $payload['snapshot'] ?? ($context['snapshot'] ?? []);

        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizeVectorContext(array $payload): array
    {
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [];
        $candidate = $payload['vector_context']
            ?? $payload['vectorContext']
            ?? $payload['vectoriales']
            ?? $payload['vectors']
            ?? ($context['vector_context'] ?? null)
            ?? ($context['vectorContext'] ?? null)
            ?? ($snapshot['vector_context'] ?? null)
            ?? ($snapshot['vectorContext'] ?? null);

        return is_array($candidate) ? $candidate : [];
    }
}

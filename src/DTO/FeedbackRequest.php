<?php

declare(strict_types=1);

namespace App\DTO;

final class FeedbackRequest
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $clientKey,
        public readonly string $tenant,
        public readonly string $locale,
        public readonly bool $helpful,
        public readonly string $question,
        public readonly string $answer,
        public readonly array $context = [],
        public readonly array $metadata = [],
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            conversationId: self::nullableString($payload['conversation_id'] ?? null),
            clientKey: trim((string) ($payload['client_key'] ?? '')),
            tenant: trim((string) ($payload['tenant'] ?? '')),
            locale: self::normalizeLocale($payload['locale'] ?? null),
            helpful: self::normalizeBool($payload['helpful'] ?? false),
            question: trim((string) ($payload['question'] ?? '')),
            answer: trim((string) ($payload['answer'] ?? '')),
            context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeLocale(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));

        return str_replace('_', '-', $normalized);
    }

    private static function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
    }
}

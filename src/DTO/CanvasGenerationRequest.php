<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class CanvasGenerationRequest
{
    public function __construct(
        public string $message,
        public string $tenant,
        public string $usuario,
        public string $locale,
        public array $metadata = [],
    ) {
    }

    public static function fromArray(array $payload, string $tenantFallback = 'marketing'): self
    {
        $message = trim((string) ($payload['message'] ?? $payload['question'] ?? ''));

        return new self(
            message: $message,
            tenant: trim((string) ($payload['tenant'] ?? $tenantFallback)),
            usuario: trim((string) ($payload['usuario'] ?? '')),
            locale: self::normalizeLocale($payload['locale'] ?? 'es'),
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
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
            'usuario' => $this->usuario,
            'locale' => $this->locale,
            'metadata' => $this->metadata,
        ];
    }

    private static function normalizeLocale(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));

        return $normalized !== '' ? str_replace('_', '-', $normalized) : 'es';
    }
}

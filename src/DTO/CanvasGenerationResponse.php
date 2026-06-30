<?php

declare(strict_types=1);

namespace App\DTO;

final class CanvasGenerationResponse
{
    /**
     * @param array<int, mixed> $actions
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?array $design,
        public readonly array $actions,
        public readonly ?string $imageUrl,
        public readonly ?string $imageKey,
        public readonly array $raw,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'imageUrl' => $this->imageUrl,
            'imageKey' => $this->imageKey,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class CanvasPromptInput
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $canvas
     * @param array<int, array<string, mixed>> $elements
     * @param array<int, array{role:string, content:string}> $history
     * @param array<string, mixed> $vectorContext
     * @param array<string, mixed> $qdrantHealth
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public string $message,
        public array $context,
        public string $tenant,
        public string $locale,
        public string $mode,
        public array $canvas,
        public array $elements,
        public array $history,
        public array $vectorContext,
        public array $qdrantHealth,
        public array $metadata,
        public array $snapshot,
        public string $extraInstruction = '',
    ) {
    }
}

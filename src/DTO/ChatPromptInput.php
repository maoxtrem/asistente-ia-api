<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ChatPromptInput
{
    public function __construct(
        public string $message,
        public array $context,
        public string $tenant,
        public string $locale,
        public array $history,
        public array $vectorContext,
        public array $qdrantHealth,
        public string $extraInstruction = '',
    ) {
    }
}

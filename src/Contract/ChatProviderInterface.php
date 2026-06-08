<?php

declare(strict_types=1);

namespace App\Contract;

interface ChatProviderInterface
{
    /**
     * @return array{content:string, raw: array<string, mixed>}
     */
    public function chat(
        string $message,
        array $context,
        string $tenant,
        string $locale,
        array $history,
        array $vectorContext,
        array $qdrantHealth,
        string $extraInstruction = ''
    ): array;
}

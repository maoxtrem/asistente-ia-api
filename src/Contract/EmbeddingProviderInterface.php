<?php

declare(strict_types=1);

namespace App\Contract;

interface EmbeddingProviderInterface
{
    /**
     * @return float[]
     */
    public function embed(string $text): array;
}

<?php

declare(strict_types=1);

namespace App\Contract;

interface EmbeddingProviderAdapterInterface extends EmbeddingProviderInterface
{
    public function supports(string $providerKind): bool;
}

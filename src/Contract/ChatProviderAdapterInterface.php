<?php

declare(strict_types=1);

namespace App\Contract;

interface ChatProviderAdapterInterface extends ChatProviderInterface
{
    public function supports(string $providerKind): bool;
}

<?php

declare(strict_types=1);

namespace App\Service\Ai\Embedding;

use App\Contract\EmbeddingProviderAdapterInterface;
use App\Contract\EmbeddingProviderInterface;
use RuntimeException;

final class EmbeddingProviderSelector implements EmbeddingProviderInterface
{
    /**
     * @param iterable<EmbeddingProviderAdapterInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly string $providerKind,
    ) {
    }

    /**
     * @return float[]
     */
    public function embed(string $text): array
    {
        $provider = $this->resolveProvider();

        return $provider->embed($text);
    }

    private function resolveProvider(): EmbeddingProviderAdapterInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($this->providerKind)) {
                return $provider;
            }
        }

        throw new RuntimeException(sprintf('No hay un adaptador de embeddings para el proveedor "%s".', $this->providerKind));
    }
}

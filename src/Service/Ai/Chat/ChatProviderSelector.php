<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

use App\Contract\ChatProviderAdapterInterface;
use App\Contract\ChatProviderInterface;
use RuntimeException;

final class ChatProviderSelector implements ChatProviderInterface
{
    /**
     * @param iterable<ChatProviderAdapterInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly string $providerKind,
    ) {
    }

    public function chat(string $message, array $context, string $tenant, string $locale, array $history, array $vectorContext, array $qdrantHealth, string $extraInstruction = '', ?string $systemPrompt = null, ?string $userPrompt = null): array
    {
        $provider = $this->resolveProvider();

        return $provider->chat($message, $context, $tenant, $locale, $history, $vectorContext, $qdrantHealth, $extraInstruction, $systemPrompt, $userPrompt);
    }

    private function resolveProvider(): ChatProviderAdapterInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($this->providerKind)) {
                return $provider;
            }
        }

        throw new RuntimeException(sprintf('No hay un adaptador de chat para el proveedor "%s".', $this->providerKind));
    }
}

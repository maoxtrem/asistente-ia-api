<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

use App\Contract\ChatProviderAdapterInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LocalJsonChatProvider implements ChatProviderAdapterInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ChatPromptBuilder $promptBuilder,
        private readonly string $providerUrl,
        private readonly string $chatModel,
        private readonly float $timeout,
    ) {
    }

    public function supports(string $providerKind): bool
    {
        return $this->normalizeProviderKind($providerKind) === 'local_json';
    }

    public function chat(string $message, array $context, string $tenant, string $locale, array $history, array $vectorContext, array $qdrantHealth, string $extraInstruction = ''): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->providerUrl, '/') . '/api/chat', [
                'json' => [
                    'model' => $this->chatModel,
                    'stream' => false,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->promptBuilder->buildSystemPrompt($locale),
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->promptBuilder->buildUserPrompt($message, $context, $tenant, $locale, $history, $vectorContext, $qdrantHealth, $extraInstruction),
                        ],
                    ],
                ],
                'timeout' => $this->timeout,
                'max_connect_duration' => 5,
            ]);

            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible consultar el servicio de chat: %s', $exception->getMessage()), 0, $exception);
        }

        $this->throwIfProviderError($payload);
        $content = trim((string) ($payload['message']['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('El servicio de chat no devolvio contenido util.');
        }

        return [
            'content' => $content,
            'raw' => $payload,
        ];
    }

    private function normalizeProviderKind(string $providerKind): string
    {
        $normalized = strtolower(trim($providerKind));

        return match ($normalized) {
            'local', 'local_json', 'local-json', 'custom', 'custom_json', 'custom-json' => 'local_json',
            default => $normalized,
        };
    }

    private function throwIfProviderError(array $payload): void
    {
        $detailMessage = (string) ($payload['detail']['message'] ?? '');
        if ($detailMessage !== '') {
            throw new RuntimeException($detailMessage);
        }

        $errorMessage = (string) ($payload['error']['message'] ?? '');
        if ($errorMessage !== '') {
            throw new RuntimeException($errorMessage);
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message !== '' && !isset($payload['choices'])) {
            throw new RuntimeException($message);
        }
    }
}

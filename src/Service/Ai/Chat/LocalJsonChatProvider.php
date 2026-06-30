<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

use App\DTO\ChatPromptInput;
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

    public function chat(string $message, array $context, string $tenant, string $locale, array $history, array $vectorContext, array $qdrantHealth, string $extraInstruction = '', ?string $systemPrompt = null, ?string $userPrompt = null): array
    {
        try {
            $input = new ChatPromptInput(
                $message,
                $context,
                $tenant,
                $locale,
                $history,
                $vectorContext,
                $qdrantHealth,
                $extraInstruction,
            );
            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt ?? $this->promptBuilder->buildSystemPrompt(),
                ],
            ];

            if (trim((string) $userPrompt) !== '') {
                $messages[] = [
                    'role' => 'user',
                    'content' => $userPrompt ?? $this->promptBuilder->buildUserPrompt($input),
                ];
            }

            $response = $this->httpClient->request('POST', rtrim($this->providerUrl, '/') . '/api/chat', [
                'json' => [
                    'model' => $this->chatModel,
                    'stream' => false,
                    'messages' => $messages,
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
        $detailMessage = $this->extractNestedMessage($payload['detail'] ?? null);
        if ($detailMessage !== '') {
            throw new RuntimeException($detailMessage);
        }

        $errorMessage = $this->extractNestedMessage($payload['error'] ?? null);
        if ($errorMessage !== '') {
            throw new RuntimeException($errorMessage);
        }

        $message = trim($this->normalizeScalarMessage($payload['message'] ?? null));
        if ($message !== '' && !isset($payload['choices'])) {
            throw new RuntimeException($message);
        }
    }

    private function extractNestedMessage(mixed $value): string
    {
        if (is_array($value)) {
            $nestedMessage = $value['message'] ?? '';
            if (is_scalar($nestedMessage) || $nestedMessage === null) {
                return trim((string) $nestedMessage);
            }

            return '';
        }

        return $this->normalizeScalarMessage($value);
    }

    private function normalizeScalarMessage(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        return '';
    }
}

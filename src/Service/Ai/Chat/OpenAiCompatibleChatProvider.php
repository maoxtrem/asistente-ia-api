<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

use App\DTO\ChatPromptInput;
use App\Contract\ChatProviderAdapterInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiCompatibleChatProvider implements ChatProviderAdapterInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ChatPromptBuilder $promptBuilder,
        private readonly string $providerUrl,
        private readonly string $chatModel,
        private readonly float $timeout,
        private readonly string $apiKey,
    ) {
    }

    public function supports(string $providerKind): bool
    {
        return $this->normalizeProviderKind($providerKind) === 'openai_compatible';
    }

    public function chat(string $message, array $context, string $tenant, string $locale, array $history, array $vectorContext, array $qdrantHealth, string $extraInstruction = '', ?string $systemPrompt = null, ?string $userPrompt = null): array
    {
        try {
            $input = new ChatPromptInput($message, $context, $tenant, $locale, $history, $vectorContext, $qdrantHealth, $extraInstruction);
            $response = $this->httpClient->request('POST', $this->buildOpenAiCompatibleEndpoint('/chat/completions'), [
                'json' => [
                    'model' => $this->chatModel,
                    'stream' => false,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt ?? $this->promptBuilder->buildSystemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt ?? $this->promptBuilder->buildUserPrompt($input),
                        ],
                    ],
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->requireApiKey($this->apiKey),
                ],
                'timeout' => $this->timeout,
                'max_connect_duration' => 5,
            ]);

            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible consultar el servicio de chat: %s', $exception->getMessage()), 0, $exception);
        }

        $this->throwIfProviderError($payload);
        $content = trim($this->extractContent($payload));
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
            'openai', 'openai_compatible', 'openai-compatible', 'cerebras' => 'openai_compatible',
            default => $normalized,
        };
    }

    private function requireApiKey(string $apiKey): string
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new RuntimeException('CHAT_PROVIDER_KIND=openai_compatible requiere CHAT_API_KEY.');
        }

        return $apiKey;
    }

    private function extractContent(array $payload): string
    {
        $content = (string) ($payload['choices'][0]['message']['content'] ?? '');
        if ($content !== '') {
            return $content;
        }

        return (string) ($payload['message']['content'] ?? '');
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

    private function buildOpenAiCompatibleEndpoint(string $path): string
    {
        $baseUrl = rtrim($this->providerUrl, '/');
        $normalizedPath = '/' . ltrim($path, '/');
        $parsedPath = (string) (parse_url($baseUrl, PHP_URL_PATH) ?? '');

        if ($parsedPath !== '' && str_ends_with($parsedPath, '/v1')) {
            return $baseUrl . $normalizedPath;
        }

        return $baseUrl . '/v1' . $normalizedPath;
    }
}

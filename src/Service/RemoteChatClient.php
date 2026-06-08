<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\ChatProviderInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RemoteChatClient implements ChatProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $providerUrl,
        private readonly string $chatModel,
        private readonly float $timeout,
    ) {
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
                            'content' => $this->buildSystemPrompt($locale),
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->buildUserPrompt($message, $context, $tenant, $locale, $history, $vectorContext, $qdrantHealth, $extraInstruction),
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

        $content = trim((string) ($payload['message']['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('El servicio de chat no devolvio contenido util.');
        }

        return [
            'content' => $content,
            'raw' => $payload,
        ];
    }

    private function buildSystemPrompt(string $locale): string
    {
        return <<<'PROMPT'
You are an assistant for a business system.
Use the available retrieved context to answer.
If the information is not enough, be honest and ask for more details.
Do not invent routes, IDs, or internal data.
Reply in plain text, concise, clear, and useful.
Respond in the same language as the user's latest message.
If the user's language is unclear, use the application locale provided in the context as fallback.
PROMPT;
    }

    private function buildUserPrompt(string $message, array $context, string $tenant, string $locale, array $history, array $vectorContext, array $qdrantHealth, string $extraInstruction): string
    {
        $contextPath = trim((string) ($context['pathname'] ?? ''));

        return json_encode([
            'mensaje' => $message,
            'historial' => array_values(array_map(static function (array $item): array {
                return [
                    'role' => (string) ($item['role'] ?? ''),
                    'content' => (string) ($item['content'] ?? ''),
                ];
            }, $history)),
            'contexto' => [
                'pathname' => $contextPath,
                'tenant' => $tenant,
                'locale' => $locale,
                'application_locale' => (string) ($context['application_locale'] ?? $locale),
                'message_locale' => (string) ($context['message_locale'] ?? 'unknown'),
                'response_locale' => (string) ($context['response_locale'] ?? $locale),
                'qdrant_activo' => (bool) ($qdrantHealth['ok'] ?? false),
                'coleccion_vectorial' => (string) ($vectorContext['collection'] ?? ''),
                'recuperacion_ok' => (bool) ($vectorContext['ok'] ?? false),
            ],
            'contexto_vectorial' => array_values(array_map(static function (array $document): array {
                return [
                    'id' => $document['id'] ?? '',
                    'score' => $document['score'] ?? 0.0,
                    'title' => $document['title'] ?? '',
                    'content' => $document['content'] ?? '',
                    'source' => $document['source'] ?? '',
                    'type' => $document['type'] ?? '',
                    'tenant' => $document['tenant'] ?? '',
                    'metadata' => is_array($document['metadata'] ?? null) ? $document['metadata'] : [],
                ];
            }, is_array($vectorContext['matches'] ?? null) ? $vectorContext['matches'] : [])),
            'recuperacion_error' => isset($vectorContext['error']) ? (string) $vectorContext['error'] : '',
            'instruccion' => trim(
                'Respond in the same language as the user’s latest message.'
                . ' If message_locale is unknown, use application_locale as fallback.'
                . ' Use only the recovered information.'
                . ' If there is not enough information, say so clearly and ask for more context.'
                . ($extraInstruction !== '' ? ' ' . $extraInstruction : '')
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $message;
    }
}

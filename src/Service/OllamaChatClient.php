<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\ChatProviderInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OllamaChatClient implements ChatProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $ollamaUrl,
        private readonly string $chatModel,
        private readonly float $timeout,
    ) {
    }

    public function chat(string $message, array $context, string $tenant, array $vectorContext, array $qdrantHealth, string $extraInstruction = ''): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->ollamaUrl, '/') . '/api/chat', [
                'json' => [
                    'model' => $this->chatModel,
                    'stream' => false,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->buildSystemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->buildUserPrompt($message, $context, $tenant, $vectorContext, $qdrantHealth, $extraInstruction),
                        ],
                    ],
                ],
                'timeout' => $this->timeout,
                'max_connect_duration' => 5,
            ]);

            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible consultar Ollama: %s', $exception->getMessage()), 0, $exception);
        }

        $content = trim((string) ($payload['message']['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('Ollama no devolvio contenido util.');
        }

        return [
            'content' => $content,
            'raw' => $payload,
        ];
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Eres un asistente en español para un sistema empresarial.
Usa la informacion disponible en el contexto recuperado para responder.
Si la informacion no alcanza, dilo con honestidad y pide mas detalles.
No inventes rutas, IDs ni datos internos.
Responde en texto plano, breve, claro y util.
PROMPT;
    }

    private function buildUserPrompt(string $message, array $context, string $tenant, array $vectorContext, array $qdrantHealth, string $extraInstruction): string
    {
        $contextPath = trim((string) ($context['pathname'] ?? ''));

        return json_encode([
            'mensaje' => $message,
            'contexto' => [
                'pathname' => $contextPath,
                'tenant' => $tenant,
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
                'Responde solo con base en la informacion recuperada.'
                . ' Si no hay suficiente informacion, dilo claramente y pide mas contexto.'
                . ($extraInstruction !== '' ? ' ' . $extraInstruction : '')
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $message;
    }
}

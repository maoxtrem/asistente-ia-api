<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

use App\DTO\ChatPromptInput;
use JsonException;
use RuntimeException;

final class ChatPromptBuilder
{
    public function __construct(
        private readonly int $maxHistoryItems = 4,
        private readonly int $maxVectorMatches = 2,
    ) {
    }

    public function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an assistant for a business system.
Use the available context to answer naturally.
If the information is not enough, be honest and ask for one short clarification.
Do not invent routes, IDs, or internal data.
Reply in plain text, concise, clear, and useful.
Respond in the same language as the user's latest message.
If the user's language is unclear, use the application locale provided in the context as fallback.
PROMPT;
    }

    public function buildUserPrompt(ChatPromptInput $input): string
    {
        return $this->encodeJson([
            'mensaje' => $input->message,
            'historial' => $this->normalizeHistory($input->history),
            'contexto' => $this->buildContextPayload($input->context, $input->tenant, $input->locale, $input->vectorContext, $input->qdrantHealth),
            'contexto_vectorial' => $this->normalizeVectorMatches($input->vectorContext),
            'recuperacion_error' => isset($input->vectorContext['error']) ? (string) $input->vectorContext['error'] : '',
            'instruccion' => $this->buildInstruction($input->extraInstruction),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array{role:string, content:string}>
     */
    private function normalizeHistory(array $history): array
    {
        return array_values(array_map(static function (array $item): array {
            return [
                'role' => (string) ($item['role'] ?? ''),
                'content' => (string) ($item['content'] ?? ''),
            ];
        }, array_slice($history, -$this->maxHistoryItems)));
    }

    /**
     * @param array<string, mixed> $vectorContext
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVectorMatches(array $vectorContext): array
    {
        $matches = is_array($vectorContext['matches'] ?? null) ? $vectorContext['matches'] : [];

        return array_values(array_map(static function (array $document): array {
            return [
                'id' => $document['id'] ?? '',
                'score' => $document['score'] ?? 0.0,
                'title' => $document['title'] ?? '',
                'content' => $document['content'] ?? '',
                'source' => $document['source'] ?? '',
                'type' => $document['type'] ?? '',
                'tenant' => $document['tenant'] ?? '',
            ];
        }, array_slice($matches, 0, $this->maxVectorMatches)));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $vectorContext
     * @param array<string, mixed> $qdrantHealth
     * @return array<string, mixed>
     */
    private function buildContextPayload(array $context, string $tenant, string $locale, array $vectorContext, array $qdrantHealth): array
    {
        return [
            'pathname' => trim((string) ($context['pathname'] ?? '')),
            'tenant' => $tenant,
            'locale' => $locale,
            'application_locale' => (string) ($context['application_locale'] ?? $locale),
            'message_locale' => (string) ($context['message_locale'] ?? 'unknown'),
            'response_locale' => (string) ($context['response_locale'] ?? $locale),
            'qdrant_activo' => (bool) ($qdrantHealth['ok'] ?? false),
            'coleccion_vectorial' => (string) ($vectorContext['collection'] ?? ''),
            'recuperacion_ok' => (bool) ($vectorContext['ok'] ?? false),
        ];
    }

    private function buildInstruction(string $extraInstruction): string
    {
        return trim(
            'Respond in the same language as the user’s latest message.'
            . ' If message_locale is unknown, use application_locale as fallback.'
            . ' Use the context as support, but keep the answer natural and direct.'
            . ' If there is not enough information, ask for one short clarification without mentioning internal sources or retrieval.'
            . ($extraInstruction !== '' ? ' ' . $extraInstruction : '')
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('No fue posible serializar el prompt del chat a JSON.', 0, $exception);
        }
    }
}

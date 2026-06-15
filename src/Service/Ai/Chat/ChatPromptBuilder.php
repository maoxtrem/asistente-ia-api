<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

final class ChatPromptBuilder
{
    public function buildSystemPrompt(string $locale): string
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

    public function buildUserPrompt(string $message, array $context, string $tenant, string $locale, array $history, array $vectorContext, array $qdrantHealth, string $extraInstruction): string
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
                . ' Use the context as support, but keep the answer natural and direct.'
                . ' If there is not enough information, ask for one short clarification without mentioning internal sources or retrieval.'
                . ($extraInstruction !== '' ? ' ' . $extraInstruction : '')
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $message;
    }

    public function buildFeedbackAnalysisSystemPrompt(string $locale): string
    {
        return <<<'PROMPT'
You are a knowledge curator for a business assistant.
Analyze a user question, the assistant answer, and recent conversation context.
Your job is to decide whether the exchange should become reusable knowledge.
Return only valid JSON, without markdown, code fences, or extra commentary.
Be strict. Prefer rejecting vague, overly specific, duplicated, sensitive, or low-signal content.
When the exchange is reusable, produce a concise title, a clean summary, a natural content text, a language code, and a confidence score between 0 and 1.
When the exchange is not reusable, set should_index to false and explain the reason briefly.
Do not invent facts.
Do not mention internal implementation details.
PROMPT;
    }

    public function buildFeedbackAnalysisUserPrompt(
        string $question,
        string $answer,
        array $history,
        array $context,
        string $tenant,
        string $locale
    ): string {
        $contextPath = trim((string) ($context['pathname'] ?? ''));

        return json_encode([
            'feedback' => [
                'question' => $question,
                'answer' => $answer,
            ],
            'historial_reciente' => array_values(array_map(static function (array $item): array {
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
            ],
            'salida_esperada' => [
                'should_index' => true,
                'title' => 'Short reusable title',
                'summary' => 'Concise summary of the reusable knowledge',
                'content' => 'Clean reusable knowledge text',
                'language' => 'en',
                'confidence' => 0.0,
                'keywords' => ['keyword1', 'keyword2'],
                'duplicate_of' => null,
                'reason' => 'Why it should or should not be indexed',
            ],
            'instrucciones' => [
                'If the exchange is too specific to a single case, set should_index to false.',
                'If the answer depends on temporary state, permissions, or personal data, set should_index to false.',
                'If the exchange is reusable, produce a clean knowledge item that could answer similar questions in the future.',
                'Keep the language aligned with the answer and the user conversation.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: json_encode([
            'feedback' => compact('question', 'answer'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

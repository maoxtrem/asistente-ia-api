<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

use App\DTO\FeedbackAnalysisPromptInput;
use JsonException;
use RuntimeException;

final class FeedbackAnalysisPromptBuilder
{
    private const MAX_HISTORY_ITEMS = 4;

    public function buildSystemPrompt(): string
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

    public function buildUserPrompt(FeedbackAnalysisPromptInput $input): string
    {
        return $this->encodeJson([
            'task' => 'knowledge_extraction',
            'tenant' => $input->tenant,
            'locale' => $input->locale,
            'question' => $input->question,
            'answer' => $input->answer,
            'context' => $this->buildContextPayload($input->context, $input->tenant, $input->locale),
            'recent_history' => $this->normalizeHistory($input->history),
            'output_requirements' => [
                'Return only a JSON object.',
                'Use should_index=true only if the exchange is reusable and broadly useful.',
                'If the exchange is too specific, temporary, sensitive, or noisy, set should_index=false.',
                'Keep the summary concise and neutral.',
                'Prefer general reusable knowledge, not raw conversation.',
            ],
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
        }, array_slice($history, -self::MAX_HISTORY_ITEMS)));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildContextPayload(array $context, string $tenant, string $locale): array
    {
        return [
            'pathname' => trim((string) ($context['pathname'] ?? '')),
            'tenant' => $tenant,
            'locale' => $locale,
            'application_locale' => (string) ($context['application_locale'] ?? $locale),
            'message_locale' => (string) ($context['message_locale'] ?? 'unknown'),
            'response_locale' => (string) ($context['response_locale'] ?? $locale),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('No fue posible serializar el prompt de feedback a JSON.', 0, $exception);
        }
    }
}

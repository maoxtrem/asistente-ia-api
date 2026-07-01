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
Eres un curador de conocimiento para un asistente de negocio.
Analiza una pregunta del usuario, la respuesta del asistente y el contexto reciente de la conversación.
Tu tarea es decidir si el intercambio debe convertirse en conocimiento reutilizable.
Devuelve solo JSON válido, sin markdown, sin bloques de código y sin comentarios extra.
Sé estricto. Prefiere rechazar contenido ambiguo, demasiado específico, duplicado, sensible o con poco valor.
Cuando el intercambio sea reutilizable, produce un título breve, un resumen limpio, un texto de contenido natural, un código de idioma y un puntaje de confianza entre 0 y 1.
Cuando el intercambio no sea reutilizable, establece should_index en false y explica brevemente el motivo.
No inventes hechos.
No menciones detalles internos de implementación.
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
                'Devuelve solo un objeto JSON.',
                'Usa should_index=true solo si el intercambio es reutilizable y útil de forma general.',
                'Si el intercambio es demasiado específico, temporal, sensible o ruidoso, establece should_index=false.',
                'Mantén el resumen breve y neutral.',
                'Prefiere conocimiento general reutilizable, no la conversación en bruto.',
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

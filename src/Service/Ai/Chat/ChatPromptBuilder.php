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
Eres un asistente para un sistema empresarial.
IMPORTANTE: el idioma de respuesta lo determina response_locale del contexto.
Si response_locale es en, responde en inglés aunque el historial esté en español.
Si response_locale es es, responde en español aunque el historial esté en inglés.
Si el idioma no está claro, usa el locale de la aplicación indicado en el contexto como respaldo.
Si contexto.search_plan existe, úsalo como guía prioritaria para interpretar la consulta.
Usa el contexto disponible para responder de forma natural y precisa.
Si la información no es suficiente, sé honesto y pide una sola aclaración breve.
No inventes rutas, identificadores ni datos internos.
Responde en texto plano, de forma concisa, clara y útil.
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

    public function buildSearchPlanSystemPrompt(): string
    {
        return <<<'PROMPT'
Eres un planificador de recuperación para un asistente empresarial.
Tu tarea es decidir cómo consultar un sistema RAG con documentos principalmente en español.
Devuelve solo JSON válido, sin markdown, sin bloques de código y sin comentarios extra.
El campo search_query debe ser una consulta corta y optimizada para búsqueda semántica.
Si el usuario escribe en inglés, traduce search_query al español neutro para mejorar la recuperación.
El campo response_locale debe conservar el idioma con el que debe responderse al usuario.
El campo search_locale debe indicar el idioma de la consulta de búsqueda.
No inventes hechos ni datos.
PROMPT;
    }

    public function buildSearchPlanUserPrompt(string $message, array $context, string $tenant, string $locale, array $history): string
    {
        return $this->encodeJson([
            'task' => 'rag_search_planning',
            'tenant' => $tenant,
            'locale' => $locale,
            'message' => $message,
            'context' => $this->buildSearchPlanContextPayload($context, $tenant, $locale),
            'history' => $this->normalizeHistory($history),
            'output' => [
                'should_search' => true,
                'search_query' => 'Consulta breve para buscar en la base de conocimiento',
                'search_locale' => 'es',
                'response_locale' => 'Idioma final de respuesta',
                'reason' => 'Explicación breve.',
            ],
            'rules' => [
                'Prioriza la recuperación en español.',
                'Si el mensaje viene en inglés, traduce la consulta al español para buscar.',
                'Si el mensaje es ambiguo, devuelve una query más general pero útil.',
                'Devuelve JSON sin markdown ni texto adicional.',
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
            'search_plan' => is_array($context['search_plan'] ?? null) ? $context['search_plan'] : [],
            'qdrant_activo' => (bool) ($qdrantHealth['ok'] ?? false),
            'coleccion_vectorial' => (string) ($vectorContext['collection'] ?? ''),
            'recuperacion_ok' => (bool) ($vectorContext['ok'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildSearchPlanContextPayload(array $context, string $tenant, string $locale): array
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

    private function buildInstruction(string $extraInstruction): string
    {
        return trim(
            'Responde en el idioma indicado por response_locale.'
            . ' Si response_locale es desconocido, usa application_locale como respaldo.'
            . ' No copies el idioma del historial si contradice response_locale.'
            . ' Usa el contexto como apoyo, pero mantén la respuesta natural, directa y breve.'
            . ' Si no hay suficiente información, pide una sola aclaración concreta sin mencionar fuentes internas ni procesos de recuperación.'
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

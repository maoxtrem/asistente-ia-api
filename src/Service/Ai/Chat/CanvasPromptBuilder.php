<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

use App\DTO\CanvasPromptInput;
use JsonException;
use RuntimeException;

final class CanvasPromptBuilder
{
    private const MAX_HISTORY_ITEMS = 4;
    private const MAX_VECTOR_MATCHES = 2;

    public function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the operational canvas assistant for a business system.
Use only the canvas channel context and the provided vector matches from the canvas knowledge base.
Do not mix this task with the informational assistant channel.
Return only valid JSON, without markdown, code fences, or extra commentary.
When the request is actionable, return a design export that matches the exact save format already used by the editor and the database.
The design object must mirror the database/editor row shape, including the keys name_usar_medida, backgroundType, borderStyle, canvasSize, nombreCampana, primaryColor, secondaryColor, designElements, backgroundImage, and fotoMostrar.
Do not add wrapper objects or alternate schema variants inside design.
Keep designElements strictly compatible with the saved element structure so the frontend can mount it directly without inventing fields.
If the user asks to create or improve an element, infer sensible defaults from the current snapshot instead of asking for size or position unless that information is truly missing.
Use the existing vector context as grounding for canvas size, recurring elements, colors, layout intent, and object placement.
If you make changes, reflect them in the design export and describe them in actions.
When there is not enough information, keep the response honest and request one short clarification.
Do not invent internal routes, identifiers, extra metadata, or operational data.
PROMPT;
    }

    public function buildUserPrompt(CanvasPromptInput $input): string
    {
        return $this->encodeJson([
            'task' => 'canvas_operation',
            'tenant' => $input->tenant,
            'locale' => $input->locale,
            'mode' => $input->mode,
            'message' => $input->message,
            'canvas' => $input->canvas,
            'elements' => $input->elements,
            'snapshot' => $input->snapshot,
            'context' => $this->buildContextPayload($input->context, $input->tenant, $input->locale, $input->qdrantHealth),
            'recent_history' => $this->normalizeHistory($input->history),
            'vector_context' => $this->normalizeVectorMatches($input->vectorContext),
            'incoming_vector_context' => $this->normalizeIncomingVectorContext($input->incomingVectorContext),
            'output_requirements' => [
                'Return only a JSON object.',
                'Include ok, message, design, and actions keys.',
                'Preserve the same exact save-format structure from the snapshot so it can be mounted directly.',
                'Keep the design object aligned with the database row format: name_usar_medida, backgroundType, borderStyle, canvasSize, nombreCampana, primaryColor, secondaryColor, designElements, backgroundImage, and fotoMostrar.',
                'Do not introduce assistant-ia informational fields, alternative wrappers, or extra properties that are not part of the export format.',
                'When the request is about creating or improving content, use the current canvas snapshot to infer default size, position, and styling if they are not explicitly provided.',
                'Only ask for clarification when the snapshot does not provide enough information to make a reasonable change.',
                'Keep the design export actionable and concise.',
                'Use the canvas knowledge base only when it helps the current canvas task.',
            ],
            'instruction' => $this->buildInstruction($input->extraInstruction),
            'metadata' => $input->metadata,
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
     * @param array<string, mixed> $vectorContext
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVectorMatches(array $vectorContext): array
    {
        $matches = is_array($vectorContext['matches'] ?? null) ? $vectorContext['matches'] : [];

        return array_values(array_map(static function (array $document): array {
            return [
                'id' => self::normalizeString($document['id'] ?? ''),
                'score' => $document['score'] ?? 0.0,
                'title' => self::normalizeString($document['title'] ?? ''),
                'content' => self::normalizeString($document['content'] ?? ''),
                'source' => self::normalizeString($document['source'] ?? ''),
                'type' => self::normalizeString($document['type'] ?? ''),
                'tenant' => self::normalizeString($document['tenant'] ?? ''),
            ];
        }, array_slice($matches, 0, self::MAX_VECTOR_MATCHES)));
    }

    /**
     * @param array<string, mixed> $vectorContext
     * @return array<string, mixed>
     */
    private function normalizeIncomingVectorContext(array $vectorContext): array
    {
        if ($vectorContext === []) {
            return [];
        }

        $normalized = [];
        foreach ($vectorContext as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $qdrantHealth
     * @return array<string, mixed>
     */
    private function buildContextPayload(array $context, string $tenant, string $locale, array $qdrantHealth): array
    {
        return [
            'pathname' => self::normalizeString($context['pathname'] ?? ''),
            'tenant' => self::normalizeString($tenant),
            'locale' => self::normalizeString($locale),
            'application_locale' => self::normalizeString($context['application_locale'] ?? $locale),
            'message_locale' => self::normalizeString($context['message_locale'] ?? 'unknown'),
            'response_locale' => self::normalizeString($context['response_locale'] ?? $locale),
            'qdrant_activo' => (bool) ($qdrantHealth['ok'] ?? false),
        ];
    }

    private function buildInstruction(string $extraInstruction): string
    {
        return trim(
            'Respond as the canvas operational assistant.'
            . ' Prefer concrete, actionable canvas guidance.'
            . ' If there is not enough information, ask for one short clarification.'
            . ' Keep the response scoped to the canvas channel and do not use the informational assistant context.'
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
            throw new RuntimeException('No fue posible serializar el prompt de canvas a JSON.', 0, $exception);
        }
    }

    /**
     * @param mixed $value
     */
    private static function normalizeString(mixed $value): string
    {
        if (is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException) {
                return '';
            }
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

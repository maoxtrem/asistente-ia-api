<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

use App\Contract\ChatProviderInterface;
use JsonException;
use RuntimeException;

final class ChatToolPdfGenerationService
{
    public function __construct(
        private readonly ChatProviderInterface $chatProvider,
        private readonly ChatToolPdfPromptBuilder $promptBuilder,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<string, mixed> $context
     * @return array{
     *   status:string,
     *   mode:string,
     *   message:string,
     *   missing_fields:array<int, string>,
     *   html:string,
     *   json:array<string, mixed>,
     *   paper_size:string,
     *   orientation:string,
     *   raw:array<string, mixed>
     * }
     */
    public function processRequest(
        string $message,
        string $tenant,
        string $usuario,
        string $entorno,
        string $locale,
        bool $tool,
        array $history,
        array $context = []
    ): array {
        $response = $this->chatProvider->chat(
            message: $message,
            context: $context,
            tenant: $tenant,
            locale: $locale !== '' ? $locale : 'es',
            history: $history,
            vectorContext: ['ok' => true, 'skipped' => true, 'matches' => []],
            qdrantHealth: ['ok' => true, 'skipped' => true],
            extraInstruction: 'Return one JSON object only.',
            systemPrompt: $this->promptBuilder->buildUnifiedSystemPrompt(),
            userPrompt: $this->promptBuilder->buildUnifiedUserPrompt(
                $message,
                $tenant,
                $usuario,
                $entorno,
                $locale,
                $tool,
                $context
            )
        );

        $content = trim((string) ($response['content'] ?? ''));
        $decoded = $this->extractJson($content);

        if (!is_array($decoded)) {
            throw new RuntimeException('La IA no devolvio un JSON valido para chattoolpdf.');
        }

        $status = strtolower(trim((string) ($decoded['status'] ?? 'ready')));
        $mode = strtolower(trim((string) ($decoded['mode'] ?? 'chat')));
        $messageText = trim((string) ($decoded['message'] ?? ''));
        $missingFields = $this->normalizeStringList($decoded['missing_fields'] ?? []);
        $html = (string) ($decoded['html'] ?? '');
        $json = is_array($decoded['json'] ?? null) ? $decoded['json'] : [];
        $paperSize = strtoupper(trim((string) ($decoded['paper_size'] ?? 'A4')));
        $orientation = strtolower(trim((string) ($decoded['orientation'] ?? 'portrait')));

        if (!in_array($mode, ['chat', 'document'], true)) {
            throw new RuntimeException('La IA devolvio un mode invalido para chattoolpdf.');
        }

        if ($status === 'needs_clarification') {
            return [
                'status' => 'needs_clarification',
                'mode' => $mode,
                'message' => $messageText !== '' ? $messageText : 'Necesito un poco mas de informacion para continuar.',
                'missing_fields' => $missingFields,
                'html' => '',
                'json' => [],
                'paper_size' => $paperSize !== '' ? $paperSize : 'A4',
                'orientation' => $orientation !== '' ? $orientation : 'portrait',
                'raw' => $response,
            ];
        }

        if ($messageText === '') {
            throw new RuntimeException('La IA no devolvio un mensaje util para chattoolpdf.');
        }

        if ($mode === 'document' && $html === '') {
            throw new RuntimeException('La IA no devolvio html para generar el PDF.');
        }

        if ($paperSize === '') {
            $paperSize = 'A4';
        }

        if ($orientation === '') {
            $orientation = 'portrait';
        }

        return [
            'status' => 'ready',
            'mode' => $mode,
            'message' => $messageText,
            'missing_fields' => $missingFields,
            'html' => $html,
            'json' => $json,
            'paper_size' => $paperSize,
            'orientation' => $orientation,
            'raw' => $response,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<string, mixed> $context
     * @return array{
     *   status:string,
     *   message:string,
     *   raw:array<string, mixed>
     * }
     */
    public function answerQuestion(
        string $message,
        string $tenant,
        string $usuario,
        string $entorno,
        string $locale,
        array $history,
        array $context = []
    ): array {
        $response = $this->chatProvider->chat(
            message: $message,
            context: $context,
            tenant: $tenant,
            locale: $locale !== '' ? $locale : 'es',
            history: $history,
            vectorContext: ['ok' => true, 'skipped' => true, 'matches' => []],
            qdrantHealth: ['ok' => true, 'skipped' => true],
            extraInstruction: 'Responde de forma natural, clara y directa.',
            systemPrompt: $this->promptBuilder->buildQuestionSystemPrompt(),
            userPrompt: $this->promptBuilder->buildQuestionUserPrompt(
                $message,
                $tenant,
                $usuario,
                $entorno,
                $locale,
                $context
            )
        );

        $content = trim((string) ($response['content'] ?? ''));

        if ($content === '') {
            throw new RuntimeException('La IA no devolvio una respuesta util.');
        }

        return [
            'status' => 'ready',
            'message' => $content,
            'raw' => $response,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<string, mixed> $context
     * @return array{
     *   status:string,
     *   message:string,
     *   missing_fields:array<int, string>,
     *   html:string,
     *   json:array<string, mixed>,
     *   paper_size:string,
     *   orientation:string,
     *   raw:array<string, mixed>
     * }
     */
    public function generateDocument(
        string $message,
        string $tenant,
        string $usuario,
        string $entorno,
        string $locale,
        array $history,
        array $context = []
    ): array {
        $response = $this->chatProvider->chat(
            message: $message,
            context: $context,
            tenant: $tenant,
            locale: $locale !== '' ? $locale : 'es',
            history: $history,
            vectorContext: ['ok' => true, 'skipped' => true, 'matches' => []],
            qdrantHealth: ['ok' => true, 'skipped' => true],
            extraInstruction: 'Devuelve solo JSON valido.',
            systemPrompt: $this->promptBuilder->buildPdfSystemPrompt(),
            userPrompt: $this->promptBuilder->buildPdfUserPrompt(
                $message,
                $tenant,
                $usuario,
                $entorno,
                $locale,
                $context
            )
        );

        $content = trim((string) ($response['content'] ?? ''));
        $decoded = $this->extractJson($content);

        if (!is_array($decoded)) {
            throw new RuntimeException('La IA no devolvio un JSON valido para chattoolpdf.');
        }

        $status = strtolower(trim((string) ($decoded['status'] ?? 'ready')));
        $messageText = trim((string) ($decoded['message'] ?? ''));
        $missingFields = $this->normalizeStringList($decoded['missing_fields'] ?? []);
        $html = (string) ($decoded['html'] ?? '');
        $json = is_array($decoded['json'] ?? null) ? $decoded['json'] : [];
        $paperSize = strtoupper(trim((string) ($decoded['paper_size'] ?? 'A4')));
        $orientation = strtolower(trim((string) ($decoded['orientation'] ?? 'portrait')));

        if ($status === 'needs_clarification') {
            return [
                'status' => 'needs_clarification',
                'message' => $messageText !== '' ? $messageText : 'Necesito un poco mas de informacion para construir el PDF.',
                'missing_fields' => $missingFields,
                'html' => '',
                'json' => [],
                'paper_size' => $paperSize !== '' ? $paperSize : 'A4',
                'orientation' => $orientation !== '' ? $orientation : 'portrait',
                'raw' => $response,
            ];
        }

        if ($html === '') {
            throw new RuntimeException('La IA no devolvio html para generar el PDF.');
        }

        if ($paperSize === '') {
            $paperSize = 'A4';
        }

        if ($orientation === '') {
            $orientation = 'portrait';
        }

        return [
            'status' => 'ready',
            'message' => $messageText,
            'missing_fields' => $missingFields,
            'html' => $html,
            'json' => $json,
            'paper_size' => $paperSize,
            'orientation' => $orientation,
            'raw' => $response,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($content, $start, $end - $start + 1);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}

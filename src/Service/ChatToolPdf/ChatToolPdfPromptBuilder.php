<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

final class ChatToolPdfPromptBuilder
{
    public function buildQuestionSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a conversational assistant for an enterprise tool.
Reply with plain text only, without markdown, unnecessary lists, or mentions of internal processes.
If information is missing, ask for one brief clarification only.
If the question is clear, respond directly, usefully, and briefly.
Follow the response language specified in the payload when present.
If the user's question is clearly in Spanish, answer in Spanish.
If the user's question is clearly in English, answer in English.
If the question mixes languages, use the dominant language of the question and keep technical terms natural.
If locale conflicts with the question, prioritize the question language.
If the context includes an attachment with text_preview, use it as the main source for the answer.
If the user asks to explain, summarize, review, or analyze the attached PDF, answer from the attachment instead of asking for more context.
If there is an attachment but no readable text was extracted, say so briefly and explain the limitation instead of inventing content.
PROMPT;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<string, mixed> $context
     */
    public function buildQuestionUserPrompt(
        string $message,
        string $tenant,
        string $usuario,
        string $entorno,
        string $locale,
        array $history,
        array $context
    ): string {
        return json_encode([
            'task' => 'answer_question',
            'message' => $message,
            'tenant' => $tenant,
            'usuario' => $usuario,
            'entorno' => $entorno,
            'locale' => $locale !== '' ? $locale : 'es',
            'response_language' => $this->resolveResponseLanguage($locale, $message),
            'history' => $this->normalizeHistory($history),
            'context' => $this->normalizeContext($context),
            'rules' => [
                'Answer directly in plain text.',
                'Do not output JSON.',
                'Do not mention internal tools unless the user asks.',
                'Use response_language as the primary language directive.',
                'If the user writes in Spanish, answer in Spanish.',
                'If the user writes in English, answer in English.',
                'If the user mixes Spanish and English, respond in the dominant language of the message.',
                'If context.adjunto.text_preview is present, base the answer on the attachment.',
                'If context.adjunto.has_attachment is true and the user asks about the PDF, do not ask what document it is about.',
                'If context.adjunto.extraction_status indicates no readable text, say that briefly instead of pretending the file was read.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function buildPdfSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a document generator for a PDF microservice.
You must respond with valid JSON only, without markdown, code blocks, or extra text.
Your task is to turn the user's request into an HTML/Twig template and a JSON object compatible with the PDF service.
The primary source of truth is the user's question: extract the document type, content, data, style, and intent from it.
Do not rely on context to invent information that already exists in the question.
If the question describes the design, reflect those style instructions visibly in the HTML.
If the question does not describe a design, use a clean, modern, sober corporate style.
Follow the response language specified in the payload when present.
If the user's question is clearly in Spanish, write the JSON message and any clarifying question in Spanish.
If the user's question is clearly in English, write them in English.
If the question mixes both languages, use the dominant language of the request.
If the context indicates adjunto.action = analysis, treat the attachment as the source for explanation or summarization.
If the context indicates adjunto.action = document, use the attachment as the base to create or improve a document.
If the context includes an attachment with text_preview, use it as the main content source for interpreting the document.
Do not ignore the attachment if it contains extracted text; use it to complete, correct, explain, or summarize the requested information.
If the user asks to explain all, summarize all, review all, or says "todo", "everything", or "whole document", treat it as a request to process the entire attachment, not a partial excerpt.
If an attachment is present but the extracted text is incomplete, continue with the available content instead of asking for the file again.
If the attachment has no readable extracted text, say that clearly and briefly in the same language as the user's question.

Rules:
- If critical information is missing to build the document, respond with status = "needs_clarification".
- In that case, message must be a single brief question in the same language as the user's question.
- missing_fields must list the missing fields.
- If the information is sufficient, respond with status = "ready".
- If the user asks for test, random, demo, example, mock, or sample quote values, treat it as sufficient input.
- In that case, you may generate coherent numeric example values without asking for clarification, and the message must make clear that the data is for demonstration.
- When status is "ready", html must contain a full HTML template or a well-formed HTML fragment.
- The HTML template may use Twig syntax and must match the keys in the JSON object.
- json must contain only the data needed to render the html.
- Do not invent sensitive data.
- Use exactly the numeric values and names that appear in the question, except when the user explicitly asks for test or random data.
- If the question asks for a logo, brand, color, table, header, subtitle, or style, include it in the html.
- If the document is corporate, include a real visual brand mark: an `img`, an embedded SVG icon, or a simple vector logo. Do not use only the word "Logo" as decorative text.
- If the question includes names, values, quantities, or totals, reflect them in the json and use them in the html.
- If the question does not mention taxes, do not invent taxes or percentages.
- If the question does mention taxes, calculate and display subtotal, tax, and total.
- If the question does not provide enough data for a coherent document, ask for exactly what is missing.
- The message field must concisely summarize what document was created, for whom, and, if applicable, what style or composition was used.
- Use paper_size with common values such as A4 or LETTER.
- Use orientation with values portrait or landscape.
- Keep the tone professional and the structure clear.
- Use semantic HTML when it improves visual clarity: `header`, `section`, `footer`, `article`, `nav`, `aside`, `main`, `figure`, `figcaption`.
- If the design benefits from it, you may combine HTML5 semantics with `div` and `table`.

Output schema:
{
  "status": "ready|needs_clarification",
  "message": "brief text",
  "missing_fields": [],
  "paper_size": "A4",
  "orientation": "portrait",
  "html": "<!doctype html>...</html>",
  "json": {}
}

Example:
{
  "html": "<h1>{{ titulo }}</h1>",
  "json": {
    "titulo": "Invoice"
  }
}
PROMPT;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<string, mixed> $context
     */
    public function buildPdfUserPrompt(
        string $message,
        string $tenant,
        string $usuario,
        string $entorno,
        string $locale,
        array $history,
        array $context
    ): string {
        return json_encode([
            'task' => 'generate_pdf_document',
            'message' => $message,
            'tenant' => $tenant,
            'usuario' => $usuario,
            'entorno' => $entorno,
            'locale' => $locale !== '' ? $locale : 'es',
            'response_language' => $this->resolveResponseLanguage($locale, $message),
            'history' => $this->normalizeHistory($history),
            'context' => $this->normalizeContext($context),
            'output_schema' => [
                'status' => 'ready',
                'message' => 'Brief summary of the action performed.',
                'missing_fields' => [],
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'html' => '<!doctype html><html><body>...</body></html>',
                'json' => [
                    'titulo' => '...',
                ],
            ],
            'rules' => [
                'Return only one JSON object.',
                'Use the user question as the primary source of truth.',
                'Extract concrete values from the message and reflect them in json.',
                'Follow the styling instructions from the message if they exist.',
                'Include a real visual brand mark when the document is corporate.',
                'Make the message describe the generated document, not the service-pdf transport.',
                'Do not invent taxes or numeric values that are not in the message.',
                'Keep json keys aligned with the html placeholders.',
                'If the request includes styling hints, include them in the html.',
                'If more information is needed, ask one concise question.',
                'Use response_language as the primary language directive.',
                'If the user writes in Spanish, answer in Spanish.',
                'If the user writes in English, answer in English.',
                'If the user mixes Spanish and English, respond in the dominant language of the message.',
                'If context.adjunto.text_preview is present, use it as the source for the explanation or summary.',
                'If context.adjunto.has_attachment is true and the message asks about the attached PDF, do not respond with a generic request for more context.',
                'If the attachment has no readable extracted text, explain that briefly and do not invent content.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        return [
            'pathname' => trim((string) ($context['pathname'] ?? '')),
            'document_type' => trim((string) ($context['document_type'] ?? '')),
            'theme' => trim((string) ($context['theme'] ?? '')),
            'notes' => trim((string) ($context['notes'] ?? '')),
            'adjunto' => $this->normalizeAdjunto($context['adjunto'] ?? null),
        ];
    }

    /**
     * @param mixed $adjunto
     * @return array<string, mixed>
     */
    private function normalizeAdjunto(mixed $adjunto): array
    {
        if (!is_array($adjunto) || $adjunto === []) {
            return [];
        }

        return [
            'name' => trim((string) ($adjunto['name'] ?? '')),
            'mime_type' => trim((string) ($adjunto['mime_type'] ?? '')),
            'size' => (int) ($adjunto['size'] ?? 0),
            'has_attachment' => (bool) ($adjunto['has_attachment'] ?? false),
            'action' => trim((string) ($adjunto['action'] ?? '')),
            'text_preview' => trim((string) ($adjunto['text_preview'] ?? '')),
            'text_truncated' => (bool) ($adjunto['text_truncated'] ?? false),
            'extraction_status' => trim((string) ($adjunto['extraction_status'] ?? '')),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array<string, string>>
     */
    private function normalizeHistory(array $history): array
    {
        return array_values(array_map(static function (array $item): array {
            return [
                'role' => (string) ($item['role'] ?? ''),
                'content' => (string) ($item['content'] ?? ''),
            ];
        }, array_slice($history, -6)));
    }

    private function resolveResponseLanguage(string $locale, string $message): string
    {
        $normalizedMessage = strtolower(trim($message));
        $normalizedLocale = strtolower(trim(str_replace('_', '-', $locale)));

        $spanishSignals = [
            '¿',
            '¡',
            ' qué ',
            ' como ',
            ' cómo ',
            'explicame',
            'explícame',
            ' explica',
            'explica',
            ' resumen',
            'resumeme',
            'resúmeme',
            ' revisa',
            'revisar',
            'analiza',
            ' adjunto',
            ' documento',
            ' todo',
            'pdf',
        ];
        $englishSignals = [
            ' what ',
            ' how ',
            ' explain',
            ' summary',
            ' review',
            ' attachment',
            ' document',
        ];

        $spanishScore = 0;
        foreach ($spanishSignals as $signal) {
            if (str_contains($normalizedMessage, $signal)) {
                $spanishScore++;
            }
        }

        $englishScore = 0;
        foreach ($englishSignals as $signal) {
            if (str_contains($normalizedMessage, $signal)) {
                $englishScore++;
            }
        }

        if ($spanishScore > $englishScore) {
            return 'es';
        }

        if ($englishScore > $spanishScore) {
            return 'en';
        }

        if ($normalizedLocale !== '') {
            if (str_starts_with($normalizedLocale, 'es')) {
                return 'es';
            }

            if (str_starts_with($normalizedLocale, 'en')) {
                return 'en';
            }
        }

        return 'auto';
    }
}

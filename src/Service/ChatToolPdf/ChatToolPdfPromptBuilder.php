<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

final class ChatToolPdfPromptBuilder
{
    public function buildQuestionSystemPrompt(): string
    {
        return <<<'PROMPT'
Eres un asistente conversacional para una herramienta empresarial.
Responde solo texto plano, sin markdown, sin listas innecesarias y sin mencionar procesos internos.
Si falta información, pide una sola aclaración breve.
Si la pregunta es clara, responde de forma directa, útil y corta.
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
            'history' => $this->normalizeHistory($history),
            'context' => $this->normalizeContext($context),
            'rules' => [
                'Answer directly in plain text.',
                'Do not output JSON.',
                'Do not mention internal tools unless the user asks.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function buildPdfSystemPrompt(): string
    {
        return <<<'PROMPT'
Eres un generador de documentos para un microservicio de PDF.
Debes responder solo con JSON valido, sin markdown, sin bloques de codigo y sin texto adicional.
Tu tarea es convertir la peticion del usuario en una plantilla HTML/Twig y un objeto JSON compatible con el servicio de PDF.
La fuente principal de verdad es la pregunta del usuario: extrae de ahí el tipo de documento, el contenido, los datos, el estilo y la intención.
No dependas de context para inventar información que ya está en la pregunta.
Si la pregunta describe el diseño, respeta esas indicaciones de estilo de forma visible en el HTML.
Si la pregunta no describe diseño, usa un estilo corporativo limpio, moderno y sobrio.

Reglas:
- Si falta informacion critica para construir el documento, responde con status = "needs_clarification".
- En ese caso, message debe ser una sola pregunta breve en español o ingles depende de idioma de la pregunta.
- missing_fields debe listar los campos faltantes.
- Si la informacion es suficiente, responde con status = "ready".
- Cuando status sea "ready", html debe contener una plantilla HTML completa o un fragmento HTML bien formado.
- La plantilla HTML puede usar sintaxis Twig y debe coincidir con las claves del objeto json.
- json debe contener solo los datos necesarios para renderizar el html.
- No inventes datos sensibles ni numericos.
- Usa exactamente los valores numéricos y nombres que aparezcan en la pregunta.
- Si la pregunta pide logo, marca, color, tabla, encabezado, subtitulo o estilo, incluyelos en el html.
- Si el documento es corporativo, incluye una marca visual real: una imagen `img`, un isotipo SVG embebido o un logo vectorial sencillo. No uses solo la palabra "Logo" como texto decorativo.
- Si la pregunta incluye nombres, valores, cantidades o totales, reflejalos en el json y úsalos en el html.
- Si la pregunta no indica impuestos, no inventes impuestos ni porcentajes.
- Si la pregunta sí indica impuestos, calcula y muestra subtotal, impuesto y total.
- Si la pregunta no trae datos suficientes para un documento coherente, pide exactamente lo que falta.
- El campo message debe resumir de forma concreta qué documento creaste, para quién, y si aplica, qué estilo o composición usaste.
- Usa paper_size con valores comunes como A4 o LETTER.
- Usa orientation con valores portrait o landscape.
- Mantén el tono profesional y la estructura clara.
- Usa HTML semántico cuando aporte claridad visual: `header`, `section`, `footer`, `article`, `nav`, `aside`, `main`, `figure`, `figcaption`.
- Si el diseño lo amerita, puedes combinar semántica HTML5 con `div` y `table`.

Esquema de salida:
{
  "status": "ready|needs_clarification",
  "message": "texto breve",
  "missing_fields": [],
  "paper_size": "A4",
  "orientation": "portrait",
  "html": "<!doctype html>...</html>",
  "json": {}
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
            'history' => $this->normalizeHistory($history),
            'context' => $this->normalizeContext($context),
            'output_schema' => [
                'status' => 'ready',
                'message' => 'Resumen breve de la accion realizada.',
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
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
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
        ];
    }
}

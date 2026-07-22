<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\ChatToolPdf\ChatToolPdfGenerationService;
use App\Service\ChatToolPdf\PdfAttachmentPreviewExtractor;
use App\Service\ChatToolPdf\PdfClient;
use App\Service\ChatToolPdf\PdfVisionExtractor;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class ChatToolPdfController
{
    public function __construct(
        private readonly ChatToolPdfGenerationService $generationService,
        private readonly PdfAttachmentPreviewExtractor $attachmentPreviewExtractor,
        private readonly PdfVisionExtractor $pdfVisionExtractor,
        private readonly PdfClient $servicePdfClient,
        private readonly LoggerInterface $logger,
        private readonly string $assistantName,
        private readonly string $chattoolpdfEnvironment,
    ) {
    }

    #[Route('/debug/chattoolpdf', name: 'debug_chattoolpdf', methods: ['GET'])]
    public function debug(Request $request): Response
    {
        $message = (string) $request->query->get('message', 'explicar este pdf');
        $tenant = (string) $request->query->get('tenant', 'projects');
        $usuario = (string) $request->query->get('usuario', 'services@onlinesoftwarepro.com');
        $locale = (string) $request->query->get('locale', 'es');
        $tool = (string) $request->query->get('tool', '0');
        $conversationId = (string) $request->query->get('conversation_id', '');
        $clientKey = (string) $request->query->get('client_key', '');
        $messageEsc = $this->escapeHtml($message);
        $tenantEsc = $this->escapeHtml($tenant);
        $usuarioEsc = $this->escapeHtml($usuario);
        $localeEsc = $this->escapeHtml($locale);
        $conversationIdEsc = $this->escapeHtml($conversationId);
        $clientKeyEsc = $this->escapeHtml($clientKey);
        $toolFalseSelected = $tool === '0' ? 'selected' : '';
        $toolTrueSelected = $tool === '1' ? 'selected' : '';

        $html = <<<HTML
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Debug ChatToolPdf</title>
  <style>
    :root {
      color-scheme: dark;
      --bg: #0f1115;
      --panel: #171a21;
      --panel-2: #1f2430;
      --text: #e8eaf0;
      --muted: #9ca3af;
      --border: #2b3240;
      --accent: #7dd3fc;
      --accent-2: #38bdf8;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: radial-gradient(circle at top, #18202b 0%, var(--bg) 42%);
      color: var(--text);
    }
    .wrap {
      max-width: 1180px;
      margin: 0 auto;
      padding: 24px;
    }
    .hero {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: end;
      margin-bottom: 20px;
    }
    .hero h1 {
      margin: 0 0 8px;
      font-size: 28px;
    }
    .hero p {
      margin: 0;
      color: var(--muted);
    }
    .grid {
      display: grid;
      grid-template-columns: 420px 1fr;
      gap: 20px;
    }
    .card {
      background: linear-gradient(180deg, rgba(31,36,48,.96), rgba(23,26,33,.96));
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 18px;
      box-shadow: 0 20px 45px rgba(0,0,0,.25);
    }
    .field { margin-bottom: 14px; }
    label {
      display: block;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: var(--muted);
      margin-bottom: 6px;
    }
    input, textarea, select, button {
      width: 100%;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--panel-2);
      color: var(--text);
      padding: 12px 14px;
      font: inherit;
    }
    textarea {
      min-height: 120px;
      resize: vertical;
    }
    .row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .row-3 {
      display: grid;
      grid-template-columns: 1.2fr .8fr .8fr;
      gap: 12px;
    }
    .actions {
      display: flex;
      gap: 12px;
      margin-top: 12px;
    }
    button {
      cursor: pointer;
      background: linear-gradient(180deg, var(--accent), var(--accent-2));
      color: #00111d;
      font-weight: 700;
      border: none;
    }
    button.secondary {
      background: transparent;
      color: var(--text);
      border: 1px solid var(--border);
    }
    pre {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
      background: #0b0d12;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 14px;
      min-height: 240px;
      color: #dbeafe;
    }
    .status {
      margin-bottom: 12px;
      color: var(--accent);
      font-weight: 600;
    }
    .hint {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
      margin-top: 8px;
    }
    @media (max-width: 980px) {
      .grid { grid-template-columns: 1fr; }
      .hero { align-items: start; flex-direction: column; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="hero">
      <div>
        <h1>Debug ChatToolPdf</h1>
        <p>Formulario local para enviar un payload JSON con PDF base64 al endpoint <code>/api/chattoolpdf</code>.</p>
      </div>
      <div class="hint">Archivo adjunto, lectura en navegador y envío directo al microservicio.</div>
    </div>

    <div class="grid">
      <section class="card">
        <form id="debug-form">
          <div class="field">
            <label for="message">Message</label>
            <textarea id="message" name="message">{$messageEsc}</textarea>
          </div>
          <div class="row">
            <div class="field">
              <label for="tenant">Tenant</label>
              <input id="tenant" name="tenant" value="{$tenantEsc}">
            </div>
            <div class="field">
              <label for="usuario">Usuario</label>
              <input id="usuario" name="usuario" value="{$usuarioEsc}">
            </div>
          </div>
          <div class="row-3">
            <div class="field">
              <label for="locale">Locale</label>
              <input id="locale" name="locale" value="{$localeEsc}">
            </div>
            <div class="field">
              <label for="tool">Tool</label>
              <select id="tool" name="tool">
                <option value="0" {$toolFalseSelected}>false</option>
                <option value="1" {$toolTrueSelected}>true</option>
              </select>
            </div>
            <div class="field">
              <label for="conversation_id">Conversation ID</label>
              <input id="conversation_id" name="conversation_id" value="{$conversationIdEsc}" placeholder="opcional">
            </div>
          </div>
          <div class="field">
            <label for="client_key">Client Key</label>
            <input id="client_key" name="client_key" value="{$clientKeyEsc}" placeholder="opcional">
          </div>
          <div class="field">
            <label for="adjunto">Adjunto PDF</label>
            <input id="adjunto" name="adjunto" type="file" accept="application/pdf,.pdf">
          </div>
          <div class="actions">
            <button type="submit">Enviar</button>
            <button type="button" class="secondary" id="reset-btn">Limpiar salida</button>
          </div>
          <div class="hint">
            El archivo se lee en el navegador y se manda como <code>content_base64</code> para simular exactamente el payload del host.
          </div>
        </form>
      </section>

      <section class="card">
        <div class="status" id="status">Esperando envío...</div>
        <pre id="output"></pre>
      </section>
    </div>
  </div>

  <script>
    const form = document.getElementById('debug-form');
    const output = document.getElementById('output');
    const statusNode = document.getElementById('status');
    const resetBtn = document.getElementById('reset-btn');

    const setOutput = (value) => {
      output.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
    };

    const readFileAsBase64 = (file) => new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error('No fue posible leer el archivo.'));
      reader.onload = () => {
        const result = String(reader.result || '');
        const commaIndex = result.indexOf(',');
        resolve(commaIndex >= 0 ? result.slice(commaIndex + 1) : '');
      };
      reader.readAsDataURL(file);
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      statusNode.textContent = 'Leyendo archivo y enviando...';

      try {
        const formData = new FormData(form);
        const file = formData.get('adjunto');

        const payload = {
          message: String(formData.get('message') || '').trim(),
          tenant: String(formData.get('tenant') || '').trim(),
          usuario: String(formData.get('usuario') || '').trim(),
          locale: String(formData.get('locale') || '').trim(),
          tool: String(formData.get('tool') || '0') === '1',
          conversation_id: String(formData.get('conversation_id') || '').trim(),
          client_key: String(formData.get('client_key') || '').trim(),
          history: [],
        };

        if (file && file.size > 0) {
          const contentBase64 = await readFileAsBase64(file);
          payload.adjunto = {
            name: file.name,
            mime_type: file.type || 'application/pdf',
            size: file.size,
            content_base64: contentBase64,
          };
          payload.context = {
            adjunto: {
              has_attachment: true,
              name: file.name,
              mime_type: file.type || 'application/pdf',
              size: file.size,
              action: 'analysis',
            },
          };
        }

        setOutput(payload);

        const response = await fetch('/api/chattoolpdf', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: JSON.stringify(payload),
        });

        const text = await response.text();
        let parsed = text;
        try {
          parsed = JSON.parse(text);
        } catch (error) {}

        statusNode.textContent = 'Respuesta recibida';
        setOutput({
          request: payload,
          responseStatus: response.status,
          response: parsed,
        });
      } catch (error) {
        statusNode.textContent = 'Error al enviar';
        setOutput({
          error: error instanceof Error ? error.message : String(error),
        });
      }
    });

    resetBtn.addEventListener('click', () => {
      output.textContent = '';
      statusNode.textContent = 'Esperando envío...';
    });
  </script>
</body>
</html>
HTML;

        return new Response($html);
    }

    #[Route('/api/chattoolpdf', name: 'api_chattoolpdf', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'El body debe ser un JSON valido.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = trim((string) ($payload['message'] ?? ($payload['question'] ?? '')));
        $conversationId = trim((string) ($payload['conversation_id'] ?? ''));
        $clientKey = trim((string) ($payload['client_key'] ?? ''));
        $tool = $this->normalizeBool($payload['tool'] ?? false);
        $tenant = trim((string) ($payload['tenant'] ?? ''));
        $usuario = trim((string) ($payload['usuario'] ?? ''));
        $locale = $this->normalizeLocale($payload['locale'] ?? '');
        $adjunto = is_array($payload['adjunto'] ?? null)
            ? $payload['adjunto']
            : (is_array($payload['archivo'] ?? null)
                ? $payload['archivo']
                : (is_array($payload['pdf'] ?? null) ? $payload['pdf'] : []));
        $declaredAdjuntoAction = strtolower(trim((string) ($payload['adjunto_action'] ?? $payload['action'] ?? '')));
        $requestContext = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $history = is_array($payload['history'] ?? null) ? $payload['history'] : [];

        if ($message === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'El campo message es obligatorio.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($conversationId !== '' && (strlen($conversationId) !== 32 || !ctype_xdigit($conversationId))) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'El identificador de conversacion no es valido.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conversationId = $this->resolveConversationId($conversationId);

        if ($adjunto !== []) {
            $adjuntoTexto = $this->attachmentPreviewExtractor->extractPreview(
                (string) ($adjunto['content_base64'] ?? ''),
                (string) ($adjunto['mime_type'] ?? ''),
                (string) ($adjunto['name'] ?? '')
            );
            $adjuntoVision = $this->extractFirstPageImageBase64($adjunto);
            $requestContext['adjunto'] = [
                'has_attachment' => true,
                'action' => $declaredAdjuntoAction,
                'declared_action' => $declaredAdjuntoAction,
                'name' => (string) ($adjunto['name'] ?? ''),
                'mime_type' => (string) ($adjunto['mime_type'] ?? ''),
                'size' => (int) ($adjunto['size'] ?? 0),
                'text_preview' => $adjuntoTexto['text_preview'],
                'text_truncated' => $adjuntoTexto['text_truncated'],
                'extraction_status' => $adjuntoTexto['status'],
                'first_page_image_available' => $adjuntoVision !== null,
            ];
            $metadata['adjunto'] = [
                'has_attachment' => true,
                'action' => $declaredAdjuntoAction,
                'declared_action' => $declaredAdjuntoAction,
                'name' => (string) ($adjunto['name'] ?? ''),
                'mime_type' => (string) ($adjunto['mime_type'] ?? ''),
                'size' => (int) ($adjunto['size'] ?? 0),
                'text_length' => $adjuntoTexto['text_length'],
                'text_truncated' => $adjuntoTexto['text_truncated'],
                'extraction_status' => $adjuntoTexto['status'],
                'first_page_image_available' => $adjuntoVision !== null,
                'first_page_image_base64' => $adjuntoVision,
                'first_page_image_mime_type' => $adjuntoVision !== null ? 'image/jpeg' : '',
            ];
        }

        if ($tool && ($tenant === '' || $usuario === '')) {
            $missingFields = [];
            if ($tenant === '') {
                $missingFields[] = 'tenant';
            }
            if ($usuario === '') {
                $missingFields[] = 'usuario';
            }

            return new JsonResponse([
                'status' => 'pending',
                'message' => $this->buildIdentityClarificationMessage($missingFields),
                'missing_fields' => $missingFields,
                'assistant' => $this->assistantName,
                'conversation_id' => $conversationId,
                'client_key' => $clientKey,
                'tool' => $tool,
            ]);
        }

        try {
            $aiResponse = $this->generationService->processRequest(
                message: $message,
                tenant: $tenant,
                usuario: $usuario,
                entorno: $this->chattoolpdfEnvironment,
                locale: $locale,
                tool: $tool,
                history: $history,
                context: [
                    ...$requestContext,
                    'client_key' => $clientKey,
                    'metadata' => $metadata,
                ]
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('No fue posible procesar chattoolpdf.', [
                'exception' => $exception,
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
                'tool' => $tool,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_GATEWAY);
        } catch (Throwable $exception) {
            $this->logger->error('No fue posible procesar chattoolpdf.', [
                'exception' => $exception,
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
                'tool' => $tool,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Ocurrio un error interno al procesar la solicitud.',
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        if (($aiResponse['status'] ?? '') === 'needs_clarification') {
            $assistantMessage = trim((string) ($aiResponse['message'] ?? ''));
            $missingFields = is_array($aiResponse['missing_fields'] ?? null) ? array_values(array_map('strval', $aiResponse['missing_fields'])) : [];

            return new JsonResponse([
                'status' => 'pending',
                'message' => $assistantMessage,
                'missing_fields' => $missingFields,
            ]);
        }

        if (($aiResponse['mode'] ?? 'chat') === 'chat') {
            return new JsonResponse([
                'status' => 'success',
                'message' => (string) ($aiResponse['message'] ?? ''),
            ]);
        }

        $pdfPayload = [
            'tenant' => $tenant,
            'usuario' => $usuario,
            'entorno' => $this->chattoolpdfEnvironment,
            'html' => (string) ($aiResponse['html'] ?? ''),
            'json' => is_array($aiResponse['json'] ?? null) ? $aiResponse['json'] : [],
            'paper_size' => (string) ($aiResponse['paper_size'] ?? 'A4'),
            'orientation' => (string) ($aiResponse['orientation'] ?? 'portrait'),
        ];

        if (trim($pdfPayload['html']) === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'La IA no devolvio un HTML valido para generar el PDF.',
                'raw' => $aiResponse,
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        try {
            $pdfResponse = $this->servicePdfClient->generate($pdfPayload);
        } catch (RuntimeException $exception) {
            $this->logger->error('No fue posible invocar service-pdf.', [
                'exception' => $exception,
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_GATEWAY);
        } catch (Throwable $exception) {
            $this->logger->error('No fue posible completar la generacion del PDF.', [
                'exception' => $exception,
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'No fue posible completar la generacion del PDF.',
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        if (($pdfResponse['ok'] ?? false) !== true) {
            $statusCode = (int) ($pdfResponse['status_code'] ?? JsonResponse::HTTP_BAD_GATEWAY);
            $errorMessage = (string) ($pdfResponse['body']['error'] ?? $pdfResponse['message'] ?? 'service-pdf rechazo la solicitud.');

            $this->logger->error('service-pdf rechazo la solicitud.', [
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
                'status_code' => $statusCode,
                'response' => $pdfResponse,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => $errorMessage,
                'raw' => $pdfResponse,
            ], $statusCode);
        }

        $assistantMessage = trim((string) ($aiResponse['message'] ?? ''));
        if ($assistantMessage === '') {
            $assistantMessage = (string) ($pdfResponse['message'] ?? 'PDF generado correctamente.');
        }

        $pdfUrl = (string) ($pdfResponse['pdf_url'] ?? '');

        return new JsonResponse([
            'status' => 'success',
            'message' => $assistantMessage,
            'pdf_url' => $pdfUrl,
        ]);
    }

    private function resolveConversationId(string $conversationId): string
    {
        if ($conversationId === '') {
            return bin2hex(random_bytes(16));
        }

        return strtolower($conversationId);
    }

    /**
     * @param array<int, string> $missingFields
     */
    private function buildIdentityClarificationMessage(array $missingFields): string
    {
        $missingFields = array_values(array_filter($missingFields, static fn (string $field): bool => $field !== ''));

        if ($missingFields === []) {
            return 'Necesito tenant y usuario para continuar.';
        }

        if ($missingFields === ['tenant', 'usuario']) {
            return 'Necesito tenant y usuario para poder generar el PDF.';
        }

        if ($missingFields === ['tenant']) {
            return 'Necesito el tenant para poder generar el PDF.';
        }

        if ($missingFields === ['usuario']) {
            return 'Necesito el usuario para poder generar el PDF.';
        }

        return 'Necesito completar los datos requeridos para generar el PDF.';
    }

    private function normalizeLocale(mixed $locale): string
    {
        $normalized = strtolower(trim((string) ($locale ?? '')));

        return str_replace('_', '-', $normalized);
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
    }

    /**
     * @param array<string, mixed> $adjunto
     */
    private function extractFirstPageImageBase64(array $adjunto): ?string
    {
        $mimeType = strtolower(trim((string) ($adjunto['mime_type'] ?? '')));
        if ($mimeType !== 'application/pdf') {
            return null;
        }

        $contentBase64 = trim((string) ($adjunto['content_base64'] ?? ''));
        if ($contentBase64 === '') {
            return null;
        }

        $binary = base64_decode($contentBase64, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $pdfPath = tempnam(sys_get_temp_dir(), 'adjunto_pdf_');
        if ($pdfPath === false) {
            return null;
        }

        try {
            if (file_put_contents($pdfPath, $binary) === false) {
                return null;
            }

            return $this->pdfVisionExtractor->extractFirstPageAsBase64($pdfPath);
        } finally {
            @unlink($pdfPath);
        }
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

use App\Entity\Loger;
use App\Service\Vector\ChatContextRetriever;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use OSP\Message\AsistenteIA\ChatToolIAPdfResponse;
use OSP\Message\AsistenteIA\ChatToolPdfMessage;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ChatToolPdfMessageProcessor
{
    private const QUESTION_SYSTEM_PROMPT = 'Responde como un asistente conversacional en el mismo idioma del usuario. Usa el historial proporcionado como contexto y determina por la intención de la pregunta actual si el usuario está editando una cotización anterior.

Si la pregunta solicita modificar, actualizar, corregir o recalcular una cotización del historial, devuelve EXCLUSIVAMENTE un JSON válido (puedes usar un bloque de markdown ```json o texto plano directo del JSON, sin texto introductorio ni despedidas) con los cambios aplicados, conservando de forma estricta las claves "message" y "quotation" con la siguiente estructura exacta:
{
  "message": "string",
  "quotation": {
    "quotation_number": "string",
    "status": "string",
    "date": "string",
    "valid_until": "string",
    "currency": "string",
    "issuer": {
      "legal_name": "string",
      "tax_id": "string",
      "address": "string",
      "city": "string",
      "country": "string",
      "email": "string",
      "phone": "string"
    },
    "client": {
      "legal_name": "string",
      "tax_id": "string",
      "contact_person": "string",
      "address": "string",
      "city": "string",
      "country": "string",
      "email": "string",
      "phone": "string"
    },
    "commercial_terms": {
      "payment_method": "string",
      "payment_terms": "string",
      "delivery_time": "string",
      "warranty": "string"
    },
    "items": [
      {
        "item_id": "string",
        "description": "string",
        "quantity": 0,
        "unit_price": 0,
        "discount_percentage": 0,
        "tax_percentage": 0,
        "subtotal": 0,
        "total": 0
      }
    ],
    "subtotal": 0,
    "taxes": 0,
    "discounts": 0,
    "total": 0,
    "notes": "string"
  }
}

Si la pregunta no solicita una edición de la cotización, responde únicamente con texto conversacional claro y breve; no devuelvas JSON. No inventes información ni crees una cotización nueva cuando no exista una cotización relacionada en el historial.';
    private const SYSTEM_PROMPT = 'Eres un experto estimador de obra. Tu tarea es analizar planos arquitectónicos y generar cotizaciones en formato JSON.
PASOS OBLIGATORIOS:
1. Analiza el mensaje del usuario para extraer precios, tarifas o materiales si los menciona.
2. Analiza el plano adjunto para identificar áreas, elementos y cantidades lógicas.
3. Si faltan precios, usa supuestos razonables y explícitalos dentro de notes.
4. Nunca respondas con negativa, evasiva o texto fuera del JSON.
5. Responde SOLO en JSON válido conservando exactamente esta estructura:
{
  "message": "string",
  "quotation": {
    "quotation_number": "string",
    "status": "string",
    "date": "string",
    "valid_until": "string",
    "currency": "string",
    "issuer": {
      "legal_name": "string",
      "tax_id": "string",
      "address": "string",
      "city": "string",
      "country": "string",
      "email": "string",
      "phone": "string"
    },
    "client": {
      "legal_name": "string",
      "tax_id": "string",
      "contact_person": "string",
      "address": "string",
      "city": "string",
      "country": "string",
      "email": "string",
      "phone": "string"
    },
    "commercial_terms": {
      "payment_method": "string",
      "payment_terms": "string",
      "delivery_time": "string",
      "warranty": "string"
    },
    "items": [
      {
        "item_id": "string",
        "description": "string",
        "quantity": 0,
        "unit_price": 0,
        "discount_percentage": 0,
        "tax_percentage": 0,
        "subtotal": 0,
        "total": 0
      }
    ],
    "subtotal": 0,
    "taxes": 0,
    "discounts": 0,
    "total": 0,
    "notes": "string"
  }
}
6. El campo message debe resumir el resultado del análisis y la intención de la cotización.
7. El campo quotation no debe ser null. Si hay poca información, completa con estimaciones conservadoras y acláralo en notes.';
    private const IMAGE_ANALYSIS_SYSTEM_PROMPT = 'Eres un analista experto de planos arquitectónicos y cotizaciones.
    Analiza exclusivamente la imagen adjunta y devuelve un resumen técnico de los elementos visibles: espacios, medidas, áreas, materiales, cantidades, niveles, instalaciones y cualquier dato útil para elaborar una cotización.
    No inventes datos que no sean visibles; indica claramente cuando una estimación no sea posible. Responde en el mismo idioma del usuario y únicamente con el análisis de esta imagen, sin generar todavía la cotización final.
    su es una cotizacion usa los datos que encuentras para crear la respuesta';
    private const DEFAULT_USER_QUESTION = 'Por favor, genérame una cotización a partir de este plano. Usa moneda COP como estándar, analiza cada detalle posible del plano y estima valores razonables si faltan datos.';
    private const HTML_SKELETON_PROMPT = <<<PROMPT
Eres un desarrollador frontend experto en disenio. Tu única tarea es tomar los datos de la cotización proporcionada y mapearlos dentro de este esqueleto HTML si el usuario pide cambiar el disenio lo cambias con css nativo puedes modificar los valores de las etiquetas para dar un aspecto mas profecional.

REGLAS OBLIGATORIAS:
1. Inyecta los datos en los marcadores (ej. [QUOTATION_NUMBER]). Genera una fila <tr> por cada ítem.
2. Usa el CSS proporcionado como base, pero puedes modificar colores, tipografías, espaciados, bordes, fondos y cualquier otro estilo cuando la instrucción del usuario lo solicite. La instrucción del usuario tiene prioridad sobre los valores visuales del esqueleto.
3. Mantén la estructura de <!DOCTYPE html>, <html>, <head> y <body>.
4. Para una cotización generada o actualizada hoy, usa la fecha actual indicada en la solicitud. Si no se especifica una fecha de validez, usa la fecha actual más 15 días.
5. RESPONDE ÚNICAMENTE CON EL CÓDIGO HTML FINAL. No incluyas explicaciones ni bloques de Markdown.

ESQUELETO HTML BASE:
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cotización</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f3f4f6; color: #374151; padding: 20px; margin: 0; }
        .container { max-width: 900px; margin: 0 auto; background-color: #ffffff; padding: 40px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 8px; }
        header { display: table; width: 100%; border-bottom: 2px solid #e5e7eb; padding-bottom: 20px; margin-bottom: 20px; }
        .header-left { display: table-cell; vertical-align: top; }
        .header-right { display: table-cell; vertical-align: top; text-align: right; }
        h1 { font-size: 28px; font-weight: bold; text-transform: uppercase; color: #111827; margin: 0; }
        .text-sm { font-size: 14px; margin: 4px 0; color: #4b5563; }
        .mb-8 { margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px; }
        th { background-color: #1f2937; color: white; padding: 12px; text-align: left; }
        th.text-center, td.text-center { text-align: center; }
        th.text-right, td.text-right { text-align: right; }
        td { padding: 12px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) { background-color: #f9fafb; }
        .totales-box { width: 300px; float: right; background-color: #f9fafb; padding: 15px; border: 1px solid #e5e7eb; border-radius: 5px; }
        .totales-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .total-final { font-size: 18px; font-weight: bold; border-top: 1px solid #d1d5db; padding-top: 10px; margin-top: 10px; color: #111827; }
        .clearfix::after { content: ""; clear: both; display: table; }
        footer { border-top: 2px solid #e5e7eb; padding-top: 20px; font-size: 14px; color: #4b5563; clear: both; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Encabezado -->
        <header>
            <div class="header-left">
                <h1>Cotización</h1>
                <p style="font-size: 18px; font-weight: bold; margin: 15px 0 5px; color: #111827;">[LEGAL_NAME_EMISOR]</p>
                <p class="text-sm">NIT: [TAX_ID_EMISOR]</p>
                <p class="text-sm">[EMAIL_EMISOR] | [PHONE_EMISOR]</p>
            </div>
            <div class="header-right">
                <p class="text-sm" style="font-weight: bold; text-transform: uppercase;">Número</p>
                <p style="font-size: 20px; font-weight: bold; margin: 5px 0; color: #111827;">[QUOTATION_NUMBER]</p>
                <p class="text-sm">Fecha: [DATE]</p>
                <p class="text-sm">Válida hasta: [VALID_UNTIL]</p>
            </div>
        </header>

        <!-- Cliente -->
        <section class="mb-8">
            <h2 style="font-size: 14px; text-transform: uppercase; border-bottom: 1px solid #e5e7eb; padding-bottom: 5px; color: #6b7280;">Cotizado a:</h2>
            <p style="font-size: 16px; font-weight: bold; margin: 10px 0 5px; color: #111827;">[LEGAL_NAME_CLIENTE]</p>
            <p class="text-sm">Atención: [CONTACT_PERSON_CLIENTE]</p>
            <p class="text-sm">NIT: [TAX_ID_CLIENTE]</p>
            <p class="text-sm">[ADDRESS_CLIENTE], [CITY_CLIENTE]</p>
        </section>

        <!-- Tabla de Ítems -->
        <section class="mb-8">
            <table>
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th class="text-center">Cant.</th>
                        <th class="text-right">Precio Unit.</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- LA IA DEBE GENERAR LOS <tr> AQUÍ BASADO EN LOS ITEMS DEL JSON -->
                    <tr>
                        <td>[ITEM_DESCRIPTION]</td>
                        <td class="text-center">[ITEM_QUANTITY]</td>
                        <td class="text-right">[ITEM_UNIT_PRICE]</td>
                        <td class="text-right" style="font-weight: bold;">[ITEM_TOTAL]</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Totales -->
        <section class="mb-8 clearfix">
            <div class="totales-box">
                <div class="totales-row"><span>Subtotal:</span> <span>[SUBTOTAL]</span></div>
                <div class="totales-row"><span>Impuestos:</span> <span>[TAXES]</span></div>
                <div class="totales-row"><span>Descuentos:</span> <span>[DISCOUNTS]</span></div>
                <div class="totales-row total-final">
                    <span>Total:</span>
                    <span>[TOTAL]</span>
                </div>
            </div>
        </section>

        <!-- Notas -->
        <footer>
            <h3 style="font-size: 14px; text-transform: uppercase; margin-bottom: 10px; color: #6b7280;">Términos y Notas</h3>
            <p><strong>Forma de pago:</strong> [PAYMENT_METHOD]</p>
            <p><strong>Tiempo de entrega:</strong> [DELIVERY_TIME]</p>
            <p style="margin-top: 15px; white-space: pre-line;">[NOTES]</p>
        </footer>
    </div>
</body>
</html>
PROMPT;
    public function __construct(
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
        private string $model,
        private int $maxHistoryMessages,
        private string $stirlingEndpoint,
        private string $gotenbergEndpoint,
        private ChatContextRetriever $chatContextRetriever,
        #[Autowire(service: 'ai.traceable_platform.openai')]
        private PlatformInterface $platform,
        #[Autowire(service: 'planos.storage')]
        private FilesystemOperator $planosStorage,
        #[Autowire(service: 'planos.storage.images')]
        private FilesystemOperator $planosImagesStorage,
    ) {}

    public function process(ChatToolPdfMessage $message): void
    {
        $attachmentPath = $message->getAttachmentKey();
        $chatId = (string) $message->getChatId();
        $userText = $message->getMessage();
        $aiContent = null;
        $pdfUrl = null;

        if (!is_string($attachmentPath) || trim($attachmentPath) === '') {
            $aiContent = $this->answerQuestionOnly($userText, $chatId);
        } else {
            $attachmentPath = trim($attachmentPath);

            try {
                if (!$this->planosStorage->fileExists($attachmentPath)) {
                    $this->logger->error('[ChatToolPdfMessageProcessor] El archivo no existe en storage.', [
                        'attachment_path' => $attachmentPath,
                        'chat_id' => $message->getChatId(),
                    ]);

                    $aiContent = $this->answerQuestionOnly($userText, $chatId);
                } else {
                    $zipBinaryContent = $this->convertAndStorePdf($attachmentPath, $message);
                    $aiContent = $this->analyzeZipBinary($zipBinaryContent, $userText, $chatId);
                }
            } catch (\Throwable $exception) {
                $this->logger->error('[ChatToolPdfMessageProcessor] No fue posible completar el flujo de IA.', [
                    'attachment_path' => $attachmentPath,
                    'chat_id' => $message->getChatId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($aiContent !== null && trim($aiContent) !== '') {
            // Las respuestas conversacionales no contienen HTML y no deben
            // intentar pasar por el conversor de PDF.
            if ($this->hasGeneratedHtml($aiContent)) {
                $pdfUrl = $this->generateAndStorePdfFromContent($aiContent, $chatId);
            }

            $this->saveConversationHistory($chatId, $userText, $aiContent);
        }

        $this->dispatchResponse(
            $message,
            is_string($attachmentPath) && trim($attachmentPath) !== '' ? trim($attachmentPath) : null,
            $aiContent,
            $pdfUrl,
        );
    }

    private function generateAndStorePdfFromContent(string $content, string $chatId): ?string
    {
        $decoded = json_decode(trim($content), true);
        $html = is_array($decoded) ? trim((string) ($decoded['html'] ?? '')) : '';

        if ($html === '') {
            return null;
        }

        $tempHtmlPath = tempnam(sys_get_temp_dir(), 'quotation_html_');
        if ($tempHtmlPath === false || file_put_contents($tempHtmlPath, $html) === false) {
            throw new \RuntimeException('No fue posible preparar el HTML para Gotenberg.');
        }

        try {
            $formData = new FormDataPart([
                'files' => DataPart::fromPath($tempHtmlPath, 'index.html', 'text/html'),
            ]);
            $response = $this->httpClient->request('POST', $this->gotenbergEndpoint, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf(
                    'Gotenberg no pudo convertir el HTML a PDF. Status %d: %s',
                    $response->getStatusCode(),
                    $response->getContent(false),
                ));
            }

            // Se guarda como los adjuntos: en planos.storage y se conserva
            // la clave del objeto, no una URL interna del contenedor.
            $pdfPath = bin2hex(random_bytes(32)) . '.pdf';
            $this->planosStorage->write(
                $pdfPath,
                $response->getContent(),
                ['visibility' => 'public'],
            );

            $this->logger->info('[ChatToolPdfMessageProcessor] PDF HTML generado y guardado en MinIO.', [
                'chat_id' => $chatId,
                'pdf_key' => $pdfPath,
                'bucket' => 'planos-entrada',
            ]);

            return $pdfPath;
        } finally {
            if (file_exists($tempHtmlPath)) {
                unlink($tempHtmlPath);
            }
        }
    }

    private function hasGeneratedHtml(string $content): bool
    {
        $decoded = json_decode(trim($content), true);

        return is_array($decoded) && trim((string) ($decoded['html'] ?? '')) !== '';
    }

    private function saveConversationHistory(string $chatId, string $userText, string $aiContent): void
    {
        try {
            $this->chatContextRetriever->saveMessage($chatId, 'user', $userText);
        } catch (\Throwable $exception) {
            $this->logger->error('[ChatToolPdfMessageProcessor] No fue posible guardar el mensaje del usuario en Qdrant.', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $decoded = json_decode(trim($aiContent), true);
            $html = is_array($decoded) ? trim((string) ($decoded['html'] ?? '')) : '';

            if ($html !== '' && is_array($decoded)) {
                unset($decoded['html']);
                $jsonContent = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($jsonContent) && $jsonContent !== '') {
                    $this->chatContextRetriever->saveMessage($chatId, 'assistant', $jsonContent, $jsonContent);
                    $this->chatContextRetriever->saveMessage($chatId, 'assistant_html', $html, $html);
                }
            } else {
                $this->chatContextRetriever->saveMessage($chatId, 'assistant', $aiContent, $aiContent);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('[ChatToolPdfMessageProcessor] No fue posible guardar la respuesta de la IA en Qdrant.', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function answerQuestionOnly(string $question, string $chatId): string
    {
        return $this->callOpenAiQuestionOnly($this->normalizeQuestion($question), $chatId);
    }

    private function analyzeZipBinary(string $zipBinary, string $question, string $chatId): string
    {
        $imagePaths = $this->extractImagesToTempFiles($zipBinary);

        try {
            if ([] === $imagePaths) {
                return $this->answerQuestionOnly($question, $chatId);
            }

            return $this->callOpenAiWithImages($this->normalizeQuestion($question), $imagePaths, $chatId);
        } finally {
            // Limpieza garantizada de las imágenes temporales
            foreach ($imagePaths as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @param array<int, string> $imagePaths
     */
    private function callOpenAiQuestionOnly(string $question, string $chatId): string
    {
        $agent = new Agent($this->platform, $this->model);
        $history = $this->getConversationHistory($chatId);
        $prompt = self::QUESTION_SYSTEM_PROMPT;

        $this->logger->info('[ChatToolPdfMessageProcessor] Consultando historial para pregunta sin adjunto.', [
            'chat_id' => $chatId,
            'history_messages' => count($history),
            'has_history' => $history !== [],
        ]);

        $messages = $this->buildMessagesWithHistory(
            $chatId,
            new UserMessage(new Text($question !== '' ? $question : self::DEFAULT_USER_QUESTION)),
            $prompt,
            $history,
        );

        $response = $agent->call(new MessageBag(...$messages));
        $content = trim((string) $response->getContent());

        if ($content === '') {
            throw new \RuntimeException('La respuesta de OpenAI no devolvio contenido.');
        }

        $contentArray = $this->normalizeStructuredContent($content);

        if ($contentArray !== null && !empty($contentArray['quotation'])) {
            $this->logger->info('[ChatToolPdfMessageProcessor] Modificación detectada. Iniciando generación de HTML (Paso 2).');

            $contentArray['html'] = $this->callOpenAiQuestionOnlyHtmlSkeleton($contentArray['quotation'], $question);
            $encoded = json_encode($contentArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (!is_string($encoded) || $encoded === '') {
                throw new \RuntimeException('No fue posible serializar la cotización modificada.');
            }

            return $encoded;
        }

        $this->logger->info('[ChatToolPdfMessageProcessor] Respuesta de OpenAI sin adjuntos recibida.', [
            'content_preview' => mb_substr($content, 0, 1000),
        ]);

        return $content;
    }

    /**
     * Genera el HTML inyectando la cotización en el esqueleto definido.
     *
     * @param array<string, mixed> $quotationData
     */
    private function callOpenAiQuestionOnlyHtmlSkeleton(array $quotationData, string $userInstruction = ''): string
    {
        $agent = new Agent($this->platform, $this->model);
        $quotationJson = json_encode($quotationData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($quotationJson) || $quotationJson === '') {
            throw new \RuntimeException('No fue posible serializar los datos de la cotización para generar el HTML.');
        }

        $currentDate = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
        $today = $currentDate->format('Y-m-d');
        $defaultValidUntil = $currentDate->modify('+15 days')->format('Y-m-d');
        $userPrompt = sprintf(
            "Fecha actual: %s\nFecha de validez predeterminada si no se indica otra: %s\n\nInstrucción del usuario para el diseño o contenido:\n%s\n\nDatos de la cotización para inyectar:\n%s",
            $today,
            $defaultValidUntil,
            $userInstruction !== '' ? $userInstruction : 'Mantén un diseño profesional usando el esqueleto base.',
            $quotationJson,
        );
        $messages = new MessageBag(
            new SystemMessage(self::HTML_SKELETON_PROMPT),
            new UserMessage(new Text($userPrompt)),
        );

        $response = $agent->call($messages);
        $html = trim((string) $response->getContent());
        $html = preg_replace('/^```html\s*/i', '', $html) ?? $html;
        $html = preg_replace('/\s*```$/', '', $html) ?? $html;
        $html = trim($html);

        if ($html === '') {
            throw new \RuntimeException('La respuesta de OpenAI para el HTML no devolvió contenido.');
        }

        return $html;
    }

    /**
     * @param array<int, string> $imagePaths
     */
    private function callOpenAiWithImages(string $question, array $imagePaths, string $chatId): string
    {
        $agent = new Agent($this->platform, $this->model);
        $imageAnalyses = [];

        foreach ($imagePaths as $index => $path) {
            $imageNumber = $index + 1;
            $imageMessage = new UserMessage(
                new Text(sprintf(
                    "Analiza la imagen %d  para esta solicitud: %s",
                    $imageNumber,
                    $question !== '' ? $question : self::DEFAULT_USER_QUESTION,
                )),
                Image::fromFile($path),
            );

            $imageResponse = $agent->call(new MessageBag(
                new SystemMessage(self::IMAGE_ANALYSIS_SYSTEM_PROMPT),
                $imageMessage,
            ));
            $imageAnalysis = trim((string) $imageResponse->getContent());

            if ($imageAnalysis === '') {
                $this->logger->warning('[ChatToolPdfMessageProcessor] La IA no devolvió análisis para una imagen.', [
                    'chat_id' => $chatId,
                    'image_number' => $imageNumber,
                ]);
                continue;
            }

            $imageAnalyses[] = [
                'image_number' => $imageNumber,
                'analysis' => $imageAnalysis,
            ];
        }

        if ($imageAnalyses === []) {
            throw new \RuntimeException('La IA no devolvió análisis para ninguna imagen del plano.');
        }

        $analysesJson = json_encode($imageAnalyses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($analysesJson) || $analysesJson === '') {
            throw new \RuntimeException('No fue posible consolidar los análisis individuales de las imágenes.');
        }

        $finalPrompt = sprintf(
            "Solicitud original del usuario:\n%s\n\nAnálisis individuales de las imágenes del plano:\n%s\n\nUsa estos análisis consolidados para generar la cotización final. Integra la información de todas las imágenes, evita duplicar elementos visibles en varias páginas y responde únicamente con el JSON de cotización solicitado.",
            $question !== '' ? $question : self::DEFAULT_USER_QUESTION,
            $analysesJson,
        );
        $messages = $this->buildMessagesWithHistory(
            $chatId,
            new UserMessage(new Text($finalPrompt)),
            self::SYSTEM_PROMPT,
        );

        $response = $agent->call(new MessageBag(...$messages));

        $content = $response->getContent();
        $contentArray = $this->normalizeStructuredContent($content);

        if (null === $contentArray) {
            throw new \RuntimeException('La respuesta de OpenAI no devolvio una estructura de cotizacion valida.');
        }

        $this->logger->info('[ChatToolPdfMessageProcessor] Iniciando generación de HTML (Paso 2).');
        $contentArray['html'] = $this->callOpenAiQuestionOnlyHtmlSkeleton($contentArray['quotation'], $question);

        $encoded = json_encode($contentArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || '' === $encoded) {
            throw new \RuntimeException('No fue posible serializar la respuesta final.');
        }

        $this->logger->info('[ChatToolPdfMessageProcessor] Respuesta de OpenAI recibida.', [
            'content_preview' => mb_substr($encoded, 0, 1000),
            'has_quotation' => null !== $contentArray['quotation'],
        ]);

        return $encoded;
    }

    /**
     * Construye los mensajes en el orden que necesita el agente:
     * instrucciones del sistema, historial cronológico y mensaje actual.
     *
     * @return array<int, SystemMessage|UserMessage|AssistantMessage>
     */
    /**
     * @param array<int, array{role: string, content: string}>|null $history
     * @return array<int, SystemMessage|UserMessage|AssistantMessage>
     */
    private function buildMessagesWithHistory(string $chatId, UserMessage $currentMessage, string $systemPrompt, ?array $history = null): array
    {
        $messages = [new SystemMessage($systemPrompt)];

        foreach ($history ?? $this->getConversationHistory($chatId) as $historyMessage) {
            $content = trim((string) ($historyMessage['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $role = strtolower(trim((string) ($historyMessage['role'] ?? '')));
            if ($role === 'assistant') {
                $messages[] = new AssistantMessage(new Text($content));
                continue;
            }

            if ($role === 'user') {
                $messages[] = new UserMessage(new Text($content));
            }
        }

        $messages[] = $currentMessage;

        return $messages;
    }

    /**
     * El historial es contextual; si Qdrant no está disponible no debe impedir
     * que el agente procese el mensaje actual.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function getConversationHistory(string $chatId): array
    {
        try {
            return $this->chatContextRetriever->getHistoryBySession($chatId, max(1, $this->maxHistoryMessages));
        } catch (\Throwable $exception) {
            $this->logger->warning('[ChatToolPdfMessageProcessor] No fue posible recuperar el historial desde Qdrant.', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function convertAndStorePdf(string $attachmentPath, ChatToolPdfMessage $message): string
    {
        $pdfBinary = $this->planosStorage->read($attachmentPath);
        $tempPdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pdf_to_convert_', true) . '.pdf';

        if (file_put_contents($tempPdfPath, $pdfBinary) === false) {
            throw new \RuntimeException('No fue posible escribir el archivo temporal en el disco.');
        }

        try {
            $formFields = [
                'fileInput' => DataPart::fromPath($tempPdfPath, 'documento.pdf', 'application/pdf'),
                'pageNumbers' => 'all',
                'imageFormat' => 'jpeg',
                'singleOrMultiple' => 'multiple',
                'colorType' => 'color',
                'dpi' => '300', // Resolución perfecta para gpt-4o-mini
            ];

            $formData = new FormDataPart($formFields);
            $response = $this->httpClient->request('POST', $this->stirlingEndpoint, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException(sprintf('Stirling devolvió status %d: %s', $response->getStatusCode(), $response->getContent(false)));
            }

            $zipBinaryContent = $response->getContent();
            $zipPath = $this->buildZipPath($attachmentPath);

            $this->planosImagesStorage->write($zipPath, $zipBinaryContent);

            $loger = new Loger($zipPath, $message->getCreatedAt());
            $this->entityManager->persist($loger);
            $this->entityManager->flush();

            $this->logger->info('[ChatToolPdfMessageProcessor] PDF convertido con Stirling.', [
                'attachment_path' => $attachmentPath,
                'zip_path' => $zipPath,
            ]);

            return $zipBinaryContent;
        } finally {
            if (file_exists($tempPdfPath)) {
                unlink($tempPdfPath);
            }
        }
    }

    /**
     * Extrae las imágenes a disco en lugar de RAM para proteger el servidor.
     * @return array<int, string>
     */
    private function extractImagesToTempFiles(string $zipBinary): array
    {
        if ('' === $zipBinary) {
            return [];
        }

        $tempZipPath = tempnam(sys_get_temp_dir(), 'stirling_zip_');
        if ($tempZipPath === false) {
            return [];
        }

        $tempZipPath .= '.zip';
        file_put_contents($tempZipPath, $zipBinary);

        $imagePaths = [];

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tempZipPath) !== true) {
                return [];
            }

            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entryName = $zip->getNameIndex($i);
                if (!is_string($entryName) || !preg_match('/\.(jpe?g|png|webp)$/i', $entryName)) {
                    continue;
                }

                $imageBinary = $zip->getFromIndex($i);
                if ($imageBinary === false || $imageBinary === '') {
                    continue;
                }

                $extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                $tempImagePath = tempnam(sys_get_temp_dir(), 'stirling_img_');
                if ($tempImagePath !== false) {
                    $tempImagePath .= '.' . ($extension ?: 'jpg');
                    file_put_contents($tempImagePath, $imageBinary);
                    $imagePaths[] = $tempImagePath;
                }
            }
        } finally {
            if (file_exists($tempZipPath)) {
                unlink($tempZipPath);
            }
        }

        return $imagePaths;
    }

    private function dispatchResponse(ChatToolPdfMessage $message, ?string $attachmentPath, ?string $content = null, ?string $pdfUrl = null): void
    {
        $resolvedContent = $content !== null && trim($content) !== '' ? $content : $message->getMessage();

        $this->logger->info('[ChatToolPdfMessageProcessor] Dispatching response content.', [
            'chat_id' => $message->getChatId(),
            'attachment_path' => $attachmentPath,
            'content_preview' => mb_substr($resolvedContent, 0, 500),
        ]);

        $this->messageBus->dispatch(new ChatToolIAPdfResponse(
            chatId: $message->getChatId(),
            userIdentifier: $message->getUserIdentifier(),
            content: $resolvedContent,
            pdfUrl: $pdfUrl,
            mercureTopic: $message->getMercureTopic(),
            originalNameAttachment: $attachmentPath !== null ? basename($attachmentPath) : null,
            attachmentPath: $message->getAttachmentKey(),
            createdAt: $message->getCreatedAt(),
        ));
    }

    private function buildZipPath(string $attachmentPath): string
    {
        return preg_replace('/\.pdf$/i', '', $attachmentPath) . '.stirling.zip';
    }

    private function normalizeQuestion(string $question): string
    {
        return trim($question);
    }

    /**
     * @param mixed $content
     * @return array{message: string, quotation: array<string, mixed>}|null
     */
    private function normalizeStructuredContent(mixed $content): ?array
    {
        if (is_string($content)) {
            $content = $this->decodeJsonContent($content);
        } elseif (is_object($content)) {
            $content = json_decode(json_encode($content, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }

        if (!is_array($content)) {
            return null;
        }

        $message = trim((string) ($content['message'] ?? ''));
        $quotation = $this->normalizeQuotationContent($content['quotation'] ?? null);

        if ($message === '' || $quotation === null) {
            return null;
        }

        return [
            'message' => $message,
            'quotation' => $quotation,
        ];
    }

    /**
     * @param mixed $quotation
     * @return array<string, mixed>|null
     */
    private function normalizeQuotationContent(mixed $quotation): ?array
    {
        if (is_string($quotation)) {
            $quotation = $this->decodeJsonContent($quotation);
        } elseif (is_object($quotation)) {
            $quotation = json_decode(json_encode($quotation, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }

        if (!is_array($quotation)) {
            return null;
        }

        return [
            'quotation_number' => $this->normalizeString($quotation['quotation_number'] ?? null),
            'status' => $this->normalizeString($quotation['status'] ?? null),
            'date' => $this->normalizeString($quotation['date'] ?? null),
            'valid_until' => $this->normalizeString($quotation['valid_until'] ?? null),
            'currency' => $this->normalizeString($quotation['currency'] ?? null),
            'issuer' => $this->normalizeParty($quotation['issuer'] ?? null, false),
            'client' => $this->normalizeParty($quotation['client'] ?? null, true),
            'commercial_terms' => $this->normalizeCommercialTerms($quotation['commercial_terms'] ?? null),
            'items' => $this->normalizeItems($quotation['items'] ?? null),
            'subtotal' => $this->normalizeNumber($quotation['subtotal'] ?? null),
            'taxes' => $this->normalizeNumber($quotation['taxes'] ?? null),
            'discounts' => $this->normalizeNumber($quotation['discounts'] ?? null),
            'total' => $this->normalizeNumber($quotation['total'] ?? null),
            'notes' => $this->normalizeString($quotation['notes'] ?? null),
        ];
    }

    private function decodeJsonContent(string $content): mixed
    {
        $normalized = trim($content);

        // La IA puede anteponer una explicación al JSON aunque el prompt
        // indique que debe responder únicamente con la estructura.
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $normalized, $matches) === 1) {
            $normalized = trim($matches[1]);
        } else {
            // Permite recuperar un JSON incrustado en una respuesta textual.
            $jsonStart = strpos($normalized, '{');
            $jsonEnd = strrpos($normalized, '}');

            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $normalized = substr($normalized, $jsonStart, $jsonEnd - $jsonStart + 1);
            }
        }

        $decoded = json_decode(trim($normalized), true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeParty(mixed $party, bool $includeContactPerson): array
    {
        if (is_string($party)) {
            $party = $this->decodeJsonContent($party);
        }

        if (!is_array($party)) {
            $party = [];
        }

        $normalized = [
            'legal_name' => $this->normalizeString($party['legal_name'] ?? null),
            'tax_id' => $this->normalizeString($party['tax_id'] ?? null),
            'address' => $this->normalizeString($party['address'] ?? null),
            'city' => $this->normalizeString($party['city'] ?? null),
            'country' => $this->normalizeString($party['country'] ?? null),
            'email' => $this->normalizeString($party['email'] ?? null),
            'phone' => $this->normalizeString($party['phone'] ?? null),
        ];

        if ($includeContactPerson) {
            $normalized['contact_person'] = $this->normalizeString($party['contact_person'] ?? null);
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeCommercialTerms(mixed $terms): array
    {
        if (!is_array($terms)) {
            $terms = [];
        }

        return [
            'payment_method' => $this->normalizeString($terms['payment_method'] ?? null),
            'payment_terms' => $this->normalizeString($terms['payment_terms'] ?? null),
            'delivery_time' => $this->normalizeString($terms['delivery_time'] ?? null),
            'warranty' => $this->normalizeString($terms['warranty'] ?? null),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_map(static function (mixed $item): array {
            if (is_object($item)) {
                $item = get_object_vars($item);
            }

            if (!is_array($item)) {
                $item = [];
            }

            return [
                'item_id' => (string) ($item['item_id'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 0,
                'unit_price' => is_numeric($item['unit_price'] ?? null) ? (float) $item['unit_price'] : 0,
                'discount_percentage' => is_numeric($item['discount_percentage'] ?? null) ? (float) $item['discount_percentage'] : 0,
                'tax_percentage' => is_numeric($item['tax_percentage'] ?? null) ? (float) $item['tax_percentage'] : 0,
                'subtotal' => is_numeric($item['subtotal'] ?? null) ? (float) $item['subtotal'] : 0,
                'total' => is_numeric($item['total'] ?? null) ? (float) $item['total'] : 0,
            ];
        }, $items));
    }

    private function normalizeString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normalizeNumber(mixed $value): int|float
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}

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
use App\Service\Prompt\PromptLoader;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ChatToolPdfMessageProcessorOld
{
    private const INTENT_CONVERSATION = 'conversation';
    private const INTENT_ANALYZE_DOCUMENT = 'analyze_document';
    private const INTENT_CREATE_DOCUMENT = 'create_document';
    private const INTENT_EDIT_DOCUMENT = 'edit_document';
    private const INTENT_NO_UNDERSTAND_QUESTION = 'no_understand_question';

    // Intenciones antiguas conservadas mientras continúa la refactorización.
    private const INTENT_CREATE_QUOTATION = 'create_quotation';
    private const INTENT_EDIT_QUOTATION = 'edit_quotation';
    private const INTENT_RENDER_QUOTATION = 'render_quotation';
    private const DEFAULT_CONVERSATION_QUESTION = '¿En qué aspecto de la cotización necesitas ayuda?';
    private const DEFAULT_QUOTATION_FROM_DOCUMENT_QUESTION = 'Genera una cotización a partir del documento adjunto. Usa moneda COP y limita los supuestos a precios o tarifas, identificándolos en las notas.';
    private const DEFAULT_QUOTATION_MESSAGE = 'Cotización generada a partir de la información disponible.';

    private function loadPrompt(string $promptName): string
    {
        return $this->promptLoader->load('chattoolpdf/' . $promptName);
    }

    private function getConversationSystemPrompt(): string
    {
        return $this->loadPrompt('conversation_system_prompt.md');
    }

    private function getDocumentSummarySystemPrompt(): string
    {
        return $this->loadPrompt('document_summary_system_prompt.md');
    }

    private function getQuotationConsolidationSystemPrompt(): string
    {
        return $this->loadPrompt('quotation_consolidation_system_prompt.md');
    }

    private function getQuestionSystemPrompt(): string
    {
        return $this->loadPrompt('question_system_prompt.md');
    }

    private function getSystemPrompt(): string
    {
        return $this->loadPrompt('system_prompt.md');
    }

    private function getImageAnalysisSystemPrompt(): string
    {
        return $this->loadPrompt('image_analysis_system_prompt.md');
    }

    private function getIntentClassificationSystemPrompt(): string
    {
        return $this->loadPrompt('intent_classification_system_prompt.md');
    }

    private function getHtmlSkeletonPrompt(): string
    {
        return $this->loadPrompt('html_skeleton_prompt.md');
    }

    public function __construct(
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
        #[Autowire('%app.chattoolpdf_model%')]
        private string $model,
        #[Autowire('%app.chattoolpdf_max_history_messages%')]
        private int $maxHistoryMessages,
        #[Autowire('%app.chattoolpdf_max_image_analyses%')]
        private int $maxImageAnalyses,
        #[Autowire('%app.stirling_pdf_endpoint%')]
        private string $stirlingEndpoint,
        #[Autowire('%app.gotenberg_endpoint%')]
        private string $gotenbergEndpoint,
        private ChatContextRetriever $chatContextRetriever,
        private PromptLoader $promptLoader,
        #[Autowire(service: 'ai.platform.openai')]
        private PlatformInterface $platform,
        #[Autowire(service: 'chattoolpdf.storage.attach_pdf')]
        private FilesystemOperator $attachPdfStorage,
        #[Autowire(service: 'chattoolpdf.storage.zip')]
        private FilesystemOperator $chattoolpdfZipStorage,
    ) {}

    public function process(ChatToolPdfMessage $message): void
    {

        $question = $this->normalizeQuestion($message->getMessage());
        $intent = $this->classifyIntent($question);

        $aiContent = $intent;
        if ($aiContent === '') {
            $aiContent = 'La IA no devolvió contenido.';
        }

        $this->dispatchResponse($message, content: $aiContent);





















        return;




















        /*
        $attachmentPath = $message->getAttachmentKey();
        $chatId = (string) $message->getChatId();
        $userText = $this->normalizeQuestion($message->getMessage());
        $aiContent = null;
        $pdfUrl = null;
        $resolvedAttachmentPath = is_string($attachmentPath) && trim($attachmentPath) !== ''
            ? trim($attachmentPath)
            : null;
        $history = $this->getConversationHistory($chatId);
        $currentQuotation = $this->findLatestQuotation($history);
        $intent = $this->classifyIntent($userText);

        try {
            if ($intent === self::INTENT_RENDER_QUOTATION) {
                $aiContent = $currentQuotation !== null
                    ? $this->renderExistingQuotation($currentQuotation, $userText, $chatId)
                    : 'No existe una cotización previa para volver a generar el PDF.';
            } elseif ($intent === self::INTENT_CONVERSATION) {
                $aiContent = $this->answerConversation($userText, $chatId, $history);
            } elseif ($resolvedAttachmentPath === null) {
                $aiContent = match ($intent) {
                    self::INTENT_EDIT_QUOTATION => $this->answerQuestionOnly($userText, $chatId),
                    self::INTENT_CREATE_QUOTATION => $this->createQuotationFromText($userText, $chatId, $history),
                    default => $this->answerConversation($userText, $chatId, $history),
                };
            } else {
                if (!$this->attachPdfStorage->fileExists($resolvedAttachmentPath)) {
                    $this->logger->error('[ChatToolPdfMessageProcessor] El archivo no existe en storage.', [
                        'attachment_path' => $resolvedAttachmentPath,
                        'chat_id' => $message->getChatId(),
                    ]);
                    $aiContent = 'No fue posible procesar el documento adjunto porque el archivo no existe.';
                } else {
                    $zipBinaryContent = $this->convertAndStorePdf($resolvedAttachmentPath, $message);
                    $aiContent = $this->analyzeZipBinary(
                        $zipBinaryContent,
                        $userText,
                        $chatId,
                        $intent === self::INTENT_ANALYZE_DOCUMENT,
                    );
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->error('[ChatToolPdfMessageProcessor] No fue posible completar el flujo de IA.', [
                'attachment_path' => $resolvedAttachmentPath,
                'chat_id' => $message->getChatId(),
                'intent' => $intent,
                'error' => $exception->getMessage(),
            ]);
            $aiContent = $resolvedAttachmentPath !== null
                ? 'No fue posible procesar el documento adjunto. Verifica que sea un PDF válido e inténtalo nuevamente.'
                : 'No fue posible procesar la solicitud. Inténtalo nuevamente.';
        }

        if ($aiContent !== null && trim($aiContent) !== '') {
            // Las respuestas conversacionales no contienen HTML y no deben
            // intentar pasar por el conversor de PDF.
            if ($this->hasGeneratedHtml($aiContent)) {
                $pdfUrl = $this->generateAndStorePdfFromContent($aiContent, $chatId);
            }

            $this->saveConversationHistory($chatId, $userText, $aiContent);
        }

        $responseContent = $aiContent !== null ? $this->removeInternalHtml($aiContent) : null;
        $this->dispatchResponse(
            $message,
            $resolvedAttachmentPath,
            $responseContent,
            $pdfUrl,
        );
        */
    }

    private function removeInternalHtml(string $content): string
    {
        $decoded = json_decode(trim($content), true);
        if (!is_array($decoded) || !array_key_exists('html', $decoded)) {
            return $content;
        }

        unset($decoded['html']);
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) && $encoded !== '' ? $encoded : $content;
    }

    private function classifyIntent(string $userText): string
    {

        try {
            $agent = new Agent($this->platform, $this->model);
            $response = $agent->call(new MessageBag(
                new SystemMessage($this->getIntentClassificationSystemPrompt()),
                new UserMessage(new Text($userText)),
            ));
            $decoded = $this->decodeJsonContent((string) $response->getContent());
            $intent = is_array($decoded) ? (string) ($decoded['intent'] ?? '') : '';
            return $intent;
        } catch (\Throwable $exception) {
            $this->logger->warning('[ChatToolPdfMessageProcessor] Falló el clasificador de intención; se aplicará una ruta segura.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return self::INTENT_CONVERSATION;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array<string, mixed>|null
     */
    private function findLatestQuotation(array $history): ?array
    {
        foreach (array_reverse($history) as $historyMessage) {
            if (($historyMessage['role'] ?? '') !== 'assistant') {
                continue;
            }

            $decoded = $this->decodeJsonContent((string) ($historyMessage['content'] ?? ''));
            if (!is_array($decoded)) {
                continue;
            }

            $quotation = $this->normalizeQuotationContent($decoded['quotation'] ?? null);
            if ($quotation !== null && $quotation['items'] !== []) {
                return $quotation;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $quotation
     */
    private function renderExistingQuotation(array $quotation, string $designInstruction, string $chatId): string
    {
        $this->validateQuotationForHtml($quotation);
        $content = [
            'message' => 'Se generó nuevamente el PDF de la cotización sin modificar sus datos comerciales.',
            'quotation' => $quotation,
            'html' => $this->callOpenAiQuestionOnlyHtmlSkeleton(
                $quotation,
                $designInstruction,
                $this->getLatestQuotationHtml($chatId),
            ),
        ];
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('No fue posible serializar la cotización renderizada.');
        }

        return $encoded;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    private function answerConversation(string $question, string $chatId, array $history): string
    {
        $messages = $this->buildMessagesWithHistory(
            $chatId,
            new UserMessage(new Text($question !== '' ? $question : self::DEFAULT_CONVERSATION_QUESTION)),
            $this->getConversationSystemPrompt(),
            $history,
        );
        $response = (new Agent($this->platform, $this->model))->call(new MessageBag(...$messages));
        $content = trim((string) $response->getContent());

        if ($content === '') {
            throw new \RuntimeException('La respuesta conversacional no devolvió contenido.');
        }

        return $content;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    private function createQuotationFromText(string $question, string $chatId, array $history): string
    {
        $prompt = $this->getSystemPrompt()
            . "\n\nEn esta ruta no hay documento adjunto. Usa únicamente datos aportados por el usuario; no atribuyas información a un plano."
            . "\n\n" . $this->getQuotationDateInstruction();
        $messages = $this->buildMessagesWithHistory(
            $chatId,
            new UserMessage(new Text($question !== '' ? $question : self::DEFAULT_CONVERSATION_QUESTION)),
            $prompt,
            $history,
        );
        $response = (new Agent($this->platform, $this->model))->call(new MessageBag(...$messages));
        $contentArray = $this->normalizeStructuredContent($response->getContent());
        if ($contentArray === null) {
            throw new \RuntimeException('La IA no devolvió una cotización válida a partir del texto.');
        }

        $this->validateQuotationForHtml($contentArray['quotation']);
        $contentArray['html'] = $this->callOpenAiQuestionOnlyHtmlSkeleton(
            $contentArray['quotation'],
            $question,
            $this->getLatestQuotationHtml($chatId),
        );
        $encoded = json_encode($contentArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('No fue posible serializar la cotización creada por texto.');
        }

        return $encoded;
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

            // Se guarda como los adjuntos: en chattoolpdf.storage.attach_pdf y se conserva
            // la clave del objeto, no una URL interna del contenedor.
            $pdfPath = bin2hex(random_bytes(32)) . '.pdf';
            $this->attachPdfStorage->write(
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
                    $this->chatContextRetriever->saveMessage($chatId, 'assistant_html', $html, $jsonContent);
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

    private function analyzeZipBinary(string $zipBinary, string $question, string $chatId, bool $analysisOnly): string
    {
        $imagePaths = $this->extractImagesToTempFiles($zipBinary);

        try {
            if ([] === $imagePaths) {
                return $analysisOnly
                    ? 'No fue posible extraer imágenes legibles del documento adjunto.'
                    : $this->answerQuestionOnly($question, $chatId);
            }

            return $this->callOpenAiWithImages(
                $this->normalizeQuestion($question),
                $imagePaths,
                $chatId,
                $analysisOnly,
            );
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
        $prompt = $this->getQuestionSystemPrompt() . "\n\n" . $this->getQuotationDateInstruction();

        $this->logger->info('[ChatToolPdfMessageProcessor] Consultando historial para pregunta sin adjunto.', [
            'chat_id' => $chatId,
            'history_messages' => count($history),
            'has_history' => $history !== [],
        ]);

        $messages = $this->buildMessagesWithHistory(
            $chatId,
            new UserMessage(new Text($question !== '' ? $question : self::DEFAULT_CONVERSATION_QUESTION)),
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

            $this->validateQuotationForHtml($contentArray['quotation']);
            $contentArray['html'] = $this->callOpenAiQuestionOnlyHtmlSkeleton(
                $contentArray['quotation'],
                $question,
                $this->getLatestQuotationHtml($chatId),
            );
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
    private function callOpenAiQuestionOnlyHtmlSkeleton(array $quotationData, string $userInstruction = '', ?string $previousHtml = null): string
    {
        $agent = new Agent($this->platform, $this->model);
        $quotationJson = json_encode($quotationData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($quotationJson) || $quotationJson === '') {
            throw new \RuntimeException('No fue posible serializar los datos de la cotización para generar el HTML.');
        }

        $userPrompt = sprintf(
            "Instrucción visual del usuario:\n%s\n\nHTML base anterior para editar, si existe:\n%s\n\nDatos de la cotización para renderizar:\n%s",
            $userInstruction !== '' ? $userInstruction : 'Mantén un diseño profesional usando el esqueleto base.',
            $previousHtml !== null && trim($previousHtml) !== '' ? $previousHtml : 'No existe un HTML previo.',
            $quotationJson,
        );
        $messages = new MessageBag(
            new SystemMessage($this->getHtmlSkeletonPrompt()),
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
     * Valida los tipos críticos antes de entregarlos al generador HTML.
     *
     * @param array<string, mixed> $quotation
     */
    private function validateQuotationForHtml(array $quotation): void
    {
        if (trim((string) ($quotation['currency'] ?? '')) === '') {
            throw new \RuntimeException('La cotización no contiene una moneda válida.');
        }

        foreach (['issuer', 'client', 'commercial_terms', 'items'] as $field) {
            if (!is_array($quotation[$field] ?? null)) {
                throw new \RuntimeException(sprintf(
                    'La cotización no contiene una estructura válida en el campo "%s".',
                    $field,
                ));
            }
        }

        if ($quotation['items'] === []) {
            throw new \RuntimeException('La cotización debe contener al menos un ítem.');
        }

        foreach (['subtotal', 'taxes', 'discounts', 'total'] as $field) {
            if (!is_int($quotation[$field] ?? null) && !is_float($quotation[$field] ?? null)) {
                throw new \RuntimeException(sprintf(
                    'El campo numérico "%s" de la cotización no es válido.',
                    $field,
                ));
            }
        }

        $itemNumericFields = [
            'quantity',
            'unit_price',
            'discount_percentage',
            'tax_percentage',
            'subtotal',
            'total',
        ];

        foreach ($quotation['items'] as $itemIndex => $item) {
            if (!is_array($item)) {
                throw new \RuntimeException(sprintf(
                    'El ítem %d de la cotización no tiene una estructura válida.',
                    $itemIndex + 1,
                ));
            }

            if (trim((string) ($item['description'] ?? '')) === '') {
                throw new \RuntimeException(sprintf('El ítem %d no contiene descripción.', $itemIndex + 1));
            }

            if ((float) ($item['quantity'] ?? 0) <= 0) {
                throw new \RuntimeException(sprintf('La cantidad del ítem %d debe ser mayor que cero.', $itemIndex + 1));
            }

            foreach ($itemNumericFields as $field) {
                if (!is_int($item[$field] ?? null) && !is_float($item[$field] ?? null)) {
                    throw new \RuntimeException(sprintf(
                        'El campo numérico "%s" del ítem %d no es válido.',
                        $field,
                        $itemIndex + 1,
                    ));
                }
            }
        }
    }

    private function getQuotationDateInstruction(): string
    {
        $currentDate = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));

        return sprintf(
            'Solo cuando la intención sea crear o editar una cotización: la fecha actual es %s. Si el usuario no especifica fecha o vigencia, usa esa fecha y una vigencia estándar de 15 días. Si especifica una fecha o plazo, respétalo. Registra el resultado en date y valid_until. Para respuestas conversacionales, ignora por completo esta instrucción temporal y no devuelvas JSON.',
            $currentDate->format('Y-m-d'),
        );
    }

    /**
     * @param array<int, string> $imagePaths
     */
    private function callOpenAiWithImages(string $question, array $imagePaths, string $chatId, bool $analysisOnly): string
    {
        $agent = new Agent($this->platform, $this->model);
        $history = $this->getConversationHistory($chatId);
        $imageAnalyses = [];
        $maxImageAnalyses = max(1, $this->maxImageAnalyses);
        $imagesToAnalyze = array_slice($imagePaths, 0, $maxImageAnalyses);

        if (count($imagePaths) > count($imagesToAnalyze)) {
            $this->logger->warning('[ChatToolPdfMessageProcessor] Se limitó la cantidad de imágenes analizadas para proteger los límites de la API.', [
                'chat_id' => $chatId,
                'total_images' => count($imagePaths),
                'analyzed_images' => count($imagesToAnalyze),
                'omitted_images' => count($imagePaths) - count($imagesToAnalyze),
            ]);
        }

        $this->logger->info('[ChatToolPdfMessageProcessor] Preparando análisis consolidado con historial.', [
            'chat_id' => $chatId,
            'history_messages' => count($history),
            'images' => count($imagesToAnalyze),
        ]);

        foreach ($imagesToAnalyze as $index => $path) {
            $imageNumber = $index + 1;

            // Cada página se envía como un mensaje independiente para que la IA
            // pueda analizarla junto con la instrucción concreta del usuario.
            // Image::fromFile prepara el archivo local como contenido visual del mensaje.
            $imageMessage = new UserMessage(
                new Text(sprintf(
                    "Analiza la imagen %d para esta solicitud: %s",
                    $imageNumber,
                    $question !== '' ? $question : self::DEFAULT_QUOTATION_FROM_DOCUMENT_QUESTION,
                )),
                Image::fromFile($path),
            );

            $imageMessages = $this->buildMessagesWithHistory(
                $chatId,
                $imageMessage,
                $this->getImageAnalysisSystemPrompt(),
                $history,
            );

            // El agente recibe el prompt del sistema, el historial de la conversación
            // y el mensaje actual que contiene la imagen. La respuesta es el análisis
            // de esta página y luego se incorporará al análisis consolidado del documento.
            $imageResponse = $agent->call(new MessageBag(...$imageMessages));
            $imageAnalysis = trim((string) $imageResponse->getContent());

            if ($imageAnalysis === '') {
                $this->logger->warning('[ChatToolPdfMessageProcessor] La IA no devolvió análisis para una imagen.', [
                    'chat_id' => $chatId,
                    'image_number' => $imageNumber,
                ]);
                continue;
            }

            $structuredAnalysis = $this->decodeJsonContent($imageAnalysis);
            if (!is_array($structuredAnalysis)) {
                $this->logger->warning('[ChatToolPdfMessageProcessor] La IA no devolvió JSON válido para una imagen; se conservará un resumen de respaldo.', [
                    'chat_id' => $chatId,
                    'image_number' => $imageNumber,
                ]);
                $structuredAnalysis = [
                    'summary' => $imageAnalysis,
                    'spaces' => [],
                    'measurements' => [],
                    'materials' => [],
                    'quantities' => [],
                    'installations' => [],
                    'notes' => 'La respuesta intermedia no llegó en JSON válido.',
                ];
            }

            $imageAnalyses[] = [
                'image_number' => $imageNumber,
                'analysis' => $structuredAnalysis,
            ];
        }

        if ($imageAnalyses === []) {
            throw new \RuntimeException('La IA no devolvió análisis para ninguna imagen del plano.');
        }

        $analysesJson = json_encode($imageAnalyses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($analysesJson) || $analysesJson === '') {
            throw new \RuntimeException('No fue posible consolidar los análisis individuales de las imágenes.');
        }

        $coverage = [
            'total_pages' => count($imagePaths),
            'analyzed_pages' => count($imagesToAnalyze),
            'omitted_pages' => count($imagePaths) - count($imagesToAnalyze),
            'is_complete' => count($imagePaths) === count($imagesToAnalyze),
        ];
        $coverageJson = json_encode($coverage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($coverageJson) || $coverageJson === '') {
            throw new \RuntimeException('No fue posible serializar la cobertura del documento.');
        }

        $finalPrompt = sprintf(
            "Solicitud actual:\n%s\n\ndocument_coverage:\n%s\n\nAnálisis individuales:\n%s",
            $question !== '' ? $question : self::DEFAULT_QUOTATION_FROM_DOCUMENT_QUESTION,
            $coverageJson,
            $analysesJson,
        );
        if ($analysisOnly) {
            $summaryMessages = $this->buildMessagesWithHistory(
                $chatId,
                new UserMessage(new Text($finalPrompt)),
                $this->getDocumentSummarySystemPrompt(),
                [],
            );
            $summary = trim((string) $agent->call(new MessageBag(...$summaryMessages))->getContent());

            if ($summary === '') {
                throw new \RuntimeException('La IA no devolvió el resumen consolidado del documento.');
            }

            return $summary;
        }

        $messages = $this->buildMessagesWithHistory(
            $chatId,
            new UserMessage(new Text($finalPrompt)),
            $this->getSystemPrompt()
                . "\n\n" . $this->getQuotationConsolidationSystemPrompt()
                . "\n\n" . $this->getQuotationDateInstruction(),
            $history,
        );

        $response = $agent->call(new MessageBag(...$messages));

        $content = $response->getContent();
        $contentArray = $this->normalizeStructuredContent($content);

        if (null === $contentArray) {
            throw new \RuntimeException('La respuesta de OpenAI no devolvio una estructura de cotizacion valida.');
        }

        $this->validateQuotationForHtml($contentArray['quotation']);
        $this->logger->info('[ChatToolPdfMessageProcessor] Iniciando generación de HTML (Paso 2).');
        $contentArray['html'] = $this->callOpenAiQuestionOnlyHtmlSkeleton(
            $contentArray['quotation'],
            $question,
            $this->getLatestQuotationHtml($chatId),
        );

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

    private function getLatestQuotationHtml(string $chatId): ?string
    {
        try {
            return $this->chatContextRetriever->getLatestContentBySessionAndRole($chatId, 'assistant_html');
        } catch (\Throwable $exception) {
            $this->logger->warning('[ChatToolPdfMessageProcessor] No fue posible recuperar el HTML previo desde Qdrant.', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function convertAndStorePdf(string $attachmentPath, ChatToolPdfMessage $message): string
    {
        $pdfBinary = $this->attachPdfStorage->read($attachmentPath);
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

            $this->chattoolpdfZipStorage->write($zipPath, $zipBinaryContent);

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

    private function dispatchResponse(ChatToolPdfMessage $message, ?string $attachmentPath = null, ?string $content = null, ?string $pdfUrl = null): void
    {
        $this->logger->info("[=================inicio==========================]");
        $resolvedContent = $content !== null && trim($content) !== '' ? $content : "no tenemos servicio en este momento";
        $publishedData = [
            'chat_id' => $message->getChatId(),
            'user_identifier' => $message->getUserIdentifier(),
            'content' => $resolvedContent,
            'pdf_url' => $pdfUrl,
            'mercure_topic' => $message->getMercureTopic(),
            'original_name_attachment' => $attachmentPath !== null ? basename($attachmentPath) : null,
            'attachment_path' => $message->getAttachmentKey(),
            'created_at' => $message->getCreatedAt()->format(
                \DateTimeInterface::ATOM,
            ),
        ];

        // Se imprime el payload completo justo antes de publicarlo en el bus.
        // Esto permite verificar exactamente qué datos recibe ChatToolIAPdfResponse.
        $this->logger->info('[ChatToolPdfMessageProcessor] Payload completo a publicar.', $publishedData);
        $consolePayload = json_encode($publishedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($consolePayload)) {
            fwrite(STDOUT, "[ChatToolPdfMessageProcessor] Payload completo a publicar: {$consolePayload}\n");
        }

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
        $this->logger->info("[=================fin==========================]");
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

        if ($quotation === null) {
            return null;
        }

        if ($message === '') {
            $message = self::DEFAULT_QUOTATION_MESSAGE;
            $this->logger->warning('[ChatToolPdfMessageProcessor] La respuesta estructurada no incluía message; se aplicó un valor predeterminado.');
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

        $normalized = [
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

        return $this->recalculateQuotationTotals($normalized);
    }

    /**
     * Recalcula únicamente valores matemáticos; las fechas siguen siendo
     * responsabilidad del modelo según la instrucción del usuario.
     *
     * @param array<string, mixed> $quotation
     * @return array<string, mixed>
     */
    private function recalculateQuotationTotals(array $quotation): array
    {
        $subtotal = 0.0;
        $discounts = 0.0;
        $taxes = 0.0;
        $total = 0.0;

        foreach ($quotation['items'] as $index => $item) {
            $quantity = max(0.0, (float) ($item['quantity'] ?? 0));
            $unitPrice = max(0.0, (float) ($item['unit_price'] ?? 0));
            $discountPercentage = min(100.0, max(0.0, (float) ($item['discount_percentage'] ?? 0)));
            $taxPercentage = min(100.0, max(0.0, (float) ($item['tax_percentage'] ?? 0)));
            $itemSubtotal = $quantity * $unitPrice;
            $itemDiscount = $itemSubtotal * ($discountPercentage / 100);
            $taxableBase = $itemSubtotal - $itemDiscount;
            $itemTaxes = $taxableBase * ($taxPercentage / 100);
            $itemTotal = $taxableBase + $itemTaxes;

            $quotation['items'][$index]['quantity'] = $quantity;
            $quotation['items'][$index]['unit_price'] = $unitPrice;
            $quotation['items'][$index]['discount_percentage'] = $discountPercentage;
            $quotation['items'][$index]['tax_percentage'] = $taxPercentage;
            $quotation['items'][$index]['subtotal'] = round($itemSubtotal, 2);
            $quotation['items'][$index]['total'] = round($itemTotal, 2);

            $subtotal += $itemSubtotal;
            $discounts += $itemDiscount;
            $taxes += $itemTaxes;
            $total += $itemTotal;
        }

        $quotation['subtotal'] = round($subtotal, 2);
        $quotation['discounts'] = round($discounts, 2);
        $quotation['taxes'] = round($taxes, 2);
        $quotation['total'] = round($total, 2);

        return $quotation;
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

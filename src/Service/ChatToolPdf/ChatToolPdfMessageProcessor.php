<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

use App\Entity\Loger;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use OSP\Message\AsistenteIA\ChatToolIAPdfResponse;
use OSP\Message\AsistenteIA\ChatToolPdfMessage;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
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
}.
6. El campo message debe resumir el resultado del análisis y la intención de la cotización.
7. El campo quotation no debe ser null. Si hay poca información, completa con estimaciones conservadoras y acláralo en notes.';
    private const DEFAULT_USER_QUESTION = 'Por favor, genérame una cotización a partir de este plano. Usa moneda COP como estándar, analiza cada detalle posible del plano y estima valores razonables si faltan datos.';

    public function __construct(
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
        private string $model,
        private string $stirlingEndpoint,
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

        if (!is_string($attachmentPath) || trim($attachmentPath) === '') {
            $aiContent = $this->answerQuestionOnly($message->getMessage());
            $this->dispatchResponse($message, null, $aiContent);
            return;
        }

        $aiContent = null;
        $attachmentPath = trim($attachmentPath);

        try {
            if (!$this->planosStorage->fileExists($attachmentPath)) {
                $this->logger->error('[ChatToolPdfMessageProcessor] El archivo no existe en storage.', [
                    'attachment_path' => $attachmentPath,
                    'chat_id' => $message->getChatId(),
                ]);

                $aiContent = $this->answerQuestionOnly($message->getMessage());
                $this->dispatchResponse($message, null, $aiContent);
                return;
            }

            $zipBinaryContent = $this->convertAndStorePdf($attachmentPath, $message);
            $aiContent = $this->analyzeZipBinary($zipBinaryContent, $message->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error('[ChatToolPdfMessageProcessor] No fue posible completar el flujo de IA.', [
                'attachment_path' => is_string($attachmentPath) ? $attachmentPath : null,
                'chat_id' => $message->getChatId(),
                'error' => $exception->getMessage(),
            ]);
        }

        $this->dispatchResponse($message, is_string($attachmentPath) && trim($attachmentPath) !== '' ? trim($attachmentPath) : null, $aiContent);
    }

    private function answerQuestionOnly(string $question): string
    {
        return $this->callOpenAiQuestionOnly($this->normalizeQuestion($question));
    }

    private function analyzeZipBinary(string $zipBinary, string $question): string
    {
        $imagePaths = $this->extractImagesToTempFiles($zipBinary);

        try {
            if ([] === $imagePaths) {
                return $this->answerQuestionOnly($question);
            }

            return $this->callOpenAiWithImages($this->normalizeQuestion($question), $imagePaths);
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
    private function callOpenAiQuestionOnly(string $question): string
    {
        $agent = new Agent($this->platform, $this->model);
        $message = new UserMessage(new Text($question !== '' ? $question : self::DEFAULT_USER_QUESTION));

        $response = $agent->call($message);
        $content = trim((string) $response->getContent());

        if ($content === '') {
            throw new \RuntimeException('La respuesta de OpenAI no devolvio contenido.');
        }

        $this->logger->info('[ChatToolPdfMessageProcessor] Respuesta de OpenAI sin adjuntos recibida.', [
            'content_preview' => mb_substr($content, 0, 1000),
        ]);

        return $content;
    }

    /**
     * @param array<int, string> $imagePaths
     */
    private function callOpenAiWithImages(string $question, array $imagePaths): string
    {
        $agent = new Agent($this->platform, $this->model);
        $messageParts = [new Text($question !== '' ? $question : self::DEFAULT_USER_QUESTION)];

        foreach ($imagePaths as $path) {
            // Pasamos la ruta física al componente en lugar del Base64 completo
            $messageParts[] = Image::fromFile($path);
        }

        $messages = new MessageBag(
            new SystemMessage(self::SYSTEM_PROMPT),
            new UserMessage(...$messageParts)
        );

        $response = $agent->call($messages);

        $content = $response->getContent();
        $contentArray = $this->normalizeStructuredContent($content);

        if (null === $contentArray) {
            throw new \RuntimeException('La respuesta de OpenAI no devolvio una estructura de cotizacion valida.');
        }

        $encoded = json_encode($contentArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || '' === $encoded) {
            throw new \RuntimeException('No fue posible serializar la respuesta estructurada de OpenAI.');
        }

        $this->logger->info('[ChatToolPdfMessageProcessor] Respuesta de OpenAI recibida.', [
            'content_preview' => mb_substr($encoded, 0, 1000),
            'has_quotation' => null !== $contentArray['quotation'],
        ]);

        return $encoded;
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

    private function dispatchResponse(ChatToolPdfMessage $message, ?string $attachmentPath, ?string $content = null): void
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
            pdfUrl: null,
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
        $normalized = preg_replace('/^```(?:json)?\s*/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*```$/', '', $normalized) ?? $normalized;

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

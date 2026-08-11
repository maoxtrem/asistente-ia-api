<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

use App\Adapter\OllamaImageAdapter;
use App\Entity\ChatHistoryPdf;
use App\Entity\ChatHistoryPdfImage;
use App\Entity\Loger;
use App\Service\Prompt\PromptLoader;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use OSP\Message\AsistenteIA\ChatToolIAPdfResponse;
use OSP\Message\AsistenteIA\ChatToolPdfMessage;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Message\AssistantMessage;
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
use Twig\Environment;

final readonly class ChatToolPdfMessageProcessor
{
    private const INTENT_CONVERSATION = 'conversation';
    private const INTENT_ANALYZE_DOCUMENT = 'analyze_document';
    private const INTENT_CREATE_DOCUMENT = 'create_document';
    private const INTENT_EDIT_DOCUMENT = 'edit_document';
    private const INTENT_NO_UNDERSTAND_QUESTION = 'no_understand_question';

    public function __construct(
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
        #[Autowire('%app.chattoolpdf_model%')]
        private string $model,
        #[Autowire('%app.chattoolpdf_image_dpi%')]
        private int $imageDpi,
        #[Autowire('%app.chattoolpdf_image_reevaluation_index%')]
        private int $imageReevaluationIndex,
        #[Autowire('%app.gotenberg_endpoint%')]
        private string $gotenbergEndpoint,
        #[Autowire('%app.stirling_pdf_endpoint%')]
        private string $stirlingEndpoint,
        #[Autowire(service: 'ai.platform.ollama')]
        private PlatformInterface $platform,
        private PromptLoader $promptLoader,
        #[Autowire(service: 'chattoolpdf.storage.attach_pdf')]
        private FilesystemOperator $attachPdfStorage,
        #[Autowire(service: 'chattoolpdf.storage.zip')]
        private FilesystemOperator $chattoolpdfZipStorage,
        private Environment $twig,
        private OllamaImageAdapter $ollamaImageAdapter,
    ) {}

    // Flujo principal: recibe el mensaje, obtiene la intención y deriva la ejecución.
    public function process(ChatToolPdfMessage $message): void
    {
        $question = $this->normalizeQuestion($message->getMessage());
        $attachmentKey = trim((string) $message->getAttachmentKey());
        $hasNewAttachment = $attachmentKey !== '';

        // 1. Obtener historial ANTES de clasificar para inyectar contexto a la IA
        $historyRecords = $this->getRecentHistory($message->getChatId(), $message->getUserIdentifier(), 20);

        // 2. Clasificar intención pasando el historial
        $intent = $this->classifyIntent($question, $historyRecords);
        $this->persistUserMessage($message, $intent);

        // 3. Determinar el estado real de los adjuntos (Nuevos o en el historial)
        $activeAttachmentKey = $hasNewAttachment
            ? $attachmentKey
            : $this->findLatestAttachmentKey(
                $message->getChatId(),
                $message->getUserIdentifier(),
            );
        $hasActiveDocument = $activeAttachmentKey !== null;

        // =================================================================
        // MATRIZ DE DECISIÓN ESTRICTA E INTERCEPCIONES (Limpia)
        // =================================================================

        // 1. Alucinación de adjunto (Pide analizar pero no sube nada ni hay historial)
        if ($intent === self::INTENT_ANALYZE_DOCUMENT && !$hasActiveDocument) {
            $this->dispatchResponse($message, 'Solicitas un análisis, pero no hay ningún documento activo. Súbelo para continuar.');
            return;
        }

        #// 2. Edición fantasma (Pide editar pero no hay documento previo)
        #if ($intent === self::INTENT_EDIT_DOCUMENT && !$hasActiveDocument) {
        #    $this->dispatchResponse($message, 'No encuentro un documento en esta conversación para editar.');
        #    return;
        #}

        // 3. Choque de contextos (Pide editar un documento viejo, pero sube uno nuevo)
        #if ($intent === self::INTENT_EDIT_DOCUMENT && $hasNewAttachment) {
        #    $intent = self::INTENT_ANALYZE_DOCUMENT;
        #}

        // 4. Saludo mudo con archivo (Sube un archivo nuevo y la intención es nula o cháchara)
        if (in_array($intent, [self::INTENT_CONVERSATION, self::INTENT_NO_UNDERSTAND_QUESTION], true) && $hasNewAttachment) {
            $intent = self::INTENT_ANALYZE_DOCUMENT;
        }

        // NOTA: Se ha eliminado el bloqueo sobre INTENT_CREATE_DOCUMENT.
        // Si el LLM decide crear porque hay contexto de texto, se respeta la decisión.

        // =================================================================
        // EJECUCIÓN VALIDADA
        // =================================================================
               $this->logger->warning('[EJECUCIÓN VALIDADA] Respuesta de la ia intenciones', [
                   $intent
                ]);
        match ($intent) {
            self::INTENT_ANALYZE_DOCUMENT => $this->analyzeDocument($message, $activeAttachmentKey),
            self::INTENT_CREATE_DOCUMENT  => $this->createDocument($message),
            self::INTENT_EDIT_DOCUMENT    => $this->editDocument($message),
            self::INTENT_CONVERSATION,
            self::INTENT_NO_UNDERSTAND_QUESTION => $this->conversation($message),
            default => $this->conversation($message),
        };
    }

    private function analyzeDocument(ChatToolPdfMessage $message, string $attachmentKey): void
    {
        $attachmentZipKey = $this->convertAndStoreAttachmentZip($message, $attachmentKey);

        if ($attachmentZipKey === null) {
            $this->dispatchResponse($message, "analizare el documento");
            return;
        }

        $images = $this->extractAndProcessImages($message, $attachmentZipKey);
        foreach ($images as $image) {
            $this->processImageWithAi($message, $image);
        }

        $this->dispatchResponse($message, "analizare el documento");
    }

    private function convertAndStoreAttachmentZip(
        ChatToolPdfMessage $message,
        string $attachmentKey,
    ): ?string {
        $history = $this->entityManager->getRepository(ChatHistoryPdf::class)
            ->createQueryBuilder('history')
            ->where('history.chatId = :chatId')
            ->andWhere('history.userIdentifier = :userIdentifier')
            ->andWhere('history.attachmentKey = :attachmentKey')
            ->setParameter('chatId', $message->getChatId())
            ->setParameter('userIdentifier', $message->getUserIdentifier())
            ->setParameter('attachmentKey', $attachmentKey)
            ->orderBy('history.createdAt', 'DESC')
            ->addOrderBy('history.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$history instanceof ChatHistoryPdf) {
            return null;
        }

        $attachmentZipKey = trim((string) $history->getAttachmentZipKey());

        if ($attachmentZipKey !== '') {
            $this->logger->info('[ChatToolPdfMessageProcessor] Se reutiliza el ZIP existente del adjunto.', [
                'attachment_key' => $attachmentKey,
                'attachment_zip_key' => $attachmentZipKey,
                'chat_id' => $message->getChatId(),
            ]);

            return $attachmentZipKey;
        }

        $pdfBinary = $this->attachPdfStorage->read($attachmentKey);
        $tempPdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pdf_to_convert_', true) . '.pdf';

        if (file_put_contents($tempPdfPath, $pdfBinary) === false) {
            throw new \RuntimeException('No fue posible escribir el PDF temporal en el disco.');
        }

        try {
            $formData = new FormDataPart([
                'fileInput' => DataPart::fromPath($tempPdfPath, 'documento.pdf', 'application/pdf'),
                'pageNumbers' => 'all',
                'imageFormat' => 'jpeg',
                'singleOrMultiple' => 'multiple',
                'colorType' => 'color',
                'dpi' => (string) $this->imageDpi,
            ]);
            $response = $this->httpClient->request('POST', $this->stirlingEndpoint, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf(
                    'Stirling devolvió status %d: %s',
                    $response->getStatusCode(),
                    $response->getContent(false),
                ));
            }

            $zipPath = preg_replace('/\.pdf$/i', '', $attachmentKey) . '.stirling.zip';
            if (!is_string($zipPath) || $zipPath === '') {
                throw new \RuntimeException('No fue posible generar la clave del ZIP.');
            }

            $this->chattoolpdfZipStorage->write($zipPath, $response->getContent());

            $history->setAttachmentZipKey($zipPath);
            $this->entityManager->persist($history);

            $loger = new Loger($zipPath, $message->getCreatedAt());
            $this->entityManager->persist($loger);
            $this->entityManager->flush();

            $this->logger->info('[ChatToolPdfMessageProcessor] PDF convertido con Stirling y ZIP guardado.', [
                'attachment_key' => $attachmentKey,
                'attachment_zip_key' => $zipPath,
                'chat_id' => $message->getChatId(),
            ]);
        } finally {
            if (file_exists($tempPdfPath)) {
                unlink($tempPdfPath);
            }
        }

        return $zipPath;
    }

    /**
     * @return list<ChatHistoryPdfImage>
     */
    private function extractAndProcessImages(
        ChatToolPdfMessage $message,
        string $attachmentZipKey,
    ): array {
        $history = $this->findHistoryByAttachmentZipKey($message, $attachmentZipKey);
        $storedImages = $this->findStoredImages($history);
        $zipImageCount = $this->countImagesInZip($attachmentZipKey);

        if ($this->areStoredImagesAvailable($storedImages, $zipImageCount)) {
            $this->logger->info('[ChatToolPdfMessageProcessor] Se reutilizan las imágenes ya extraídas del ZIP.', [
                'chat_id' => $message->getChatId(),
                'attachment_zip_key' => $attachmentZipKey,
                'stored_images' => count($storedImages),
                'zip_images' => $zipImageCount,
            ]);

            return $storedImages;
        }

        $this->extractImagesFromZip($message, $history, $attachmentZipKey);

        return $this->findStoredImages($history);
    }

    private function findHistoryByAttachmentZipKey(
        ChatToolPdfMessage $message,
        string $attachmentZipKey,
    ): ChatHistoryPdf {
        $history = $this->entityManager->getRepository(ChatHistoryPdf::class)
            ->createQueryBuilder('history')
            ->where('history.chatId = :chatId')
            ->andWhere('history.userIdentifier = :userIdentifier')
            ->andWhere('history.attachmentZipKey = :attachmentZipKey')
            ->setParameter('chatId', $message->getChatId())
            ->setParameter('userIdentifier', $message->getUserIdentifier())
            ->setParameter('attachmentZipKey', $attachmentZipKey)
            ->orderBy('history.createdAt', 'DESC')
            ->addOrderBy('history.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$history instanceof ChatHistoryPdf) {
            throw new \RuntimeException('No se encontró el historial asociado al ZIP de imágenes.');
        }

        return $history;
    }

    /**
     * @return list<ChatHistoryPdfImage>
     */
    private function findStoredImages(ChatHistoryPdf $history): array
    {
        return $this->entityManager->getRepository(ChatHistoryPdfImage::class)->findBy(
            ['chatHistoryPdf' => $history],
            ['imageNumber' => 'ASC'],
        );
    }

    /**
     * @param list<ChatHistoryPdfImage> $storedImages
     */
    private function areStoredImagesAvailable(
        array $storedImages,
        ?int $zipImageCount,
    ): bool
    {
        if ($storedImages === []) {
            return false;
        }

        if ($zipImageCount !== null && count($storedImages) !== $zipImageCount) {
            return false;
        }

        $expectedImageNumber = 1;
        foreach ($storedImages as $storedImage) {
            if (
                $storedImage->getImageNumber() !== $expectedImageNumber
                ||
                trim($storedImage->getImageKey()) === ''
                || !$this->chattoolpdfZipStorage->fileExists($storedImage->getImageKey())
            ) {
                return false;
            }

            ++$expectedImageNumber;
        }

        return true;
    }

    private function countImagesInZip(string $attachmentZipKey): ?int
    {
        if (!$this->chattoolpdfZipStorage->fileExists($attachmentZipKey)) {
            return null;
        }

        $zipBinary = $this->chattoolpdfZipStorage->read($attachmentZipKey);
        if ($zipBinary === '') {
            return null;
        }

        $tempZipPath = tempnam(sys_get_temp_dir(), 'chattoolpdf_zip_count_');
        if ($tempZipPath === false) {
            throw new \RuntimeException('No fue posible crear el archivo temporal para validar el ZIP.');
        }

        if (file_put_contents($tempZipPath, $zipBinary) === false) {
            unlink($tempZipPath);
            throw new \RuntimeException('No fue posible guardar el ZIP temporal para validarlo.');
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tempZipPath) !== true) {
                throw new \RuntimeException('No fue posible abrir el ZIP para contar sus imágenes.');
            }

            $imageCount = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                if ($this->isImageEntryName($zip->getNameIndex($index))) {
                    ++$imageCount;
                }
            }

            $zip->close();

            return $imageCount;
        } finally {
            if (file_exists($tempZipPath)) {
                unlink($tempZipPath);
            }
        }
    }

    private function isImageEntryName(mixed $entryName): bool
    {
        return is_string($entryName)
            && !str_ends_with($entryName, '/')
            && preg_match('/\.(jpe?g|png|webp)$/i', $entryName) === 1;
    }

    private function extractImagesFromZip(
        ChatToolPdfMessage $message,
        ChatHistoryPdf $history,
        string $attachmentZipKey,
    ): void {
        $imageDirectory = preg_replace('/\.zip$/i', '.images', $attachmentZipKey);
        if (!is_string($imageDirectory) || $imageDirectory === '') {
            throw new \RuntimeException('No fue posible generar la carpeta de imágenes.');
        }

        $zipBinary = $this->chattoolpdfZipStorage->read($attachmentZipKey);
        if ($zipBinary === '') {
            return;
        }

        $tempZipPath = tempnam(sys_get_temp_dir(), 'chattoolpdf_zip_');
        if ($tempZipPath === false) {
            throw new \RuntimeException('No fue posible crear el archivo temporal del ZIP.');
        }

        if (file_put_contents($tempZipPath, $zipBinary) === false) {
            unlink($tempZipPath);
            throw new \RuntimeException('No fue posible guardar el ZIP temporal.');
        }

        $imagePaths = [];
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tempZipPath) !== true) {
                throw new \RuntimeException('No fue posible abrir el ZIP de imágenes.');
            }

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $entryName = $zip->getNameIndex($index);
                if (!$this->isImageEntryName($entryName)) {
                    continue;
                }

                $imageBinary = $zip->getFromIndex($index);
                if ($imageBinary === false || $imageBinary === '') {
                    continue;
                }

                $extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                $imageNumber = count($imagePaths) + 1;
                $imageName = basename($entryName);
                $imageFileName = sprintf('%04d_%s', $imageNumber, $imageName);
                $imageKey = $imageDirectory . '/' . $imageFileName;
                $mimeType = match ($extension) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    default => 'application/octet-stream',
                };

                $imagePath = tempnam(sys_get_temp_dir(), 'chattoolpdf_image_');
                if ($imagePath === false) {
                    continue;
                }

                unlink($imagePath);
                $imagePath .= $extension !== '' ? '.' . $extension : '';

                if (file_put_contents($imagePath, $imageBinary) === false) {
                    unlink($imagePath);
                    continue;
                }

                $imagePaths[] = $imagePath;

                $this->processImage(
                    $message,
                    $history,
                    $imagePath,
                    $imageBinary,
                    $imageKey,
                    $imageName,
                    $imageNumber,
                    $mimeType,
                );
            }

            $zip->close();
            $this->entityManager->flush();
        } finally {
            if (file_exists($tempZipPath)) {
                unlink($tempZipPath);
            }

            foreach ($imagePaths as $imagePath) {
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
        }
    }

    private function processImageWithAi(
        ChatToolPdfMessage $message,
        ChatHistoryPdfImage $image,
    ): void {
        $approved = $image->getApproved();

        if ($approved === null) {
            $approved = $this->runPreliminaryImageReview($message, $image);
            if ($approved === null) {
                return;
            }
        }

        if (!$approved) {
            return;
        }

        if ($image->getContextGeneralAnalyzed() !== true) {
            if (!$this->runContextGeneralExtraction($message, $image)) {
                return;
            }
        }

        if ($image->getContextGeneralAnalyzed() !== true) {
            return;
        }

        if ($image->getMaterialsSystemsAnalyzed() !== true) {
            if (!$this->runMaterialsSystemsExtraction($message, $image)) {
                return;
            }
        }

        if ($image->getMaterialsSystemsAnalyzed() !== true) {
            return;
        }

        if ($image->getGeometryQuantitiesAnalyzed() !== true) {
            if (!$this->runGeometryQuantitiesExtraction($message, $image)) {
                return;
            }
        }
    }

    private function runContextGeneralExtraction(
        ChatToolPdfMessage $message,
        ChatHistoryPdfImage $image,
    ): bool {
        $tempImagePath = null;

        try {
            $this->logger->info('[ChatToolPdfMessageProcessor] Iniciando extracción de contexto general.', $this->imageLogContext($message, $image));
            $imageBinary = $this->chattoolpdfZipStorage->read($image->getImageKey());
            if ($imageBinary === '') {
                throw new \RuntimeException('No fue posible leer la imagen para extraer el contexto general.');
            }

            $extension = strtolower(pathinfo($image->getImageName(), PATHINFO_EXTENSION));
            $tempImagePath = tempnam(sys_get_temp_dir(), 'chattoolpdf_context_');
            if ($tempImagePath === false) {
                throw new \RuntimeException('No fue posible crear el archivo temporal para extraer el contexto general.');
            }

            unlink($tempImagePath);
            $tempImagePath .= $extension !== '' ? '.' . $extension : '';

            if (file_put_contents($tempImagePath, $imageBinary) === false) {
                throw new \RuntimeException('No fue posible guardar la imagen temporal para extraer el contexto general.');
            }

            $reasoning = trim((string) $image->getReasoning());
            $userPrompt = 'Extrae los textos, notas y el contexto general de esta imagen.';
            if ($reasoning !== '') {
                $userPrompt .= sprintf(
                    "\n\nContexto de la revisión preliminar de esta imagen:\n%s",
                    $reasoning,
                );
            }

            $responseContent = $this->analyzeImageWithAi(
                $this->loadPrompt('image_context_general_system_prompt.md'),
                $userPrompt,
                $tempImagePath,
                $imageBinary,
            );
            $contextGeneralJson = $this->decodeJsonContent($responseContent);

            if (!is_array($contextGeneralJson)) {
                throw new \RuntimeException(sprintf(
                    'La IA no devolvió un JSON válido para el contexto general. Error JSON: %s. Longitud: %d. Respuesta: %s',
                    json_last_error_msg(),
                    strlen($responseContent),
                    $this->truncateAiResponse($responseContent),
                ));
            }

            $image->setContextGeneralAnalyzed(true);
            $image->setContextGeneraJson($contextGeneralJson);
            $this->entityManager->persist($image);
            $this->entityManager->flush();

            $this->logger->info('[ChatToolPdfMessageProcessor] Extracción de contexto general completada.', $this->imageLogContext($message, $image));
            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('[ChatToolPdfMessageProcessor] No fue posible extraer el contexto general de la imagen.', $this->imageLogContext($message, $image) + [
                'error' => $exception->getMessage(),
            ]);
            return false;
        } finally {
            if (is_string($tempImagePath) && file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }
        }
    }

    private function runMaterialsSystemsExtraction(
        ChatToolPdfMessage $message,
        ChatHistoryPdfImage $image,
    ): bool {
        $tempImagePath = null;

        try {
            $this->logger->info('[ChatToolPdfMessageProcessor] Iniciando extracción de materiales y sistemas constructivos.', $this->imageLogContext($message, $image));
            $contextGeneralJson = $image->getContextGeneraJson();
            if ($contextGeneralJson === null) {
                throw new \RuntimeException('La imagen no tiene contexto general para iniciar la fase 2.');
            }

            $imageBinary = $this->chattoolpdfZipStorage->read($image->getImageKey());
            if ($imageBinary === '') {
                throw new \RuntimeException('No fue posible leer la imagen para extraer materiales y sistemas constructivos.');
            }

            $extension = strtolower(pathinfo($image->getImageName(), PATHINFO_EXTENSION));
            $tempImagePath = tempnam(sys_get_temp_dir(), 'chattoolpdf_materials_');
            if ($tempImagePath === false) {
                throw new \RuntimeException('No fue posible crear el archivo temporal para la fase 2.');
            }

            unlink($tempImagePath);
            $tempImagePath .= $extension !== '' ? '.' . $extension : '';

            if (file_put_contents($tempImagePath, $imageBinary) === false) {
                throw new \RuntimeException('No fue posible guardar la imagen temporal para la fase 2.');
            }

            $encodedContextGeneral = json_encode(
                $contextGeneralJson,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            if (!is_string($encodedContextGeneral)) {
                throw new \RuntimeException('No fue posible serializar el contexto general de la fase 1.');
            }

            $responseContent = $this->analyzeImageWithAi(
                $this->loadPrompt('image_materials_systems_system_prompt.md'),
                "Extrae los materiales y sistemas constructivos de esta imagen.\n\nContexto general de la fase 1:\n{$encodedContextGeneral}",
                $tempImagePath,
                $imageBinary,
            );
            $materialsSystemsJson = $this->decodeJsonContent($responseContent);

            if (!is_array($materialsSystemsJson)) {
                throw new \RuntimeException(sprintf(
                    'La IA no devolvió un JSON válido para materiales y sistemas constructivos. Error JSON: %s. Longitud: %d. Respuesta: %s',
                    json_last_error_msg(),
                    strlen($responseContent),
                    $this->truncateAiResponse($responseContent),
                ));
            }

            $image->setMaterialsSystemsJson($materialsSystemsJson);
            $image->setMaterialsSystemsAnalyzed(true);
            $this->entityManager->persist($image);
            $this->entityManager->flush();

            $this->logger->info('[ChatToolPdfMessageProcessor] Extracción de materiales y sistemas constructivos completada.', $this->imageLogContext($message, $image));
            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('[ChatToolPdfMessageProcessor] No fue posible extraer materiales y sistemas constructivos de la imagen.', $this->imageLogContext($message, $image) + [
                'error' => $exception->getMessage(),
            ]);
            return false;
        } finally {
            if (is_string($tempImagePath) && file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }
        }
    }

    private function runGeometryQuantitiesExtraction(
        ChatToolPdfMessage $message,
        ChatHistoryPdfImage $image,
    ): bool {
        $tempImagePath = null;

        try {
            $this->logger->info('[ChatToolPdfMessageProcessor] Iniciando fase geométrica, metrados y síntesis final.', $this->imageLogContext($message, $image));
            $contextGeneralJson = $image->getContextGeneraJson();
            $materialsSystemsJson = $image->getMaterialsSystemsJson();
            if ($contextGeneralJson === null || $materialsSystemsJson === null) {
                throw new \RuntimeException('La imagen no tiene los resultados de las fases anteriores para iniciar la fase 3.');
            }

            $imageBinary = $this->chattoolpdfZipStorage->read($image->getImageKey());
            if ($imageBinary === '') {
                throw new \RuntimeException('No fue posible leer la imagen para la fase geométrica.');
            }

            $extension = strtolower(pathinfo($image->getImageName(), PATHINFO_EXTENSION));
            $tempImagePath = tempnam(sys_get_temp_dir(), 'chattoolpdf_geometry_');
            if ($tempImagePath === false) {
                throw new \RuntimeException('No fue posible crear el archivo temporal para la fase 3.');
            }

            unlink($tempImagePath);
            $tempImagePath .= $extension !== '' ? '.' . $extension : '';

            if (file_put_contents($tempImagePath, $imageBinary) === false) {
                throw new \RuntimeException('No fue posible guardar la imagen temporal para la fase 3.');
            }

            $phaseContext = json_encode([
                'context_general' => $contextGeneralJson,
                'materials_systems' => $materialsSystemsJson,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($phaseContext)) {
                throw new \RuntimeException('No fue posible serializar el contexto de las fases anteriores.');
            }

            $responseContent = $this->analyzeImageWithAi(
                $this->loadPrompt('image_geometric_quantities_system_prompt.md'),
                "Realiza la extracción geométrica, los metrados y la síntesis final de esta imagen.\n\nContexto de las fases anteriores:\n{$phaseContext}",
                $tempImagePath,
                $imageBinary,
            );
            $geometryQuantitiesJson = $this->decodeJsonContent($responseContent);

            if (!is_array($geometryQuantitiesJson)) {
                throw new \RuntimeException(sprintf(
                    'La IA no devolvió un JSON válido para geometría, metrados y síntesis final. Error JSON: %s. Longitud: %d. Respuesta: %s',
                    json_last_error_msg(),
                    strlen($responseContent),
                    $this->truncateAiResponse($responseContent),
                ));
            }

            $image->setGeometryQuantitiesJson($geometryQuantitiesJson);
            $image->setGeometryQuantitiesAnalyzed(true);
            $this->entityManager->persist($image);
            $this->entityManager->flush();

            $this->logger->info('[ChatToolPdfMessageProcessor] Fase geométrica, metrados y síntesis final completada.', $this->imageLogContext($message, $image));
            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('[ChatToolPdfMessageProcessor] No fue posible completar la fase geométrica de la imagen.', $this->imageLogContext($message, $image) + [
                'error' => $exception->getMessage(),
            ]);
            return false;
        } finally {
            if (is_string($tempImagePath) && file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }
        }
    }

    private function runPreliminaryImageReview(
        ChatToolPdfMessage $message,
        ChatHistoryPdfImage $image,
    ): ?bool {
        $tempImagePath = null;
        try {
            $this->logger->info('[ChatToolPdfMessageProcessor] Iniciando revisión preliminar.', $this->imageLogContext($message, $image));
            $imageBinary = $this->chattoolpdfZipStorage->read($image->getImageKey());
            if ($imageBinary === '') {
                throw new \RuntimeException('No fue posible leer la imagen para la revisión preliminar.');
            }

            $extension = strtolower(pathinfo($image->getImageName(), PATHINFO_EXTENSION));
            $tempImagePath = tempnam(sys_get_temp_dir(), 'chattoolpdf_review_');
            if ($tempImagePath === false) {
                throw new \RuntimeException('No fue posible crear el archivo temporal para revisar la imagen.');
            }

            unlink($tempImagePath);
            $tempImagePath .= $extension !== '' ? '.' . $extension : '';

            if (file_put_contents($tempImagePath, $imageBinary) === false) {
                throw new \RuntimeException('No fue posible guardar la imagen temporal para revisarla.');
            }

            $systemPrompt = $this->loadPrompt('image_preliminary_review_system_prompt.md');
            $userPrompt = 'Realiza la revisión preliminar de esta imagen.';

            $responseContent = $this->analyzeImageWithAi(
                $systemPrompt,
                $userPrompt,
                $tempImagePath,
                $imageBinary,
            );

            $decodedResponse = $this->decodeJsonContent($responseContent);
            $confidenceScore = is_array($decodedResponse)
                ? ($decodedResponse['confidence_score'] ?? null)
                : null;
            $reasoning = is_array($decodedResponse)
                ? ($decodedResponse['reasoning'] ?? null)
                : null;
            $documentType = is_array($decodedResponse)
                ? ($decodedResponse['document_type'] ?? null)
                : null;

            if (
                !is_array($decodedResponse)
                || !is_bool($decodedResponse['approved'] ?? null)
                || !is_int($confidenceScore)
                || $confidenceScore < 0
                || $confidenceScore > 10
                || !is_string($reasoning)
                || trim($reasoning) === ''
                || ($documentType !== null && !in_array($documentType, ['plano', 'financiero'], true))
            ) {
                throw new \RuntimeException('La IA no devolvió una revisión preliminar válida.');
            }

            $image->setApproved($decodedResponse['approved']);
            $image->setDocumentType($documentType);
            $image->setConfidenceScore($confidenceScore);
            $image->setReasoning(trim($reasoning));
            $this->entityManager->persist($image);
            $this->entityManager->flush();

            $this->logger->info('[ChatToolPdfMessageProcessor] Revisión preliminar completada.', $this->imageLogContext($message, $image) + [
                'approved' => $decodedResponse['approved'],
                'document_type' => $documentType,
            ]);
            return $decodedResponse['approved'];
        } catch (\Throwable $exception) {
            $this->logger->warning('[ChatToolPdfMessageProcessor] No fue posible completar la revisión preliminar de la imagen.', $this->imageLogContext($message, $image) + [
                'error' => $exception->getMessage(),
            ]);
            return null;
        } finally {
            if (is_string($tempImagePath) && file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }
        }
    }

    /**
     * @return array{chat_id: string, image_key: string, image_number: int}
     */
    private function imageLogContext(
        ChatToolPdfMessage $message,
        ChatHistoryPdfImage $image,
    ): array {
        return [
            'chat_id' => $message->getChatId(),
            'image_key' => $image->getImageKey(),
            'image_number' => $image->getImageNumber(),
        ];
    }

    private function analyzeImageWithAi(
        string $systemPrompt,
        string $userPrompt,
        ?string $imagePath,
        string $imageBinary,
    ): string {
        // Ollama es la ruta activa para las pruebas actuales.
        return $this->ollamaImageAdapter->analyzeImageWithOllama(
            $systemPrompt,
            $userPrompt,
            $imageBinary,
        );

        // Para usar OpenAI, comenta el bloque anterior y habilita esta línea:
        // return $this->analyzeImageWithAgent($systemPrompt, $userPrompt, (string) $imagePath);
    }

    private function analyzeImageWithAgent(
        string $systemPrompt,
        string $userPrompt,
        string $imagePath,
    ): string {
        $agent = new Agent($this->platform, $this->model);
        $response = $agent->call(new MessageBag(
            new SystemMessage($systemPrompt),
            new UserMessage(
                new Text($userPrompt),
                Image::fromFile($imagePath),
            ),
        ));

        return (string) $response->getContent();
    }

    private function processImage(
        ChatToolPdfMessage $message,
        ChatHistoryPdf $history,
        string $imagePath,
        string $imageBinary,
        string $imageKey,
        string $imageName,
        int $imageNumber,
        string $mimeType,
    ): void {
        $imageRepository = $this->entityManager->getRepository(ChatHistoryPdfImage::class);

        $storedImage = $imageRepository->findOneBy([
            'chatHistoryPdf' => $history,
            'imageKey' => $imageKey,
        ]);

        if (
            $storedImage === null
            || !$this->chattoolpdfZipStorage->fileExists($imageKey)
        ) {
            $this->chattoolpdfZipStorage->write($imageKey, $imageBinary);

            if ($storedImage === null) {
                $this->entityManager->persist(new ChatHistoryPdfImage(
                    chatHistoryPdf: $history,
                    imageKey: $imageKey,
                    imageName: $imageName,
                    imageNumber: $imageNumber,
                    mimeType: $mimeType,
                ));
            }
        }

        $this->logger->info('[ChatToolPdfMessageProcessor] Imagen lista para procesar.', [
            'chat_id' => $message->getChatId(),
            'image_number' => $imageNumber,
            'image_name' => $imageName,
            'image_key' => $imageKey,
            'image_path' => $imagePath,
        ]);
    }

    private function createDocument(ChatToolPdfMessage $message): void
    {
        // 1. Obtener historial reciente para dar contexto de los datos (productos, precios, etc.)
        $historyRecords = $this->getRecentHistory($message->getChatId(), $message->getUserIdentifier(), 12);

        // 2. Cargar el prompt de sistema estricto
        $messages = [new SystemMessage($this->loadPrompt('content_json_system_prompt.md'))];

        // 3. Reconstruir el historial de la conversación
        foreach ($historyRecords as $record) {
            if ($record->getRecordType() === 'user') {
                $messages[] = new UserMessage(new Text($record->getMessage() ?? ''));
                continue;
            }

            $assistantContent = $record->getContent();
            #if ($record->getContentJson() !== null) {
            #    // Si hubo un JSON previo, se inyecta para que la IA recuerde el estado de la cotización
            #    $encodedJson = json_encode($record->getContentJson(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            #    $assistantContent = is_string($encodedJson) ? $encodedJson : $assistantContent;
            #}

            if ($assistantContent !== null && trim($assistantContent) !== '') {
                $messages[] = new AssistantMessage(new Text($assistantContent));
            }
        }

        // 4. IMPORTANTE: Agregar el mensaje actual del usuario al final del hilo
        $messages[] = new UserMessage(new Text($message->getMessage()));

        try {
            // 5. Llamada al LLM
            $agent = new Agent($this->platform, $this->model);
            $response = $agent->call(new MessageBag(...$messages));
            $rawResponse = trim((string) $response->getContent());
                $this->logger->warning('[ChatToolPdfMessageProcessor] Respuesta pura de la ia', [
                    $rawResponse,
                    $messages
                ]);
            // 6. Decodificar la respuesta estructurada
            $decodedResponse = $this->decodeJsonContent($rawResponse);

            if (!is_array($decodedResponse)) {
                $this->logger->warning('[ChatToolPdfMessageProcessor] La IA no devolvió un JSON válido en createDocument.', [
                    'raw_response' => $rawResponse
                ]);
                $this->dispatchResponse($message, $rawResponse);
                return;
            }

            // 7. Extraer las claves definidas en el prompt
            $content = trim((string) ($decodedResponse['content'] ?? ''));
            $accionRequerida = $decodedResponse['accion_requerida'] ?? 'request_data';
            $contentJson = is_array($decodedResponse['content_json'] ?? null)
                ? $decodedResponse['content_json']
                : null;

            // Si la IA determina que falta información, contentJson será null y solo se despachará el mensaje de texto.
            // Si la acción es render_pdf, contentJson contendrá la estructura completa de la cotización.
            $pdfUrl = null;
            if ($accionRequerida === 'render_pdf' && $contentJson !== null) {
                $pdfUrl = $this->renderAndStoreQuotationPdf(
                    $contentJson,
                    $message->getChatId(),
                );
            }

            $this->dispatchResponse(
                message: $message,
                content: $content !== '' ? $content : 'Generando documento...',
                contentJson: $contentJson,
                pdfUrl: $pdfUrl,
            );
        } catch (\Throwable $exception) {
            $this->logger->error('[ChatToolPdfMessageProcessor] Error al crear el documento.', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $this->dispatchResponse($message, 'Ocurrió un error interno al intentar estructurar el documento.');
        }
    }

    /**
     * Renderiza la cotización con la plantilla fija, la convierte a PDF con
     * Gotenberg, la guarda en MinIO y devuelve la clave del objeto generado.
     *
     * @param array<string, mixed> $contentJson
     */
    private function renderAndStoreQuotationPdf(array $contentJson, string $chatId): string
    {
        $html = trim($this->twig->render('plantillaCotizacionPdf.html.twig', $contentJson));
        if ($html === '') {
            throw new \RuntimeException('La plantilla de cotización no devolvió HTML.');
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

            $pdfPath = bin2hex(random_bytes(32)) . '.pdf';
            $this->attachPdfStorage->write(
                $pdfPath,
                $response->getContent(),
                ['visibility' => 'public'],
            );

            $this->logger->info('[ChatToolPdfMessageProcessor] PDF de cotización generado y guardado en MinIO.', [
                'chat_id' => $chatId,
                'pdf_key' => $pdfPath,
            ]);

            return $pdfPath;
        } finally {
            if (file_exists($tempHtmlPath)) {
                unlink($tempHtmlPath);
            }
        }
    }

    private function editDocument(ChatToolPdfMessage $message): void
    {
        // 1. Buscar ÚNICAMENTE el último estado válido del documento (el último JSON generado)
        $latestJson = $this->findLatestContentJson($message->getChatId(), $message->getUserIdentifier());

        if ($latestJson === null) {
            $this->dispatchResponse($message, 'esto se debe de actualizar No encontré una cotización activa para editar. Por favor, crea una primero.');
            return;
        }

        // 2. Preparar el contexto preciso y sin redundancias
        $messages = [
            // A. Reglas de negocio
            new SystemMessage($this->loadPrompt('edit_content_json_system_prompt.md')),

            // B. La fuente de verdad absoluta (El último JSON tal cual está en DB)
            new AssistantMessage(new Text(json_encode($latestJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),

            // C. Lo que el usuario quiere cambiar
            new UserMessage(new Text($message->getMessage()))
        ];

        try {
            // 3. Llamada al LLM
            $agent = new Agent($this->platform, $this->model);
            $response = $agent->call(new MessageBag(...$messages));
            $rawResponse = trim((string) $response->getContent());

            $decodedResponse = $this->decodeJsonContent($rawResponse);

            if (!is_array($decodedResponse)) {
                $this->logger->warning('[ChatToolPdfMessageProcessor] La IA no devolvió un JSON válido en editDocument.', [
                    'raw_response' => $rawResponse
                ]);
                $this->dispatchResponse($message, 'No pude procesar la edición correctamente. Intenta de nuevo.');
                return;
            }

            // 4. Extracción
            $content = trim((string) ($decodedResponse['content'] ?? ''));
            $accionRequerida = $decodedResponse['accion_requerida'] ?? 'request_data';
            $contentJson = is_array($decodedResponse['content_json'] ?? null)
                ? $decodedResponse['content_json']
                : null;
            $pdfUrl = null;

            if ($accionRequerida === 'render_pdf' && $contentJson !== null) {
                $pdfUrl = $this->renderAndStoreQuotationPdf(
                    $contentJson,
                    $message->getChatId(),
                );
            }

            $this->dispatchResponse(
                message: $message,
                content: $content !== '' ? $content : 'Cotización actualizada.',
                contentJson: $contentJson,
                pdfUrl: $pdfUrl,
            );
        } catch (\Throwable $exception) {
            $this->logger->error('[ChatToolPdfMessageProcessor] Error al editar el documento.', [
                'error' => $exception->getMessage(),
            ]);
            $this->dispatchResponse($message, 'Ocurrió un error interno al intentar editar la cotización.');
        }
    }

    private function conversation(ChatToolPdfMessage $message): void
    {
        $historyRecords = $this->getRecentHistory($message->getChatId(), $message->getUserIdentifier(), 6);
        $systemPrompt = $this->loadPrompt('conversation_strict_system_prompt.md');
        $messages = [new SystemMessage($systemPrompt)];

        foreach ($historyRecords as $record) {
            if ($record->getRecordType() === 'user') {
                $messages[] = new UserMessage(new Text($record->getMessage() ?? ''));
            } elseif ($record->getRecordType() === 'assistant') {
                $messages[] = new AssistantMessage(new Text($record->getContent() ?? ''));
            }
        }

        $messages[] = new UserMessage(new Text($message->getMessage()));

        try {
            $agent = new Agent($this->platform, $this->model);
            $response = $agent->call(new MessageBag(...$messages));
            $aiResponseText = trim((string) $response->getContent());

            $this->dispatchResponse($message, $aiResponseText);
        } catch (\Throwable $exception) {
            $this->logger->error('[ChatToolPdfMessageProcessor] Error en la conversación.', [
                'error' => $exception->getMessage(),
            ]);
            $this->dispatchResponse($message, 'Ocurrió un error al procesar la conversación.');
        }
    }

    // Clasificación de la pregunta mediante el prompt de intenciones, inyectando historial.
    private function classifyIntent(string $userText, array $historyRecords): string
    {
        try {
            $systemContent = $this->loadPrompt('intent_classification_system_prompt.md');

            // Construir contexto del historial para que la IA tome una decisión informada
            $historyContext = "CONTEXTO HISTÓRICO RECIENTE:\n";
            if (empty($historyRecords)) {
                $historyContext .= "No hay historial previo en esta sesión.\n";
            } else {
                foreach ($historyRecords as $record) {
                    $role = $record->getRecordType() === 'user' ? 'Usuario' : 'Asistente';
                    $hasAttachment = $record->getAttachmentKey() ? " [Envió un archivo adjunto]" : "";
                    $msgText = $record->getMessage() ?? $record->getContent() ?? '';
                    // Limitamos la longitud del mensaje histórico para no saturar tokens
                    $msgText = mb_substr(trim($msgText), 0, 150) . (mb_strlen($msgText) > 150 ? '...' : '');

                    $historyContext .= "- {$role}{$hasAttachment}: {$msgText}\n";
                }
            }
            $systemContent .= "\n\n" . $historyContext;



            $agent = new Agent($this->platform, $this->model);
            $response = $agent->call(new MessageBag(
                new SystemMessage($systemContent),
                new UserMessage(new Text($userText)),
            ));

            $decoded = $this->decodeJsonContent((string) $response->getContent());
            $intent = is_array($decoded) ? (string) ($decoded['intent'] ?? '') : '';

            $validIntents = [
                self::INTENT_CONVERSATION,
                self::INTENT_ANALYZE_DOCUMENT,
                self::INTENT_CREATE_DOCUMENT,
                self::INTENT_EDIT_DOCUMENT,
                self::INTENT_NO_UNDERSTAND_QUESTION,
            ];

            if (!in_array($intent, $validIntents, true)) {
                $this->logger->warning('[ChatToolPdfMessageProcessor] La IA devolvió una intención inválida, malformada o vacía.', [
                    'raw_response' => (string) $response->getContent(),
                    'parsed_intent' => $intent,
                ]);

                return self::INTENT_NO_UNDERSTAND_QUESTION;
            }
            $this->logger->info('[ChatToolPdfMessageProcessor] systemContent.', [
                $systemContent,
                $intent
            ]);
            return $intent;
        } catch (\Throwable $exception) {
            $this->logger->error('[ChatToolPdfMessageProcessor] Falló el clasificador de intención por excepción.', [
                'error' => $exception->getMessage(),
            ]);

            return self::INTENT_NO_UNDERSTAND_QUESTION;
        }
    }

    // Método auxiliar para evitar duplicar la lógica de Doctrine
    private function getRecentHistory(string $chatId, string $userIdentifier, int $limit): array
    {
        return $this->entityManager->getRepository(ChatHistoryPdf::class)
            ->findBy(
                [
                    'chatId' => $chatId,
                    'userIdentifier' => $userIdentifier,
                ],
                ['createdAt' => 'ASC'],
                $limit
            );
    }

    /**
     * Obtiene el JSON de la última respuesta del asistente que generó una cotización.
     *
     * @return array<string, mixed>|null
     */
   private function findLatestContentJson(string $chatId, string $userIdentifier): ?array
    {
        $chatId = trim($chatId);
        $userIdentifier = trim($userIdentifier);

        $history = $this->entityManager->getRepository(ChatHistoryPdf::class)
            ->createQueryBuilder('h')
            ->where('h.chatId = :chatId')
            ->andWhere('h.userIdentifier = :userIdentifier')
            ->andWhere('h.contentJson IS NOT NULL')
            ->setParameter('chatId', $chatId)
            ->setParameter('userIdentifier', $userIdentifier)
            ->orderBy('h.createdAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults(1) // Trae estrictamente el último
            ->getQuery()
            ->getOneOrNullResult();

        $contentJson = $history instanceof ChatHistoryPdf
            ? $history->getContentJson()
            : null;

        return is_array($contentJson) ? $contentJson : null;
    }

    private function loadPrompt(string $promptName): string
    {
        return $this->promptLoader->load('chattoolpdf/' . $promptName);
    }

    private function normalizeQuestion(string $question): string
    {
        return trim($question);
    }

    private function decodeJsonContent(string $content): mixed
    {
        $normalized = trim($content);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $normalized, $matches) === 1) {
            $normalized = trim($matches[1]);
        } else {
            $jsonStart = strpos($normalized, '{');
            $jsonEnd = strrpos($normalized, '}');

            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $normalized = substr($normalized, $jsonStart, $jsonEnd - $jsonStart + 1);
            }
        }

        $decoded = json_decode(trim($normalized), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function truncateAiResponse(string $response, int $maxLength = 2000): string
    {
        $response = trim($response);

        if (mb_strlen($response) <= $maxLength) {
            return $response;
        }

        return mb_substr($response, 0, $maxLength) . '...';
    }

    private function dispatchResponse(
        ChatToolPdfMessage $message,
        ?string $content = null,
        ?array $contentJson = null,
        ?string $pdfUrl = null,
        ?string $originalNameAttachment = null,
        ?string $attachmentPath = null,
        bool $isLocked = false,
    ): void {
        $this->logger->info('[=================inicio==========================]');
        $resolvedContent = $content !== null && trim($content) !== ''
            ? $content
            : 'no tenemos servicio en este momento';
        $resolvedAttachmentPath = $attachmentPath ?? $message->getAttachmentKey();
        $publishedData = [
            'chat_id' => $message->getChatId(),
            'user_identifier' => $message->getUserIdentifier(),
            'content' => $resolvedContent,
            'content_json' => $contentJson,
            'pdf_url' => $pdfUrl,
            'mercure_topic' => $message->getMercureTopic(),
            'original_name_attachment' => $originalNameAttachment,
            'attachment_path' => $resolvedAttachmentPath,
            'is_locked' => $isLocked,
            'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        $this->logger->info('[ChatToolPdfMessageProcessor] Payload completo a publicar.', $publishedData);
        $consolePayload = json_encode($publishedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($consolePayload)) {
            fwrite(STDOUT, "[ChatToolPdfMessageProcessor] Payload completo a publicar: {$consolePayload}\n");
        }

        $this->persistAssistantResponse(
            $message,
            $resolvedContent,
            $pdfUrl,
            $originalNameAttachment,
            $resolvedAttachmentPath,
            $isLocked,
            $contentJson,
        );

        $this->messageBus->dispatch(new ChatToolIAPdfResponse(
            chatId: $message->getChatId(),
            userIdentifier: $message->getUserIdentifier(),
            content: $resolvedContent,
            pdfUrl: $pdfUrl,
            mercureTopic: $message->getMercureTopic(),
            originalNameAttachment: $originalNameAttachment,
            attachmentPath: $resolvedAttachmentPath,
            isLocked: $isLocked,
            createdAt: $message->getCreatedAt(),
        ));
        $this->logger->info('[=================fin==========================]');
    }

    private function persistUserMessage(ChatToolPdfMessage $message, string $intent): void
    {
        $history = new ChatHistoryPdf(
            chatId: $message->getChatId(),
            userIdentifier: $message->getUserIdentifier(),
            recordType: 'user',
            intent: $intent,
            message: $message->getMessage(),
            toolEnabled: $message->isToolEnabled(),
            tenant: $message->getTenant(),
            locale: $message->getLocale(),
            session: $message->getSession(),
            history: $message->getHistory(),
            attachmentKey: $message->getAttachmentKey(),
            mercureTopic: $message->getMercureTopic(),
            createdAt: $message->getCreatedAt(),
        );

        $this->entityManager->persist($history);
        $this->entityManager->flush();
    }

    private function persistAssistantResponse(
        ChatToolPdfMessage $message,
        string $content,
        ?string $pdfUrl,
        ?string $originalNameAttachment,
        ?string $attachmentPath,
        bool $isLocked = false,
        ?array $contentJson = null,
    ): void {
        $history = new ChatHistoryPdf(
            chatId: $message->getChatId(),
            userIdentifier: $message->getUserIdentifier(),
            recordType: 'assistant',
            mercureTopic: $message->getMercureTopic(),
            createdAt: $message->getCreatedAt(),
            content: $content,
            contentJson: $contentJson,
            pdfUrl: $pdfUrl,
            originalNameAttachment: $originalNameAttachment,
            attachmentPath: $attachmentPath,
            isLocked: $isLocked,
        );

        $this->entityManager->persist($history);
        $this->entityManager->flush();
    }

    private function findLatestAttachmentKey(string $chatId, string $userIdentifier): ?string
    {
        $history = $this->entityManager->getRepository(ChatHistoryPdf::class)
            ->createQueryBuilder('history')
            ->andWhere('history.chatId = :chatId')
            ->andWhere('history.userIdentifier = :userIdentifier')
            ->andWhere('history.attachmentKey IS NOT NULL')
            ->andWhere('history.attachmentKey <> :emptyAttachmentKey')
            ->setParameter('chatId', $chatId)
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('emptyAttachmentKey', '')
            ->orderBy('history.createdAt', 'DESC')
            ->addOrderBy('history.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$history instanceof ChatHistoryPdf) {
            return null;
        }

        $attachmentKey = trim((string) $history->getAttachmentKey());

        return $attachmentKey !== '' ? $attachmentKey : null;
    }
}

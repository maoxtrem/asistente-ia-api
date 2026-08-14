<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

use App\Entity\ChatHistoryPdfImage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

final readonly class PdfDocumentExtractionService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'chattoolpdf.storage.attach_pdf')]
        private FilesystemOperator $attachPdfStorage,
        #[Autowire(service: 'chattoolpdf.storage.zip')]
        private FilesystemOperator $chattoolpdfZipStorage,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        #[Autowire('%app.document_extraction_endpoint%')]
        private string $endpoint,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function extractFromAttachment(string $attachmentKey): array
    {
        $pdfBinary = $this->attachPdfStorage->read($attachmentKey);
        if ($pdfBinary === '') {
            throw new \RuntimeException(sprintf('El PDF adjunto está vacío o no existe: %s', $attachmentKey));
        }

        return $this->extractFromBinary(
            $pdfBinary,
            basename($attachmentKey),
            'application/pdf',
            'PDF',
        );
    }

    /**
     * Envía el binario de una imagen al endpoint de Docling.
     *
     * El endpoint es el mismo que procesa PDFs (`DOCUMENT_EXTRACTION_ENDPOINT`):
     * Docling acepta imágenes como archivos multipart en el campo `files` y
     * devuelve la representación documental en JSON.
     *
     * @return array<string, mixed>
     */
    public function extractFromImageBinary(
        string $imageBinary,
        string $fileName = 'image.png',
        ?string $mimeType = null,
    ): array {
        if ($imageBinary === '') {
            throw new \RuntimeException('No se recibió contenido para la extracción de la imagen.');
        }

        $mimeType ??= (new \finfo(FILEINFO_MIME_TYPE))->buffer($imageBinary) ?: 'image/png';

        return $this->extractFromBinary($imageBinary, basename($fileName), $mimeType, 'imagen');
    }

    /**
     * Extrae una imagen almacenada en el ZIP y guarda la respuesta de Docling
     * directamente en la entidad de la imagen.
     */
    public function extractAndStoreImage(ChatHistoryPdfImage $image): void
    {
        if ($image->getDoclingJson() !== null) {
            return;
        }

        $imageBinary = $this->chattoolpdfZipStorage->read($image->getImageKey());
        if ($imageBinary === '') {
            throw new \RuntimeException('No fue posible leer la imagen para enviarla a Docling.');
        }

        $doclingResult = $this->extractFromImageBinary(
            $imageBinary,
            $image->getImageName(),
            $image->getMimeType(),
        );

        $this->logger->info('[PdfDocumentExtractionService] Respuesta JSON de Docling.', [
            'image_key' => $image->getImageKey(),
            'image_name' => $image->getImageName(),
            'docling_json' => $doclingResult,
        ]);

        $image->setDoclingJson($doclingResult);
        $this->entityManager->persist($image);
        $this->entityManager->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFromBinary(
        string $binary,
        string $fileName,
        string $mimeType,
        string $documentLabel,
    ): array {
        $tempPath = tempnam(sys_get_temp_dir(), 'chattoolpdf_doc_extract_');
        if ($tempPath === false) {
            throw new \RuntimeException('No fue posible crear el archivo temporal para la extracción documental.');
        }

        try {
            if (file_put_contents($tempPath, $binary) === false) {
                throw new \RuntimeException(sprintf('No fue posible guardar el %s temporal para la extracción documental.', $documentLabel));
            }

            $formData = new FormDataPart([
                'files' => DataPart::fromPath($tempPath, $fileName, $mimeType),
            ]);

            $response = $this->httpClient->request('POST', $this->endpoint, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new \RuntimeException(sprintf(
                    'El servicio de extracción devolvió HTTP %d: %s',
                    $response->getStatusCode(),
                    $response->getContent(false),
                ));
            }

            $result = $response->toArray();
            if (!is_array($result)) {
                throw new \RuntimeException('El servicio de extracción no devolvió un array JSON.');
            }

            return $result;
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }
}

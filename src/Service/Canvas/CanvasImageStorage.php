<?php

declare(strict_types=1);

namespace App\Service\Canvas;

use App\Entity\ImageDocument;
use App\Repository\ImageDocumentRepository;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CanvasImageStorage
{
    private const STORAGE_NAME = 'canvas.storage.images';

    public function __construct(
        private readonly ImageDocumentRepository $imageDocumentRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: self::STORAGE_NAME)]
        private readonly FilesystemOperator $canvasStorage,
        #[Autowire(service: 'aws.s3.minio_public_client')]
        private readonly S3Client $minioPublicClient,
        #[Autowire('%env(string:MINIO_BUCKET_CANVAS_IMAGE)%')]
        private readonly string $imageBucket,
        #[Autowire('%env(int:MINIO_URL_EXPIRATION_HOURS)%')]
        private readonly int $minioUrlExpirationHours,
    ) {
    }

    /**
     * @param array{
     *   tenant:string,
     *   usuario:string,
     *   entorno:string,
     *   file_name:string,
     *   mime_type:string,
     *   image:string,
     *   metadata?:array<string,mixed>
     * } $payload
     *
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $validation = $this->validateBasePayload($payload);
        if ($validation !== null) {
            return $validation;
        }

        try {
            $imageData = $this->extractImageData($payload);
            $document = $this->createImageDocument($payload, $imageData);

            return $this->storeImageDocument($payload, $document, $imageData, true);
        } catch (\Throwable $exception) {
            return $this->errorResponse(400, 'No se pudo procesar la imagen.', $payload, [
                'details' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array{tenant?: mixed, usuario?: mixed, entorno?: mixed, limit?: mixed} $filters
     *
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $tenant = isset($filters['tenant']) && is_string($filters['tenant']) ? trim($filters['tenant']) : '';
        $usuario = isset($filters['usuario']) && is_string($filters['usuario']) ? trim($filters['usuario']) : '';
        $entorno = isset($filters['entorno']) && is_string($filters['entorno']) ? trim($filters['entorno']) : '';
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : null;

        if ($tenant === '' || $usuario === '' || $entorno === '') {
            return $this->errorResponse(
                400,
                'Los campos "tenant", "usuario" y "entorno" son obligatorios para listar imágenes.',
                $filters
            );
        }

        $documents = $this->imageDocumentRepository->findByFilters($usuario, $entorno, $tenant, $limit);
        $records = array_map(
            fn (ImageDocument $document): array => $this->buildImageResponseBody($document),
            $documents
        );

        return [
            'ok' => true,
            'status_code' => 200,
            'body' => [
                'message' => 'Imágenes obtenidas desde la base de datos.',
                'filters' => [
                    'tenant' => $tenant,
                    'usuario' => $usuario,
                    'entorno' => $entorno,
                    'limit' => $limit,
                ],
                'count' => count($records),
                'records' => $records,
            ],
        ];
    }

    public function resolve(string $identifier): array
    {
        $document = $this->findDocumentByIdentifier($identifier);

        if ($document === null) {
            return $this->errorResponse(404, 'No se encontró la imagen solicitada.', []);
        }

        return [
            'ok' => true,
            'status_code' => 200,
            'body' => array_merge(
                [
                    'status' => $document->getStatus(),
                ],
                $this->buildImageResponseBody($document)
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateBasePayload(array $payload): ?array
    {
        $missingFields = [];

        foreach (['tenant', 'usuario', 'entorno', 'image'] as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                $missingFields[] = $field;
            }
        }

        if ($missingFields !== []) {
            return $this->errorResponse(400, 'Faltan campos obligatorios.', $payload, [
                'missing_fields' => $missingFields,
            ]);
        }

        if (!is_string($payload['tenant']) || trim($payload['tenant']) === '') {
            return $this->errorResponse(400, 'El campo "tenant" debe ser string no vacío.', $payload);
        }

        if (!is_string($payload['usuario']) || trim($payload['usuario']) === '') {
            return $this->errorResponse(400, 'El campo "usuario" debe ser string no vacío.', $payload);
        }

        if (!is_string($payload['entorno']) || trim($payload['entorno']) === '') {
            return $this->errorResponse(400, 'El campo "entorno" debe ser string no vacío.', $payload);
        }

        if (!is_string($payload['image']) || trim($payload['image']) === '') {
            return $this->errorResponse(400, 'El campo "image" debe ser string no vacío.', $payload);
        }

        if (array_key_exists('mime_type', $payload) && !is_string($payload['mime_type'])) {
            return $this->errorResponse(400, 'El campo "mime_type" debe ser string.', $payload);
        }

        if (array_key_exists('file_name', $payload) && !is_string($payload['file_name'])) {
            return $this->errorResponse(400, 'El campo "file_name" debe ser string.', $payload);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{stream:resource,mime_type:string,file_name:?string,extension:string,size_bytes:int}
     */
    private function extractImageData(array $payload): array
    {
        $rawImage = trim((string) $payload['image']);
        $rawImage = preg_replace('/^data:[^;]+;base64,/', '', $rawImage) ?? $rawImage;

        $mimeType = isset($payload['mime_type']) && is_string($payload['mime_type']) && trim($payload['mime_type']) !== ''
            ? trim($payload['mime_type'])
            : 'image/png';

        $fileName = isset($payload['file_name']) && is_string($payload['file_name']) && trim($payload['file_name']) !== ''
            ? trim($payload['file_name'])
            : null;

        $extension = $this->resolveExtension($mimeType, $fileName);
        $stream = $this->decodeBase64ToStream($rawImage);

        return [
            'stream' => $stream,
            'mime_type' => $mimeType,
            'file_name' => $fileName,
            'extension' => $extension,
            'size_bytes' => $this->estimateDecodedSize($rawImage),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{stream:resource,mime_type:string,file_name:?string,extension:string,size_bytes:int} $imageData
     */
    private function createImageDocument(array $payload, array $imageData): ImageDocument
    {
        $referenceData = $this->generateReferenceData();
        $objectKey = $this->generateObjectKey($imageData['extension']);

        return new ImageDocument(
            $referenceData['value'],
            $referenceData['uuid'],
            (string) $payload['tenant'],
            (string) $payload['usuario'],
            (string) $payload['entorno'],
            $imageData['mime_type'],
            $imageData['file_name'],
            $this->buildStoredPayload($imageData, $payload),
            $objectKey,
            $this->imageBucket,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{stream:resource,mime_type:string,file_name:?string,extension:string,size_bytes:int} $imageData
     */
    private function storeImageDocument(array $payload, ImageDocument $document, array $imageData, bool $isNewDocument): array
    {
        $requestPayload = $this->buildStoredPayload($imageData, $payload);
        $objectKey = $document->getObjectKey();
        $content = $imageData['stream'];

        try {
            $this->canvasStorage->writeStream($objectKey, $content, [
                'mimetype' => $imageData['mime_type'],
            ]);
        } catch (\Throwable $exception) {
            if (is_resource($content)) {
                fclose($content);
            }

            return $this->errorResponse(502, 'No fue posible guardar la imagen en MinIO.', $payload, [
                'details' => $exception->getMessage(),
            ]);
        }

        if ($isNewDocument) {
            $this->entityManager->persist($document);
        } else {
            $document->replaceImageData(
                $imageData['mime_type'],
                $imageData['file_name'],
                $requestPayload
            );
        }

        $document->markStored();
        $this->entityManager->flush();

        if (is_resource($content)) {
            fclose($content);
        }

        return [
            'ok' => true,
            'status_code' => 201,
            'body' => array_merge(
                [
                    'status' => 'stored',
                    'message' => 'Imagen guardada correctamente.',
                ],
                $this->buildImageResponseBody($document)
            ),
        ];
    }

    /**
     * @param array{mime_type:string,file_name:?string,stream:resource,extension:string,size_bytes:int} $imageData
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildStoredPayload(array $imageData, array $payload): array
    {
        return [
            'mime_type' => $imageData['mime_type'],
            'file_name' => $imageData['file_name'],
            'size_bytes' => $imageData['size_bytes'],
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        ];
    }

    private function generateReferenceData(): array
    {
        $hex = bin2hex(random_bytes(16));
        $uuid = $this->generateUuidV4();

        return [
            'hex' => $hex,
            'uuid' => $uuid,
            'value' => $hex . '-' . $uuid,
        ];
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function generateObjectKey(string $extension = 'bin'): string
    {
        $normalizedExtension = strtolower(trim($extension));
        $normalizedExtension = preg_replace('/[^a-z0-9]+/', '', $normalizedExtension) ?: 'bin';

        return bin2hex(random_bytes(32)) . '.' . $normalizedExtension;
    }

    private function resolveExtension(string $mimeType, ?string $fileName): string
    {
        if ($fileName !== null && pathinfo($fileName, PATHINFO_EXTENSION) !== '') {
            return strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        }

        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            default => 'bin',
        };
    }

    /**
     * @return resource
     */
    private function decodeBase64ToStream(string $rawImage)
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('No se pudo crear el buffer temporal de la imagen.');
        }

        $filter = stream_filter_append($stream, 'convert.base64-decode', STREAM_FILTER_WRITE);
        if ($filter === false) {
            fclose($stream);

            throw new RuntimeException('No se pudo preparar el decodificador base64.');
        }

        $length = strlen($rawImage);
        $chunkSize = 8192;

        for ($offset = 0; $offset < $length; $offset += $chunkSize) {
            $chunk = substr($rawImage, $offset, $chunkSize);
            if ($chunk === false) {
                continue;
            }

            fwrite($stream, $chunk);
        }

        stream_filter_remove($filter);
        rewind($stream);

        return $stream;
    }

    private function estimateDecodedSize(string $rawImage): int
    {
        $length = strlen($rawImage);
        $padding = 0;

        if ($length >= 2 && str_ends_with($rawImage, '==')) {
            $padding = 2;
        } elseif ($length >= 1 && str_ends_with($rawImage, '=')) {
            $padding = 1;
        }

        return (int) floor(($length * 3) / 4) - $padding;
    }

    private function findDocumentByIdentifier(string $identifier): ?ImageDocument
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if ($this->isValidUuid($identifier)) {
            return $this->imageDocumentRepository->findByUuid($identifier);
        }

        if ($this->isValidReference($identifier)) {
            return $this->imageDocumentRepository->findByReference($identifier);
        }

        $document = $this->imageDocumentRepository->findByObjectKey($identifier);
        if ($document instanceof ImageDocument) {
            return $document;
        }

        return null;
    }

    private function isValidReference(string $reference): bool
    {
        return preg_match('/^[a-f0-9]{32}-[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $reference) === 1;
    }

    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildImageResponseBody(ImageDocument $document): array
    {
        return [
            'status' => $document->getStatus(),
            'reference' => $document->getReference(),
            'uuid' => $document->getUuid(),
            'tenant' => $document->getTenant(),
            'usuario' => $document->getUsuario(),
            'entorno' => $document->getEntorno(),
            'image_file_name' => $document->getImageFileName(),
            'image_mime_type' => $document->getImageMimeType(),
            'object_key' => $document->getObjectKey(),
            'image_url' => $this->temporaryObjectUrl($document->getStorageBucket(), $document->getObjectKey()),
            'image_url_expires_in_hours' => max(1, $this->minioUrlExpirationHours),
        ];
    }

    private function temporaryObjectUrl(string $bucket, string $objectKey): string
    {
        $expiresInSeconds = max(1, $this->minioUrlExpirationHours) * 3600;
        $command = $this->minioPublicClient->getCommand('GetObject', [
            'Bucket' => $bucket !== '' ? $bucket : $this->imageBucket,
            'Key' => $objectKey,
        ]);

        $request = $this->minioPublicClient->createPresignedRequest(
            $command,
            sprintf('+%d seconds', $expiresInSeconds)
        );

        return (string) $request->getUri();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $extraBody
     * @return array<string, mixed>
     */
    private function errorResponse(int $statusCode, string $message, array $payload, array $extraBody = []): array
    {
        return [
            'ok' => false,
            'status_code' => $statusCode,
            'body' => array_merge([
                'error' => $message,
                'tenant' => $payload['tenant'] ?? null,
                'usuario' => $payload['usuario'] ?? null,
                'entorno' => $payload['entorno'] ?? null,
            ], $extraBody),
        ];
    }
}

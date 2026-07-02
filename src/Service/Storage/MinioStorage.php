<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use DateInterval;
use DateTimeInterface;
use RuntimeException;

final class MinioStorage
{
    public function __construct(
        private readonly S3Client $client,
        private readonly S3Client $publicClient,
        private readonly string $bucket,
    ) {
    }

    public function upload(string $key, string $contents, ?string $contentType = null, array $metadata = []): void
    {
        $payload = $this->buildPayload($key, $contentType, $metadata);
        $payload['Body'] = $contents;

        $this->call('putObject', $payload);
    }

    public function uploadFile(string $key, string $filePath, ?string $contentType = null, array $metadata = []): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException(sprintf('The file "%s" cannot be read.', $filePath));
        }

        $payload = $this->buildPayload($key, $contentType, $metadata);
        $payload['SourceFile'] = $filePath;

        $this->call('putObject', $payload);
    }

    public function exists(string $key): bool
    {
        return $this->client->doesObjectExistV2($this->bucket, $key);
    }

    /**
     * @return array<int, array{key:string, size:int, lastModified:?string}>
     */
    public function list(string $prefix = '', int $limit = 100): array
    {
        $arguments = [
            'Bucket' => $this->bucket,
            'Prefix' => trim($prefix),
            'MaxKeys' => max(1, min($limit, 1000)),
        ];

        $result = $this->call('listObjectsV2', $arguments);
        $objects = $result['Contents'] ?? [];

        if (!is_array($objects)) {
            return [];
        }

        $items = [];
        foreach ($objects as $object) {
            if (!is_array($object) || !isset($object['Key'])) {
                continue;
            }

            $items[] = [
                'key' => (string) $object['Key'],
                'size' => (int) ($object['Size'] ?? 0),
                'lastModified' => isset($object['LastModified']) ? (string) $object['LastModified']->format(DATE_ATOM) : null,
            ];
        }

        return $items;
    }

    public function download(string $key): string
    {
        $result = $this->call('getObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        $body = $result['Body'] ?? null;
        if (!is_object($body) || !method_exists($body, 'getContents')) {
            throw new RuntimeException(sprintf('MinIO object "%s" does not contain a readable body.', $key));
        }

        return (string) $body->getContents();
    }

    public function delete(string $key): void
    {
        $this->call('deleteObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
    }

    public function temporaryUrl(string $key, DateInterval|DateTimeInterface|int|string $expires = '+15 minutes'): string
    {
        $command = $this->publicClient->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        $request = $this->publicClient->createPresignedRequest($command, $expires);

        return (string) $request->getUri();
    }

    public function publicUrl(string $key): string
    {
        return $this->publicClient->getObjectUrl($this->bucket, $key);
    }

    public function imageUrl(string $name, DateInterval|DateTimeInterface|int|string $expires = '+15 minutes'): string
    {
        $objectKey = $this->normalizeObjectKey($name);

        if ($this->exists($objectKey)) {
            return $this->temporaryUrl($objectKey, $expires);
        }

        throw new RuntimeException(sprintf('The image "%s" was not found in bucket "%s".', $name, $this->bucket));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function call(string $operation, array $arguments): mixed
    {
        try {
            return $this->client->{$operation}($arguments);
        } catch (AwsException $exception) {
            throw new RuntimeException(
                sprintf('MinIO operation "%s" failed: %s', $operation, $exception->getAwsErrorMessage() ?: $exception->getMessage()),
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $key, ?string $contentType, array $metadata): array
    {
        $payload = [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ];

        if ($contentType !== null && $contentType !== '') {
            $payload['ContentType'] = $contentType;
        }

        if ($metadata !== []) {
            $payload['Metadata'] = $metadata;
        }

        return $payload;
    }

    private function normalizeObjectKey(string $name): string
    {
        $normalized = trim($name);
        $normalized = ltrim($normalized, '/');

        if ($normalized === '') {
            throw new RuntimeException('The image name cannot be empty.');
        }

        return $normalized;
    }
}

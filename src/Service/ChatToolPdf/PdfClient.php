<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PdfClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $servicePdfUrl,
        private readonly string $servicePdfHost,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->servicePdfUrl, '/') . '/generate', [
                'json' => $payload,
                'headers' => [
                    'Accept' => 'application/json',
                    ...($this->servicePdfHost !== '' ? ['Host' => $this->servicePdfHost] : []),
                ],
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $body = $response->toArray(false);
            $statusCode = $response->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible consultar service-pdf: %s', $exception->getMessage()), 0, $exception);
        }

        if (!is_array($body)) {
            throw new RuntimeException('service-pdf no devolvio una respuesta valida.');
        }

        return [
            'status_code' => $statusCode,
            'body' => $body,
            'ok' => $statusCode < 400,
            'message' => (string) ($body['message'] ?? ''),
            'pdf_url' => (string) ($body['pdf_url'] ?? ''),
            'reference' => $body['reference'] ?? null,
            'uuid' => $body['uuid'] ?? null,
        ];
    }
}

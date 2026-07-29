<?php

declare(strict_types=1);

namespace App\Service\Canvas;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PdfImageClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $servicePdfUrl,
        private readonly string $servicePdfHost,
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
        $response = $this->request('POST', '/images', $payload);

        return [
            'status_code' => $response['status_code'],
            'ok' => $response['status_code'] < 400,
            'body' => $response['body'],
            'message' => (string) ($response['body']['message'] ?? ''),
            'reference' => $response['body']['reference'] ?? null,
            'uuid' => $response['body']['uuid'] ?? null,
            'image_url' => $response['body']['image_url'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $tenant = trim((string) ($filters['tenant'] ?? ''));
        $usuario = trim((string) ($filters['usuario'] ?? ''));
        $entorno = trim((string) ($filters['entorno'] ?? ''));

        if ($tenant === '' || $usuario === '' || $entorno === '') {
            throw new RuntimeException('tenant, usuario y entorno son obligatorios para listar imagenes.');
        }

        $query = [
            'tenant' => $tenant,
            'usuario' => $usuario,
            'entorno' => $entorno,
            'limit' => (int) ($filters['limit'] ?? 100),
        ];

        $response = $this->request('GET', '/images?' . http_build_query($query));

        return [
            'status_code' => $response['status_code'],
            'ok' => $response['status_code'] < 400,
            'body' => $response['body'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status_code:int, body:array<string,mixed>}
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        try {
            $options = [
                'headers' => $this->buildHeaders(),
                'verify_peer' => false,
                'verify_host' => false,
            ];

            if ($method === 'GET') {
                $response = $this->httpClient->request($method, $this->buildUrl($path), $options);
            } else {
                $options['json'] = $payload;
                $response = $this->httpClient->request($method, $this->buildUrl($path), $options);
            }

            return [
                'status_code' => $response->getStatusCode(),
                'body' => $response->toArray(false),
            ];
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible consultar service-pdf: %s', $exception->getMessage()), 0, $exception);
        }
    }

    private function buildUrl(string $path): string
    {
        return rtrim($this->servicePdfUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string,string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($this->servicePdfHost !== '') {
            $headers['Host'] = $this->servicePdfHost;
        }

        return $headers;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TikaTextExtractor
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $tikaUrl,
    ) {
    }

    /**
     * @return array{status:string,text:string}
     */
    public function extract(string $binary, string $mimeType, string $fileName): array
    {
        if ($binary === '') {
            return $this->emptyResult('empty_input');
        }

        $attempts = [
            '/tika/text',
            '/tika',
        ];

        foreach ($attempts as $path) {
            $response = $this->request($path, $binary, $mimeType, $fileName);
            if ($response['status_code'] >= 400) {
                continue;
            }

            $text = $this->extractTextFromResponse($response['body'], $path);
            if ($text !== '') {
                return [
                    'status' => $path === '/tika/text' ? 'tika_text' : 'tika',
                    'text' => $text,
                ];
            }
        }

        return $this->emptyResult('empty');
    }

    /**
     * @return array{status_code:int, body:string}
     */
    private function request(string $path, string $binary, string $mimeType, string $fileName): array
    {
        try {
            $response = $this->httpClient->request('PUT', rtrim($this->tikaUrl, '/') . $path, [
                'headers' => [
                    'Accept' => 'application/json, text/plain, application/xhtml+xml',
                    'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
                    'Content-Disposition' => sprintf('attachment; filename="%s"', $this->sanitizeFileName($fileName)),
                ],
                'body' => $binary,
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            return [
                'status_code' => $response->getStatusCode(),
                'body' => $response->getContent(false),
            ];
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('No fue posible consultar Tika: %s', $exception->getMessage()), 0, $exception);
        }
    }

    private function extractTextFromResponse(string $body, string $path): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        if ($path === '/tika/text') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $content = (string) ($decoded['X-TIKA:content'] ?? '');
                return $this->normalizeText($content);
            }
        }

        return $this->normalizeText($this->stripMarkup($body));
    }

    private function stripMarkup(string $body): string
    {
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $body) ?? $body;
        $text = preg_replace('/<\/\s*p\s*>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\R{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function sanitizeFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            return 'document';
        }

        return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $fileName) ?? 'document';
    }

    /**
     * @return array{status:string,text:string}
     */
    private function emptyResult(string $status): array
    {
        return [
            'status' => $status,
            'text' => '',
        ];
    }
}

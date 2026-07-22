<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

final class PdfAttachmentPreviewExtractor
{
    public function __construct(
        private readonly TikaTextExtractor $tikaTextExtractor,
    ) {
    }

    /**
     * @return array{status:string,text_preview:string,text_length:int,text_truncated:bool}
     */
    public function extractPreview(string $contentBase64, string $mimeType, string $fileName): array
    {
        $contentBase64 = trim($contentBase64);
        if ($contentBase64 === '') {
            return $this->emptyResult('missing');
        }

        $normalizedMimeType = strtolower(trim($mimeType));
        $normalizedFileName = strtolower(trim($fileName));
        $binary = base64_decode($contentBase64, true);
        if ($binary === false || $binary === '') {
            return $this->emptyResult('invalid_base64');
        }

        $tikaResult = $this->tikaTextExtractor->extract($binary, $normalizedMimeType, $normalizedFileName);
        $text = trim((string) ($tikaResult['text'] ?? ''));

        if ($text === '') {
            return $this->emptyResult((string) ($tikaResult['status'] ?? 'empty'));
        }

        return $this->normalizePreviewText($text, (string) ($tikaResult['status'] ?? 'ok'));
    }

    /**
     * @return array{status:string,text_preview:string,text_length:int,text_truncated:bool}
     */
    private function normalizePreviewText(string $text, string $status): array
    {
        $normalized = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $normalized = preg_replace("/\R{3,}/", "\n\n", $normalized) ?? $normalized;
        $normalized = trim($normalized);

        $maxLength = 12000;
        $textLength = function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized);
        $preview = $normalized;
        $truncated = false;

        if ($textLength > $maxLength) {
            $preview = function_exists('mb_substr')
                ? mb_substr($normalized, 0, $maxLength)
                : substr($normalized, 0, $maxLength);
            $preview = rtrim($preview) . "\n\n[contenido truncado]";
            $truncated = true;
        }

        return [
            'status' => $status,
            'text_preview' => $preview,
            'text_length' => $textLength,
            'text_truncated' => $truncated,
        ];
    }

    /**
     * @return array{status:string,text_preview:string,text_length:int,text_truncated:bool}
     */
    private function emptyResult(string $status): array
    {
        return [
            'status' => $status,
            'text_preview' => '',
            'text_length' => 0,
            'text_truncated' => false,
        ];
    }

}

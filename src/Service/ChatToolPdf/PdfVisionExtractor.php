<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

use Symfony\Component\Process\Process;

final class PdfVisionExtractor
{
    public function extractFirstPageAsBase64(string $pdfPath): ?string
    {
        $pdfPath = trim($pdfPath);
        if ($pdfPath === '' || !is_file($pdfPath)) {
            return null;
        }

        $outputPrefix = sys_get_temp_dir() . '/' . uniqid('pdf_page_', true);
        $expectedOutputPath = $outputPrefix . '-1.jpg';

        $process = new Process(['pdftoppm', '-jpeg', '-f', '1', '-l', '1', $pdfPath, $outputPrefix]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful() || !is_file($expectedOutputPath)) {
            @unlink($expectedOutputPath);

            return null;
        }

        $content = file_get_contents($expectedOutputPath);
        @unlink($expectedOutputPath);

        if ($content === false || $content === '') {
            return null;
        }

        return base64_encode($content);
    }
}

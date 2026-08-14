<?php

declare(strict_types=1);

namespace App\Service\ChatToolPdf;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

final readonly class PlanoOcrOrchestrator
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%env(IMAGE_SPLITER_ENDPOINT)%')]
        private string $spliterEndpoint, // ej: http://image-spliter:8000/api/fraccionar
        #[Autowire('%env(PADDLEOCR_ENDPOINT)%')]
        private string $ocrEndpoint    // ej: http://localhost:8020/api/extraer
    ) {}

    /**
     * @return array<int, array{texto: string, coordenadas: array}>
     */
    public function processImageToOcrArray(string $imageBinary, string $fileName = 'plano.jpg'): array
    {
        $this->logger->info('[PlanoOcrOrchestrator] Iniciando procesamiento de imagen.');

        // 1. Llamar al microservicio de splitting.
        $splitsBase64 = $this->getSplitsFromImage($imageBinary, $fileName);
        
        if (empty($splitsBase64)) {
            return [];
        }

        $allOcrData = [];

        // 2. Bucle sobre cada recorte hacia PaddleOCR
        foreach ($splitsBase64 as $index => $base64Image) {
            $this->logger->info(sprintf('[PlanoOcrOrchestrator] Procesando recorte %d/%d en PaddleOCR', $index + 1, count($splitsBase64)));
            
            $splitBinary = base64_decode($base64Image);
            $ocrResult = $this->extractTextFromSplit($splitBinary, "split_{$index}.jpg");
            
            if (!empty($ocrResult['datos_ocr'])) {
                // Acumulamos los resultados brutos (Option B: delegar la limpieza de duplicados al LLM)
                $allOcrData = array_merge($allOcrData, $ocrResult['datos_ocr']);
            }
        }

        $this->logger->info('[PlanoOcrOrchestrator] Extracción OCR completada.', [
            'total_textos_encontrados' => count($allOcrData)
        ]);

        return $allOcrData;
    }

    private function getSplitsFromImage(string $imageBinary, string $fileName): array
    {
        $formData = new FormDataPart([
            'file' => new DataPart($imageBinary, $fileName, 'image/jpeg'),
            'tile_size' => '2048', // Tamaño ideal para planos gigantes
            'overlap' => '150',
            'skip_blank' => 'true'
        ]);

        $response = $this->httpClient->request('POST', $this->spliterEndpoint, [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToString(),
        ]);

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('[PlanoOcrOrchestrator] Falló el servicio de splitting.');
            return [];
        }

        $data = $response->toArray();
        return $data['tiles'] ?? [];
    }

    private function extractTextFromSplit(string $splitBinary, string $fileName): array
    {
        $formData = new FormDataPart([
            'file' => new DataPart($splitBinary, $fileName, 'image/jpeg'),
        ]);

        $response = $this->httpClient->request('POST', $this->ocrEndpoint, [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToString(),
        ]);

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        return $response->toArray();
    }
}

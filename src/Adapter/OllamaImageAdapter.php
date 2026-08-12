<?php

declare(strict_types=1);

namespace App\Adapter;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\AI\Platform\PlatformInterface;

final readonly class OllamaImageAdapter
{
    public function __construct(
        #[Autowire(service: 'ai.platform.ollama')]
        private PlatformInterface $platform,
        #[Autowire('%app.chattoolpdf_model%')]
        private string $model,
    ) {
    }

    public function analyzeImageWithOllama(
        string $systemPrompt,
        string $userPrompt,
        string $imageBinary,
    ): string {
        if ($imageBinary === '') {
            throw new \RuntimeException('No se recibió contenido para la imagen de Ollama.');
        }

        $imageBinary = $this->resizeImageForOllama($imageBinary);

        $result = $this->platform->invoke($this->model, [
            // Se conserva el nombre del modelo dentro del payload porque aquí
            // enviamos el formato nativo multimodal de Ollama.
            'model' => $this->model,
            'stream' => false,
            'format' => 'json',
            'options' => [
                // Deja espacio suficiente para la imagen, el prompt y el JSON completo.
                'num_ctx' => 8192,
                'temperature' => 0,
                'num_predict' => 8192,
                // Reduce la repetición de la misma nota en documentos con muchas etiquetas.
                'repeat_penalty' => 1.2,
                'repeat_last_n' => 256,
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                    'images' => [base64_encode($imageBinary)],
                ],
            ],
        ])->getResult();

        $responseContent = $result->getContent();

        if (!is_string($responseContent)) {
            throw new \RuntimeException('Ollama no devolvió contenido de texto.');
        }

        return $responseContent;
    }

    public function analyzeTextWithOllama(
        string $systemPrompt,
        string $userPrompt,
    ): string {
        $result = $this->platform->invoke($this->model, [
            'model' => $this->model,
            'stream' => false,
            'format' => 'json',
            'options' => [
                'num_ctx' => 16384,
                'temperature' => 0,
                'num_predict' => 8192,
                'repeat_penalty' => 1.15,
                'repeat_last_n' => 256,
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ])->getResult();

        $responseContent = $result->getContent();
        if (!is_string($responseContent)) {
            throw new \RuntimeException('Ollama no devolvió contenido de texto para el análisis final.');
        }

        return $responseContent;
    }

    private function resizeImageForOllama(
        string $imageBinary,
        int $maxDimension = 1500,
    ): string {
        $imageInfo = getimagesizefromstring($imageBinary);

        if ($imageInfo === false) {
            throw new \RuntimeException('La imagen no tiene un formato válido.');
        }

        [$width, $height] = $imageInfo;

        if ($width <= $maxDimension && $height <= $maxDimension) {
            return $imageBinary;
        }

        $scale = min(
            $maxDimension / $width,
            $maxDimension / $height,
        );

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $source = imagecreatefromstring($imageBinary);

        if ($source === false) {
            throw new \RuntimeException('No fue posible cargar la imagen para redimensionarla.');
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        if ($resized === false) {
            imagedestroy($source);

            throw new \RuntimeException('No fue posible crear la imagen redimensionada.');
        }

        if (!imagecopyresampled(
            $resized,
            $source,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height,
        )) {
            imagedestroy($source);
            imagedestroy($resized);

            throw new \RuntimeException('No fue posible redimensionar la imagen.');
        }

        ob_start();
        $encoded = imagejpeg($resized, null, 85);
        $resizedBinary = ob_get_clean();

        imagedestroy($source);
        imagedestroy($resized);

        if (!$encoded || !is_string($resizedBinary) || $resizedBinary === '') {
            throw new \RuntimeException('No fue posible codificar la imagen redimensionada.');
        }

        return $resizedBinary;
    }
}

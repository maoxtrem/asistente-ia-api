<?php

declare(strict_types=1);

namespace App\Service\Prompt;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PromptLoader
{
    /**
     * @var array<string, string>
     */
    private array $cache = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function load(string $promptPath): string
    {
        $normalizedPath = $this->normalizePath($promptPath);
        if (isset($this->cache[$normalizedPath])) {
            return $this->cache[$normalizedPath];
        }

        $filePath = rtrim($this->projectDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'config'
            . DIRECTORY_SEPARATOR
            . 'prompts'
            . DIRECTORY_SEPARATOR
            . $normalizedPath;

        if (!is_file($filePath)) {
            throw new RuntimeException(sprintf('No existe el prompt "%s" en "%s".', $promptPath, $filePath));
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException(sprintf('No fue posible leer el prompt "%s".', $filePath));
        }

        $content = trim($content);
        if ($content === '') {
            throw new RuntimeException(sprintf('El prompt "%s" está vacío.', $filePath));
        }

        return $this->cache[$normalizedPath] = $content;
    }

    private function normalizePath(string $promptPath): string
    {
        $promptPath = str_replace('\\', '/', trim($promptPath));
        $promptPath = ltrim($promptPath, '/');

        if ($promptPath === '' || str_contains($promptPath, '..')) {
            throw new RuntimeException(sprintf('Ruta de prompt inválida: "%s".', $promptPath));
        }

        return $promptPath;
    }
}

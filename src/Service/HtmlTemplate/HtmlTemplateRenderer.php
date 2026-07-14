<?php

declare(strict_types=1);

namespace App\Service\HtmlTemplate;

use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Loader\ArrayLoader;

final class HtmlTemplateRenderer
{
    public function renderTemplate(string $template, array $context): string
    {
        $twig = new Environment(new ArrayLoader(), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => false,
        ]);

        try {
            return $twig->createTemplate($template)->render($context);
        } catch (TwigError $exception) {
            throw new \RuntimeException('No se pudo renderizar la plantilla HTML: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $jsonData
     * @return array<string, mixed>
     */
    public function buildContext(array $jsonData): array
    {
        $context = [
            'json' => $jsonData,
            'data' => $jsonData,
        ];

        foreach ($jsonData as $key => $value) {
            if (!array_key_exists((string) $key, $context)) {
                $context[(string) $key] = $value;
            }
        }

        return $context;
    }
}

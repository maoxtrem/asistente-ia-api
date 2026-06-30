<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

use App\DTO\CanvasGenerationRequest;

final class CanvasImagePromptBuilder
{
    public function buildUserPrompt(CanvasGenerationRequest $request): string
    {
        return trim(implode("\n", [
            'Generate one image.',
            'Tenant: ' . $request->tenant,
            'Locale: ' . $request->locale,
            'Request: ' . $request->message,
            'Style: polished, modern, high-quality, marketing-ready, easy to understand.',
        ]));
    }
}

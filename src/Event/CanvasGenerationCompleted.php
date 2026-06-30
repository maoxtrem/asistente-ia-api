<?php

declare(strict_types=1);

namespace App\Event;

use App\DTO\CanvasGenerationRequest;
use App\DTO\CanvasGenerationResponse;

final readonly class CanvasGenerationCompleted
{
    /**
     * @param array<string, mixed> $vectorContext
     */
    public function __construct(
        public CanvasGenerationRequest $request,
        public CanvasGenerationResponse $response,
        public array $vectorContext,
    ) {
    }
}

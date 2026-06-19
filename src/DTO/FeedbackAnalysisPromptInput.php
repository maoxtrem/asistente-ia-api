<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class FeedbackAnalysisPromptInput
{
    public function __construct(
        public string $question,
        public string $answer,
        public array $history,
        public array $context,
        public string $tenant,
        public string $locale,
    ) {
    }
}

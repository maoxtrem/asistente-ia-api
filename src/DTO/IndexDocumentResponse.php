<?php

declare(strict_types=1);

namespace App\DTO;

final class IndexDocumentResponse
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?string $collection = null,
        public readonly ?string $pointId = null,
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'collection' => $this->collection,
            'point_id' => $this->pointId,
            'raw' => $this->raw,
        ];
    }
}

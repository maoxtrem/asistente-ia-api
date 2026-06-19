<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\EmbeddingProviderInterface;
use App\DTO\IndexDocument;
use App\DTO\IndexDocumentResponse;
use RuntimeException;

final class IndexDocumentProcessor
{
    public function __construct(
        private readonly EmbeddingProviderInterface $embeddingClient,
        private readonly QdrantClient $qdrantClient,
        private readonly string $qdrantCollection,
    ) {
    }

    public function process(IndexDocument $document): IndexDocumentResponse
    {
        if ($document->id === '' || $document->type === '' || $document->source === '' || $document->tenant === '') {
            throw new RuntimeException('Los campos id, type, source y tenant son obligatorios para indexar.');
        }

        if ($document->isDeletion()) {
            $pointId = $this->qdrantClient->stablePointId($document->indexKey());
            $this->qdrantClient->deletePoint($this->qdrantCollection, $pointId);

            return new IndexDocumentResponse(
                ok: true,
                message: 'Documento eliminado del indice vectorial.',
                collection: $this->qdrantCollection,
                pointId: $pointId,
                raw: [
                    'operation' => 'delete',
                ],
            );
        }

        $text = $document->toText();
        if ($text === '') {
            throw new RuntimeException('El documento no contiene contenido util para vectorizar.');
        }

        $vector = $this->embeddingClient->embed($text);
        $this->qdrantClient->ensureCollection($this->qdrantCollection, count($vector));

        $pointId = $this->qdrantClient->stablePointId($document->indexKey());
        $metadata = $document->metadata;
        $documentKind = trim((string) ($metadata['document_kind'] ?? ''));
        $payload = $document->toArray() + [
            'index_key' => $document->indexKey(),
            'indexed_text' => $text,
        ];

        if ($documentKind !== '' && !isset($payload['document_kind'])) {
            $payload['document_kind'] = $documentKind;
        }

        $upsert = $this->qdrantClient->upsertPoint(
            collection: $this->qdrantCollection,
            pointId: $pointId,
            vector: $vector,
            payload: $payload,
        );

        return new IndexDocumentResponse(
            ok: true,
            message: 'Documento indexado correctamente.',
            collection: $this->qdrantCollection,
            pointId: $pointId,
            raw: $upsert,
        );
    }
}

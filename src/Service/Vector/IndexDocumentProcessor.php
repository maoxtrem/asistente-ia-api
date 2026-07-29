<?php

declare(strict_types=1);

namespace App\Service\Vector;

use App\Contract\EmbeddingProviderInterface;
use App\DTO\IndexDocument;
use App\DTO\IndexDocumentResponse;
use Qdrant\Models\PointStruct;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\Request\CreateCollection;
use Qdrant\Models\Request\VectorParams;
use Qdrant\Models\VectorStruct;
use Qdrant\Qdrant;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class IndexDocumentProcessor
{
    public function __construct(
        private readonly EmbeddingProviderInterface $embeddingClient,
        private readonly Qdrant $qdrant,
        private readonly string $qdrantCollection,
    ) {
    }

    public function process(IndexDocument $document): IndexDocumentResponse
    {
        if ($document->id === '' || $document->type === '' || $document->source === '' || $document->tenant === '') {
            throw new RuntimeException('Los campos id, type, source y tenant son obligatorios para indexar.');
        }

        if ($document->isDeletion()) {
            $pointId = $this->stablePointId($document->indexKey());
            $this->qdrant->collections($this->qdrantCollection)->points()->delete([$pointId], ['wait' => true]);

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
        $collection = $this->qdrant->collections($this->qdrantCollection);
        $exists = $collection->exists();

        if (($exists['result']['exists'] ?? false) !== true) {
            $collection->create(
                (new CreateCollection())->addVector(
                    new VectorParams(count($vector), VectorParams::DISTANCE_COSINE)
                )
            );
        }

        $pointId = $this->stablePointId($document->indexKey());
        $metadata = $document->metadata;
        $documentKind = trim((string) ($metadata['document_kind'] ?? ''));
        $payload = $document->toArray() + [
            'index_key' => $document->indexKey(),
            'indexed_text' => $text,
        ];

        if ($documentKind !== '' && !isset($payload['document_kind'])) {
            $payload['document_kind'] = $documentKind;
        }

        if ($document->isGlobal && !isset($payload['is_global'])) {
            $payload['is_global'] = true;
        }

        $points = new PointsStruct();
        $points->addPoint(new PointStruct(
            $pointId,
            new VectorStruct(array_values($vector)),
            $payload
        ));

        $upsert = $collection->points()->upsert($points, ['wait' => true]);

        return new IndexDocumentResponse(
            ok: true,
            message: 'Documento indexado correctamente.',
            collection: $this->qdrantCollection,
            pointId: $pointId,
            raw: [
                'status' => $upsert['status'] ?? 'ok',
                'result' => $upsert['result'] ?? [],
            ],
        );
    }

    private function stablePointId(string $seed): string
    {
        $namespace = Uuid::fromString(Uuid::NAMESPACE_URL);

        return Uuid::v5($namespace, $seed)->toRfc4122();
    }
}

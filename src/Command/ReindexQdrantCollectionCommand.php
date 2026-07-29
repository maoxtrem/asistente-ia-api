<?php

declare(strict_types=1);

namespace App\Command;

use App\Contract\EmbeddingProviderInterface;
use Qdrant\Models\Filter\Condition\MatchString;
use Qdrant\Models\Filter\Filter;
use Qdrant\Models\PointStruct;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\Request\CreateCollection;
use Qdrant\Models\Request\ScrollRequest;
use Qdrant\Models\Request\VectorParams;
use Qdrant\Models\VectorStruct;
use Qdrant\Qdrant;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:qdrant-reindex',
    description: 'Reindexa los puntos almacenados en Qdrant usando el proveedor de embeddings activo.',
)]
final class ReindexQdrantCollectionCommand extends Command
{
    public function __construct(
        private readonly Qdrant $qdrant,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly string $qdrantCollection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source-collection', null, InputOption::VALUE_OPTIONAL, 'Coleccion origen a leer.', $this->qdrantCollection)
            ->addOption('target-collection', null, InputOption::VALUE_OPTIONAL, 'Coleccion destino a escribir.', $this->qdrantCollection)
            ->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Filtra por tenant.', '')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Cantidad de puntos por pagina.', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceCollection = trim((string) $input->getOption('source-collection'));
        $targetCollection = trim((string) $input->getOption('target-collection'));
        $tenant = trim((string) $input->getOption('tenant'));
        $limit = max(1, (int) $input->getOption('limit'));

        if ($sourceCollection === '' || $targetCollection === '') {
            $io->error('Las colecciones de origen y destino no pueden estar vacias.');

            return self::FAILURE;
        }

        $io->title('Reindexando puntos de Qdrant');
        $io->writeln(sprintf('Origen: <info>%s</info>', $sourceCollection));
        $io->writeln(sprintf('Destino: <info>%s</info>', $targetCollection));
        if ($tenant !== '') {
            $io->writeln(sprintf('Tenant: <info>%s</info>', $tenant));
        }

        $totalProcessed = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        $offset = null;
        $vectorSize = null;

        while (true) {
            $page = $this->scrollPoints($sourceCollection, $limit, $offset, $tenant !== '' ? $tenant : null);
            $points = $page['points'];

            if ($points === []) {
                break;
            }

            $batch = [];
            foreach ($points as $point) {
                $pointId = (string) ($point['id'] ?? '');
                $payload = is_array($point['payload'] ?? null) ? $point['payload'] : [];
                $text = $this->buildReindexText($payload);

                if ($pointId === '' || $text === '') {
                    $totalSkipped++;
                    continue;
                }

                try {
                    $vector = $this->embeddingProvider->embed($text);
                    if ($vectorSize === null) {
                        $vectorSize = count($vector);
                        $this->ensureCollection($targetCollection, $vectorSize);
                    }
                    $batch[] = [
                        'id' => $pointId,
                        'vector' => $vector,
                        'payload' => $payload,
                    ];
                } catch (\Throwable $exception) {
                    $totalErrors++;
                    $io->warning(sprintf('Punto %s: %s', $pointId, $exception->getMessage()));
                }
            }

            if ($batch !== []) {
                try {
                    $this->upsertPointsBatch($targetCollection, $batch);
                    $totalProcessed += count($batch);
                } catch (\Throwable $exception) {
                    $totalErrors += count($batch);
                    $io->warning(sprintf(
                        'Lote de %d puntos: %s',
                        count($batch),
                        $exception->getMessage()
                    ));
                }
            }

            $offset = $page['next_page_offset'];
            if ($offset === null) {
                break;
            }
        }

        $io->success(sprintf(
            'Reindexado completado. Procesados: %d, omitidos: %d, errores: %d%s',
            $totalProcessed,
            $totalSkipped,
            $totalErrors,
            $vectorSize !== null ? sprintf(', vector_size: %d', $vectorSize) : ''
        ));

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildReindexText(array $payload): string
    {
        $indexedText = trim((string) ($payload['indexed_text'] ?? ''));
        if ($indexedText !== '') {
            return $indexedText;
        }

        $parts = array_filter([
            trim((string) ($payload['title'] ?? '')),
            trim((string) ($payload['content'] ?? '')),
            $this->normalizeMetadata($payload['metadata'] ?? null),
        ], static fn (string $value): bool => $value !== '');

        return trim(implode("\n\n", $parts));
    }

    private function normalizeMetadata(mixed $metadata): string
    {
        if (!is_array($metadata) || $metadata === []) {
            return '';
        }

        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? trim($encoded) : '';
    }

    /**
     * @return array{
     *   points: array<int, array{id:string, payload:array<string, mixed>}>,
     *   next_page_offset: int|string|null
     * }
     */
    private function scrollPoints(string $collection, int $limit = 50, int|string|null $offset = null, ?string $tenant = null): array
    {
        $request = new ScrollRequest();
        $request
            ->setLimit(max(1, $limit))
            ->setWithPayload(true)
            ->setWithVector(false);

        if ($offset !== null) {
            $request->setOffset($offset);
        }

        $tenant = trim((string) ($tenant ?? ''));
        if ($tenant !== '') {
            $filter = (new Filter())->addMust(new MatchString('tenant', $tenant));
            $request->setFilter($filter);
        }

        $response = $this->qdrant->collections($collection)->points()->scroll($request);
        $payload = $response['result'] ?? [];

        if (!is_array($payload)) {
            return [
                'points' => [],
                'next_page_offset' => null,
            ];
        }

        $points = $payload['points'] ?? [];
        if (!is_array($points)) {
            $points = [];
        }

        return [
            'points' => array_values(array_map(static function (array $item): array {
                return [
                    'id' => (string) ($item['id'] ?? ''),
                    'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : [],
                ];
            }, $points)),
            'next_page_offset' => $payload['next_page_offset'] ?? null,
        ];
    }

    private function ensureCollection(string $collectionName, int $vectorSize): void
    {
        $collection = $this->qdrant->collections($collectionName);
        $exists = $collection->exists();

        if (($exists['result']['exists'] ?? false) === true) {
            return;
        }

        $collection->create(
            (new CreateCollection())->addVector(
                new VectorParams($vectorSize, VectorParams::DISTANCE_COSINE)
            )
        );
    }

    /**
     * @param array<int, array{id:string|int, vector:float[], payload:array<string, mixed>}> $points
     */
    private function upsertPointsBatch(string $collection, array $points): void
    {
        if ($points === []) {
            return;
        }

        $pointsStruct = new PointsStruct();
        foreach ($points as $point) {
            $pointsStruct->addPoint(new PointStruct(
                $point['id'],
                new VectorStruct(array_values($point['vector'])),
                $point['payload'],
            ));
        }

        $this->qdrant->collections($collection)->points()->upsert($pointsStruct, ['wait' => true]);
    }
}

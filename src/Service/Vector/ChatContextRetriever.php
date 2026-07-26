<?php

declare(strict_types=1);

namespace App\Service\Vector;

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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class ChatContextRetriever
{
    public function __construct(
        #[Autowire(service: 'qdrant.official_client')]
        private Qdrant $qdrant,
        #[Autowire('%app.chat_qdrant_pdf_collection%')]
        private string $collectionName,
        private EmbeddingProviderInterface $embeddingProvider,
    ) {
    }

    /**
     * Recupera el historial cronológico de una sesión específica.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function getHistoryBySession(string $chatId, int $limit = 6): array
    {
        $chatId = trim($chatId);
        if ($chatId === '' || $limit < 1) {
            return [];
        }

        $filter = new Filter();
        $filter->addMust(new MatchString('session_id', $chatId));

        $request = new ScrollRequest();
        $request
            ->setFilter($filter)
            ->setLimit($limit)
            ->setWithPayload(true)
            ->setWithVector(false);

        $response = $this->qdrant
            ->collections($this->collectionName)
            ->points()
            ->scroll($request);

        $points = $response['result']['points'] ?? [];
        if (!is_array($points)) {
            return [];
        }

        $messages = [];
        foreach ($points as $point) {
            if (!is_array($point) || !is_array($point['payload'] ?? null)) {
                continue;
            }

            $payload = $point['payload'];
            $role = (string) ($payload['role'] ?? 'user');
            if ($role === 'assistant_html') {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => (string) ($payload['content'] ?? ''),
                'timestamp' => (int) ($payload['timestamp'] ?? 0),
            ];
        }

        usort($messages, static fn (array $first, array $second): int =>
            $first['timestamp'] <=> $second['timestamp']
        );

        return array_map(static fn (array $message): array => [
            'role' => $message['role'],
            'content' => $message['content'],
        ], $messages);
    }

    /**
     * Guarda un mensaje en el historial de Qdrant.
     *
     * La colección actual utiliza un vector sin nombre, por eso no se asigna
     * el nombre "message_vector" al VectorStruct.
     *
     */
    public function saveMessage(string $chatId, string $role, string $content, ?string $embeddingContent = null): void
    {
        $chatId = trim($chatId);
        $role = trim($role);
        $content = trim($content);

        if ($chatId === '' || $role === '' || $content === '') {
            return;
        }

        $vector = $this->embeddingProvider->embed($embeddingContent !== null ? $embeddingContent : $content);
        if ($vector === []) {
            return;
        }

        $normalizedVector = array_values(array_map(static fn (float|int $value): float => (float) $value, $vector));
        $collection = $this->qdrant->collections($this->collectionName);

        $exists = $collection->exists();
        if (($exists['result']['exists'] ?? false) !== true) {
            $collection->create(
                (new CreateCollection())->addVector(
                    new VectorParams(count($normalizedVector), VectorParams::DISTANCE_COSINE)
                )
            );
        }

        $point = new PointStruct(
            (string) Uuid::v4(),
            new VectorStruct($normalizedVector),
            [
                'session_id' => $chatId,
                'role' => $role,
                'content' => $content,
                'timestamp' => time(),
            ],
        );

        $points = new PointsStruct();
        $points->addPoint($point);

        $collection->points()->upsert($points, ['wait' => 'true']);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Vector;

use Qdrant\Models\Filter\Condition\MatchString;
use Qdrant\Models\Filter\Filter;
use Qdrant\Models\PointStruct;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\Request\ScrollRequest;
use Qdrant\Models\VectorStruct;
use Qdrant\Qdrant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class ChatContextRetriever
{
    public function __construct(
        #[Autowire(service: 'qdrant.official_client')]
        private Qdrant $qdrant,
        #[Autowire('%app.chat_qdrant_collection%')]
        private string $collectionName,
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
            $messages[] = [
                'role' => (string) ($payload['role'] ?? 'user'),
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
     * @param array<int, float|int> $vector
     */
    public function saveMessage(string $chatId, string $role, string $content, array $vector): void
    {
        $chatId = trim($chatId);
        $role = trim($role);
        $content = trim($content);

        if ($chatId === '' || $role === '' || $content === '' || $vector === []) {
            return;
        }

        $normalizedVector = array_map(static fn (float|int $value): float => (float) $value, $vector);
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

        $this->qdrant
            ->collections($this->collectionName)
            ->points()
            ->upsert($points, ['wait' => true]);
    }
}

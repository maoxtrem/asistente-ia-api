<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
final class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * @return list<ChatMessage>
     */
    public function findRecentByConversation(string $conversationId, string $tenant, int $limit = 20): array
    {
        /** @var list<ChatMessage> $messages */
        $messages = $this->createQueryBuilder('message')
            ->andWhere('message.conversationId = :conversationId')
            ->andWhere('message.tenant = :tenant')
            ->setParameter('conversationId', $conversationId)
            ->setParameter('tenant', $tenant)
            ->orderBy('message.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_reverse($messages);
    }
}

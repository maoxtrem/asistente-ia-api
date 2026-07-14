<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatFeedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatFeedback>
 */
final class ChatFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatFeedback::class);
    }
}

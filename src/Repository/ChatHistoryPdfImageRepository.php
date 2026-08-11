<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatHistoryPdfImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatHistoryPdfImage>
 */
final class ChatHistoryPdfImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatHistoryPdfImage::class);
    }
}

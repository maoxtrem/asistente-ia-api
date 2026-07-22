<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatKnowledgeCandidate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatKnowledgeCandidate>
 */
final class ChatKnowledgeCandidateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatKnowledgeCandidate::class);
    }

    public function findOneByCandidateKey(string $candidateKey): ?ChatKnowledgeCandidate
    {
        /** @var ChatKnowledgeCandidate|null $candidate */
        $candidate = $this->findOneBy(['candidateKey' => $candidateKey]);

        return $candidate;
    }
}

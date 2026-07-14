<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HtmlTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HtmlTemplate>
 */
final class HtmlTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HtmlTemplate::class);
    }

    public function findByUuid(string $uuid): ?HtmlTemplate
    {
        /** @var HtmlTemplate|null $template */
        $template = $this->findOneBy(['uuid' => $uuid]);

        return $template;
    }

    /**
     * @return list<HtmlTemplate>
     */
    public function findAllOrdered(): array
    {
        /** @var list<HtmlTemplate> $templates */
        $templates = $this->createQueryBuilder('template')
            ->orderBy('template.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $templates;
    }
}

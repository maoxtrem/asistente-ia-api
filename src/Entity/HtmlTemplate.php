<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\HtmlTemplateRepository')]
#[ORM\Table(name: 'html_templates')]
class HtmlTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(name: 'html_content', type: 'text')]
    private string $htmlContent;

    #[ORM\Column(name: 'json_content', type: 'json')]
    private array $jsonContent = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $jsonContent
     */
    public function __construct(string $uuid, string $name, string $htmlContent, array $jsonContent = [])
    {
        $now = new \DateTimeImmutable();

        $this->uuid = $uuid;
        $this->name = $name;
        $this->htmlContent = $htmlContent;
        $this->jsonContent = $jsonContent;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHtmlContent(): string
    {
        return $this->htmlContent;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJsonContent(): array
    {
        return $this->jsonContent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

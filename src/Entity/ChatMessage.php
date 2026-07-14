<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'chat_messages')]
class ChatMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'conversation_id', length: 32)]
    private string $conversationId;

    #[ORM\Column(length: 120)]
    private string $tenant;

    #[ORM\Column(length: 20)]
    private string $role;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(type: 'json', nullable: true)]
    private array $metadata = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(string $conversationId, string $tenant, string $role, string $content, array $metadata, \DateTimeImmutable $createdAt)
    {
        $this->conversationId = $conversationId;
        $this->tenant = $tenant;
        $this->role = $role;
        $this->content = $content;
        $this->metadata = $metadata;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

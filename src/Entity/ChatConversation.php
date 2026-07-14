<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\ChatConversationRepository')]
#[ORM\Table(name: 'chat_conversations')]
class ChatConversation
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 32)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $tenant;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'last_message_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    public function __construct(string $id, string $tenant, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->tenant = $tenant;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->lastMessageAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenant(): string
    {
        return $this->tenant;
    }

    public function setTenant(string $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
        $this->lastMessageAt = $now;
    }
}

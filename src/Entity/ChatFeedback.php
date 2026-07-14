<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'chat_feedback')]
class ChatFeedback
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'conversation_id', length: 32)]
    private string $conversationId;

    #[ORM\Column(length: 120)]
    private string $tenant;

    #[ORM\Column(type: 'boolean')]
    private bool $helpful;

    #[ORM\Column(type: 'text')]
    private string $question;

    #[ORM\Column(type: 'text')]
    private string $answer;

    #[ORM\Column(type: 'json', nullable: true)]
    private array $metadata = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(string $conversationId, string $tenant, bool $helpful, string $question, string $answer, array $metadata, \DateTimeImmutable $createdAt)
    {
        $this->conversationId = $conversationId;
        $this->tenant = $tenant;
        $this->helpful = $helpful;
        $this->question = $question;
        $this->answer = $answer;
        $this->metadata = $metadata;
        $this->createdAt = $createdAt;
    }
}

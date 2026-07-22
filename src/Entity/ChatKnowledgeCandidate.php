<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\ChatKnowledgeCandidateRepository')]
#[ORM\Table(name: 'chat_knowledge_candidates')]
class ChatKnowledgeCandidate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'candidate_key', length: 64, unique: true)]
    private string $candidateKey;

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

    #[ORM\Column(length: 30)]
    private string $status;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 4, nullable: true)]
    private ?string $confidence = null;

    #[ORM\Column(name: 'should_index', type: 'boolean', nullable: true)]
    private ?bool $shouldIndex = null;

    #[ORM\Column(name: 'duplicate_of', length: 64, nullable: true)]
    private ?string $duplicateOf = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private array $analysis = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private array $metadata = [];

    #[ORM\Column(name: 'indexed_point_id', length: 64, nullable: true)]
    private ?string $indexedPointId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'indexed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $indexedAt = null;

    public function __construct(string $candidateKey, string $conversationId, string $tenant, bool $helpful, string $question, string $answer, string $status, \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt)
    {
        $this->candidateKey = $candidateKey;
        $this->conversationId = $conversationId;
        $this->tenant = $tenant;
        $this->helpful = $helpful;
        $this->question = $question;
        $this->answer = $answer;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getCandidateKey(): string
    {
        return $this->candidateKey;
    }

    public function setConversationId(string $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function setTenant(string $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function setHelpful(bool $helpful): void
    {
        $this->helpful = $helpful;
    }

    public function setQuestion(string $question): void
    {
        $this->question = $question;
    }

    public function setAnswer(string $answer): void
    {
        $this->answer = $answer;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function setSummary(?string $summary): void
    {
        $this->summary = $summary;
    }

    public function setContent(?string $content): void
    {
        $this->content = $content;
    }

    public function setLanguage(?string $language): void
    {
        $this->language = $language;
    }

    public function setConfidence(?string $confidence): void
    {
        $this->confidence = $confidence;
    }

    public function setShouldIndex(?bool $shouldIndex): void
    {
        $this->shouldIndex = $shouldIndex;
    }

    public function setDuplicateOf(?string $duplicateOf): void
    {
        $this->duplicateOf = $duplicateOf;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public function setAnalysis(array $analysis): void
    {
        $this->analysis = $analysis;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function setIndexedAt(?\DateTimeImmutable $indexedAt): void
    {
        $this->indexedAt = $indexedAt;
    }

    public function setIndexedPointId(?string $indexedPointId): void
    {
        $this->indexedPointId = $indexedPointId;
    }
}

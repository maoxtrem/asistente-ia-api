<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatHistoryPdfRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatHistoryPdfRepository::class)]
#[ORM\Table(name: 'chat_history_pdf')]
class ChatHistoryPdf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'record_type', length: 20)]
    private string $recordType;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $intent;

    #[ORM\Column(name: 'chat_id', length: 255)]
    private string $chatId;

    #[ORM\Column(name: 'user_identifier', length: 255)]
    private string $userIdentifier;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message;

    #[ORM\Column(name: 'tool_enabled', type: 'boolean', nullable: true)]
    private ?bool $toolEnabled;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $tenant;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $locale;

    #[ORM\Column(type: 'json', nullable: true)]
    private array $session;

    #[ORM\Column(type: 'json', nullable: true)]
    private array $history;

    #[ORM\Column(name: 'attachment_key', length: 255, nullable: true)]
    private ?string $attachmentKey;

    #[ORM\Column(name: 'attachment_zip_key', length: 255, nullable: true)]
    private ?string $attachmentZipKey;

    #[ORM\Column(name: 'mercure_topic', length: 255, nullable: true)]
    private ?string $mercureTopic;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content;

    #[ORM\Column(name: 'content_json', type: 'json', nullable: true)]
    private ?array $contentJson;

    #[ORM\Column(name: 'pdf_url', length: 255, nullable: true)]
    private ?string $pdfUrl;

    #[ORM\Column(name: 'original_name_attachment', length: 255, nullable: true)]
    private ?string $originalNameAttachment;

    #[ORM\Column(name: 'attachment_path', length: 255, nullable: true)]
    private ?string $attachmentPath;

    #[ORM\Column(name: 'is_locked', type: 'boolean', nullable: true)]
    private ?bool $isLocked;

    /**
     * @param array<string, mixed> $session
     * @param array<int, array<string, mixed>> $history
     */
    public function __construct(
        string $chatId,
        string $userIdentifier,
        string $recordType,
        ?string $intent = null,
        ?string $message = null,
        ?bool $toolEnabled = null,
        ?string $tenant = null,
        ?string $locale = null,
        array $session = [],
        array $history = [],
        ?string $attachmentKey = null,
        ?string $mercureTopic = null,
        ?\DateTimeImmutable $createdAt = null,
        ?string $content = null,
        ?array $contentJson = null,
        ?string $pdfUrl = null,
        ?string $originalNameAttachment = null,
        ?string $attachmentPath = null,
        ?bool $isLocked = null,
    ) {
        $this->recordType = $recordType;
        $this->intent = $intent;
        $this->chatId = $chatId;
        $this->userIdentifier = $userIdentifier;
        $this->message = $message;
        $this->toolEnabled = $toolEnabled;
        $this->tenant = $tenant;
        $this->locale = $locale;
        $this->session = $session;
        $this->history = $history;
        $this->attachmentKey = $attachmentKey;
        $this->attachmentZipKey = null;
        $this->mercureTopic = $mercureTopic;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->content = $content;
        $this->contentJson = $contentJson;
        $this->pdfUrl = $pdfUrl;
        $this->originalNameAttachment = $originalNameAttachment;
        $this->attachmentPath = $attachmentPath;
        $this->isLocked = $isLocked;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecordType(): string
    {
        return $this->recordType;
    }

    public function getIntent(): ?string
    {
        return $this->intent;
    }

    public function getChatId(): string
    {
        return $this->chatId;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function isToolEnabled(): ?bool
    {
        return $this->toolEnabled;
    }

    public function getTenant(): ?string
    {
        return $this->tenant;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /** @return array<string, mixed> */
    public function getSession(): array
    {
        return $this->session;
    }

    /** @return array<int, array<string, mixed>> */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function getAttachmentKey(): ?string
    {
        return $this->attachmentKey;
    }

    public function getAttachmentZipKey(): ?string
    {
        return $this->attachmentZipKey;
    }

    public function setAttachmentZipKey(?string $attachmentZipKey): void
    {
        $this->attachmentZipKey = $attachmentZipKey;
    }

    public function getMercureTopic(): ?string
    {
        return $this->mercureTopic;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    /** @return array<string, mixed>|null */
    public function getContentJson(): ?array
    {
        return $this->contentJson;
    }

    public function getPdfUrl(): ?string
    {
        return $this->pdfUrl;
    }

    public function getOriginalNameAttachment(): ?string
    {
        return $this->originalNameAttachment;
    }

    public function getAttachmentPath(): ?string
    {
        return $this->attachmentPath;
    }

    public function isLocked(): ?bool
    {
        return $this->isLocked;
    }
}

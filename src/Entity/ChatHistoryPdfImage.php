<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatHistoryPdfImageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatHistoryPdfImageRepository::class)]
#[ORM\Table(name: 'chat_history_pdf_images')]
#[ORM\UniqueConstraint(name: 'uniq_chat_history_pdf_image_key', columns: ['chat_history_pdf_id', 'image_key'])]
#[ORM\Index(name: 'idx_chat_history_pdf_images_history', columns: ['chat_history_pdf_id'])]
class ChatHistoryPdfImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatHistoryPdf::class)]
    #[ORM\JoinColumn(name: 'chat_history_pdf_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ChatHistoryPdf $chatHistoryPdf;

    #[ORM\Column(name: 'image_key', length: 255)]
    private string $imageKey;

    #[ORM\Column(name: 'image_name', length: 255)]
    private string $imageName;

    #[ORM\Column(name: 'image_number', type: 'integer')]
    private int $imageNumber;

    #[ORM\Column(name: 'mime_type', length: 100)]
    private string $mimeType;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $approved = null;

    #[ORM\Column(name: 'document_type', length: 20, nullable: true)]
    private ?string $documentType = null;

    #[ORM\Column(name: 'context_general_analyzed', type: 'boolean', nullable: true)]
    private ?bool $contextGeneralAnalyzed = null;

    #[ORM\Column(name: 'context_genera_json', type: 'json', nullable: true)]
    private ?array $contextGeneraJson = null;

    #[ORM\Column(name: 'materials_systems_analyzed', type: 'boolean', nullable: true)]
    private ?bool $materialsSystemsAnalyzed = null;

    #[ORM\Column(name: 'materials_systems_json', type: 'json', nullable: true)]
    private ?array $materialsSystemsJson = null;

    #[ORM\Column(name: 'geometry_quantities_analyzed', type: 'boolean', nullable: true)]
    private ?bool $geometryQuantitiesAnalyzed = null;

    #[ORM\Column(name: 'geometry_quantities_json', type: 'json', nullable: true)]
    private ?array $geometryQuantitiesJson = null;

    #[ORM\Column(name: 'docling_json', type: 'json', nullable: true)]
    private ?array $doclingJson = null;

    #[ORM\Column(name: 'confidence_score', type: 'integer', nullable: true)]
    private ?int $confidenceScore = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reasoning = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ChatHistoryPdf $chatHistoryPdf,
        string $imageKey,
        string $imageName,
        int $imageNumber,
        string $mimeType,
    ) {
        $this->chatHistoryPdf = $chatHistoryPdf;
        $this->imageKey = $imageKey;
        $this->imageName = $imageName;
        $this->imageNumber = $imageNumber;
        $this->mimeType = $mimeType;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChatHistoryPdf(): ChatHistoryPdf
    {
        return $this->chatHistoryPdf;
    }

    public function getImageKey(): string
    {
        return $this->imageKey;
    }

    public function getImageName(): string
    {
        return $this->imageName;
    }

    public function getImageNumber(): int
    {
        return $this->imageNumber;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getApproved(): ?bool
    {
        return $this->approved;
    }

    public function setApproved(?bool $approved): void
    {
        $this->approved = $approved;
    }

    public function getDocumentType(): ?string
    {
        return $this->documentType;
    }

    public function setDocumentType(?string $documentType): void
    {
        $this->documentType = $documentType;
    }

    public function getContextGeneralAnalyzed(): ?bool
    {
        return $this->contextGeneralAnalyzed;
    }

    public function setContextGeneralAnalyzed(?bool $contextGeneralAnalyzed): void
    {
        $this->contextGeneralAnalyzed = $contextGeneralAnalyzed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContextGeneraJson(): ?array
    {
        return $this->contextGeneraJson;
    }

    /**
     * @param array<string, mixed>|null $contextGeneraJson
     */
    public function setContextGeneraJson(?array $contextGeneraJson): void
    {
        $this->contextGeneraJson = $contextGeneraJson;
    }

    public function getMaterialsSystemsAnalyzed(): ?bool
    {
        return $this->materialsSystemsAnalyzed;
    }

    public function setMaterialsSystemsAnalyzed(?bool $materialsSystemsAnalyzed): void
    {
        $this->materialsSystemsAnalyzed = $materialsSystemsAnalyzed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMaterialsSystemsJson(): ?array
    {
        return $this->materialsSystemsJson;
    }

    /**
     * @param array<string, mixed>|null $materialsSystemsJson
     */
    public function setMaterialsSystemsJson(?array $materialsSystemsJson): void
    {
        $this->materialsSystemsJson = $materialsSystemsJson;
    }

    public function getGeometryQuantitiesAnalyzed(): ?bool
    {
        return $this->geometryQuantitiesAnalyzed;
    }

    public function setGeometryQuantitiesAnalyzed(?bool $geometryQuantitiesAnalyzed): void
    {
        $this->geometryQuantitiesAnalyzed = $geometryQuantitiesAnalyzed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGeometryQuantitiesJson(): ?array
    {
        return $this->geometryQuantitiesJson;
    }

    /**
     * @param array<string, mixed>|null $geometryQuantitiesJson
     */
    public function setGeometryQuantitiesJson(?array $geometryQuantitiesJson): void
    {
        $this->geometryQuantitiesJson = $geometryQuantitiesJson;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDoclingJson(): ?array
    {
        return $this->doclingJson;
    }

    /**
     * @param array<string, mixed>|null $doclingJson
     */
    public function setDoclingJson(?array $doclingJson): void
    {
        $this->doclingJson = $doclingJson;
    }

    public function getConfidenceScore(): ?int
    {
        return $this->confidenceScore;
    }

    public function setConfidenceScore(?int $confidenceScore): void
    {
        $this->confidenceScore = $confidenceScore;
    }

    public function getReasoning(): ?string
    {
        return $this->reasoning;
    }

    public function setReasoning(?string $reasoning): void
    {
        $this->reasoning = $reasoning;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

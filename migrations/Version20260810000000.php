<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guarda las imágenes extraídas de los ZIP del historial ChatTool PDF.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE chat_history_pdf_images (
    id BIGINT AUTO_INCREMENT NOT NULL,
    chat_history_pdf_id BIGINT NOT NULL,
    image_key VARCHAR(255) NOT NULL,
    image_name VARCHAR(255) NOT NULL,
    image_number INT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_chat_history_pdf_image_key (chat_history_pdf_id, image_key),
    INDEX idx_chat_history_pdf_images_history (chat_history_pdf_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_CHAT_HISTORY_PDF_IMAGES_HISTORY FOREIGN KEY (chat_history_pdf_id) REFERENCES chat_history_pdf (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_history_pdf_images');
    }
}

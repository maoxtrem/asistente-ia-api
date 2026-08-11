<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega los campos de salida del asistente al historial ChatTool PDF.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE chat_history_pdf
    ADD assistant_chat_id VARCHAR(255) DEFAULT NULL,
    ADD assistant_user_identifier VARCHAR(255) DEFAULT NULL,
    ADD assistant_content LONGTEXT DEFAULT NULL,
    ADD assistant_pdf_url VARCHAR(255) DEFAULT NULL,
    ADD assistant_mercure_topic VARCHAR(255) DEFAULT NULL,
    ADD assistant_original_name_attachment VARCHAR(255) DEFAULT NULL,
    ADD assistant_attachment_path VARCHAR(255) DEFAULT NULL,
    ADD assistant_is_locked TINYINT(1) DEFAULT 0 NOT NULL,
    ADD assistant_created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE chat_history_pdf
    DROP assistant_chat_id,
    DROP assistant_user_identifier,
    DROP assistant_content,
    DROP assistant_pdf_url,
    DROP assistant_mercure_topic,
    DROP assistant_original_name_attachment,
    DROP assistant_attachment_path,
    DROP assistant_is_locked,
    DROP assistant_created_at
SQL);
    }
}

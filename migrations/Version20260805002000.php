<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separa los registros de usuario y asistente reutilizando los campos comunes del historial PDF.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE chat_history_pdf
    ADD record_type VARCHAR(20) NOT NULL DEFAULT 'user',
    ADD content LONGTEXT DEFAULT NULL,
    ADD pdf_url VARCHAR(255) DEFAULT NULL,
    ADD original_name_attachment VARCHAR(255) DEFAULT NULL,
    ADD attachment_path VARCHAR(255) DEFAULT NULL,
    ADD is_locked TINYINT(1) DEFAULT NULL,
    MODIFY message LONGTEXT DEFAULT NULL,
    MODIFY tool_enabled TINYINT(1) DEFAULT NULL,
    MODIFY tenant VARCHAR(120) DEFAULT NULL,
    MODIFY locale VARCHAR(20) DEFAULT NULL,
    MODIFY session JSON DEFAULT NULL,
    MODIFY history JSON DEFAULT NULL,
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

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE chat_history_pdf
    DROP record_type,
    DROP content,
    DROP pdf_url,
    DROP original_name_attachment,
    DROP attachment_path,
    DROP is_locked,
    MODIFY message LONGTEXT NOT NULL,
    MODIFY tool_enabled TINYINT(1) NOT NULL,
    MODIFY tenant VARCHAR(120) NOT NULL,
    MODIFY locale VARCHAR(20) NOT NULL,
    MODIFY session JSON NOT NULL,
    MODIFY history JSON NOT NULL,
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
}

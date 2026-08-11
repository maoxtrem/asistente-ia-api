<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea la tabla de historial de mensajes del ChatTool PDF.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE chat_history_pdf (
    id BIGINT AUTO_INCREMENT NOT NULL,
    chat_id VARCHAR(255) NOT NULL,
    user_identifier VARCHAR(255) NOT NULL,
    message LONGTEXT NOT NULL,
    tool_enabled TINYINT(1) NOT NULL,
    tenant VARCHAR(120) NOT NULL,
    locale VARCHAR(20) NOT NULL,
    session JSON NOT NULL,
    history JSON NOT NULL,
    attachment_key VARCHAR(255) DEFAULT NULL,
    mercure_topic VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_chat_history_pdf_chat_created_at (chat_id, created_at),
    INDEX idx_chat_history_pdf_tenant (tenant),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_history_pdf');
    }
}

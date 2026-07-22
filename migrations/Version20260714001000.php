<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea las tablas Doctrine para historial, feedback y candidatos del chat.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE chat_conversations (
    id CHAR(32) NOT NULL,
    tenant VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    last_message_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY(id),
    INDEX idx_chat_conversations_tenant (tenant),
    INDEX idx_chat_conversations_updated_at (updated_at),
    INDEX idx_chat_conversations_last_message_at (last_message_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE chat_messages (
    id BIGINT AUTO_INCREMENT NOT NULL,
    conversation_id CHAR(32) NOT NULL,
    tenant VARCHAR(120) NOT NULL,
    role VARCHAR(20) NOT NULL,
    content LONGTEXT NOT NULL,
    metadata JSON DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_chat_messages_conversation_created_at (conversation_id, created_at),
    INDEX idx_chat_messages_tenant (tenant),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
ALTER TABLE chat_messages
    ADD CONSTRAINT fk_chat_messages_conversation
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations (id)
    ON DELETE CASCADE
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE chat_feedback (
    id BIGINT AUTO_INCREMENT NOT NULL,
    conversation_id CHAR(32) NOT NULL,
    tenant VARCHAR(120) NOT NULL,
    helpful TINYINT(1) NOT NULL,
    question LONGTEXT NOT NULL,
    answer LONGTEXT NOT NULL,
    metadata JSON DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_chat_feedback_conversation_created_at (conversation_id, created_at),
    INDEX idx_chat_feedback_tenant (tenant),
    INDEX idx_chat_feedback_helpful (helpful),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE chat_knowledge_candidates (
    id BIGINT AUTO_INCREMENT NOT NULL,
    candidate_key CHAR(64) NOT NULL,
    conversation_id CHAR(32) NOT NULL,
    tenant VARCHAR(120) NOT NULL,
    helpful TINYINT(1) NOT NULL,
    question LONGTEXT NOT NULL,
    answer LONGTEXT NOT NULL,
    status VARCHAR(30) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    summary LONGTEXT DEFAULT NULL,
    content LONGTEXT DEFAULT NULL,
    language VARCHAR(20) DEFAULT NULL,
    confidence NUMERIC(5, 4) DEFAULT NULL,
    should_index TINYINT(1) DEFAULT NULL,
    duplicate_of CHAR(64) DEFAULT NULL,
    analysis JSON DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    indexed_point_id CHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    indexed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uq_chat_knowledge_candidates_key (candidate_key),
    INDEX idx_chat_knowledge_candidates_conversation_created_at (conversation_id, created_at),
    INDEX idx_chat_knowledge_candidates_tenant (tenant),
    INDEX idx_chat_knowledge_candidates_status (status),
    INDEX idx_chat_knowledge_candidates_helpful (helpful),
    INDEX idx_chat_knowledge_candidates_indexed_at (indexed_at),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_knowledge_candidates');
        $this->addSql('DROP TABLE chat_feedback');
        $this->addSql('DROP TABLE chat_messages');
        $this->addSql('DROP TABLE chat_conversations');
    }
}

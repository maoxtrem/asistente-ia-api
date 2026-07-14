<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:init-db',
    description: 'Inicializa el esquema MySQL de historial y feedback del chat.',
)]
final class InitDatabaseCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS chat_conversations (
    id CHAR(32) NOT NULL,
    tenant VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_message_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_chat_conversations_tenant (tenant),
    KEY idx_chat_conversations_updated_at (updated_at),
    KEY idx_chat_conversations_last_message_at (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id CHAR(32) NOT NULL,
    tenant VARCHAR(120) NOT NULL,
    role VARCHAR(20) NOT NULL,
    content LONGTEXT NOT NULL,
    metadata JSON DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_chat_messages_conversation_created_at (conversation_id, created_at),
    KEY idx_chat_messages_tenant (tenant),
    CONSTRAINT fk_chat_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS chat_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id CHAR(32) NOT NULL,
    tenant VARCHAR(120) NOT NULL,
    helpful TINYINT(1) NOT NULL DEFAULT 0,
    question LONGTEXT NOT NULL,
    answer LONGTEXT NOT NULL,
    metadata JSON DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_chat_feedback_conversation_created_at (conversation_id, created_at),
    KEY idx_chat_feedback_tenant (tenant),
    KEY idx_chat_feedback_helpful (helpful)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS chat_knowledge_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_key CHAR(64) NOT NULL,
    conversation_id CHAR(32) NOT NULL,
    tenant VARCHAR(120) NOT NULL,
    helpful TINYINT(1) NOT NULL DEFAULT 0,
    question LONGTEXT NOT NULL,
    answer LONGTEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending_review',
    title VARCHAR(255) DEFAULT NULL,
    summary LONGTEXT DEFAULT NULL,
    content LONGTEXT DEFAULT NULL,
    language VARCHAR(20) DEFAULT NULL,
    confidence DECIMAL(5,4) DEFAULT NULL,
    should_index TINYINT(1) DEFAULT NULL,
    duplicate_of CHAR(64) DEFAULT NULL,
    analysis JSON DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    indexed_point_id CHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    indexed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_knowledge_candidates_key (candidate_key),
    KEY idx_chat_knowledge_candidates_conversation_created_at (conversation_id, created_at),
    KEY idx_chat_knowledge_candidates_tenant (tenant),
    KEY idx_chat_knowledge_candidates_status (status),
    KEY idx_chat_knowledge_candidates_helpful (helpful),
    KEY idx_chat_knowledge_candidates_indexed_at (indexed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS html_templates (
    id INT NOT NULL AUTO_INCREMENT,
    uuid VARCHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    html_content LONGTEXT NOT NULL,
    json_content JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_html_templates_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $output->writeln('<info>Base de datos inicializada correctamente.</info>');

        return Command::SUCCESS;
    }
}

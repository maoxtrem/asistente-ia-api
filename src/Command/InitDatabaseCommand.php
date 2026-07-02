<?php

declare(strict_types=1);

namespace App\Command;

use PDO;
use PDOException;
use RuntimeException;
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
        private readonly string $databaseUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->createPdo($this->databaseUrl);

        $pdo->exec(<<<'SQL'
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

        $pdo->exec(<<<'SQL'
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

        $pdo->exec(<<<'SQL'
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

        $pdo->exec(<<<'SQL'
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

        $output->writeln('<info>Base de datos inicializada correctamente.</info>');

        return Command::SUCCESS;
    }

    private function createPdo(string $databaseUrl): PDO
    {
        $parts = parse_url($databaseUrl);
        if (!is_array($parts)) {
            throw new RuntimeException('DATABASE_URL no tiene un formato valido.');
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        if (!in_array($scheme, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('DATABASE_URL debe usar mysql o mariadb.');
        }

        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (string) ($parts['port'] ?? '3306');
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $charset = (string) ($query['charset'] ?? 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $path, $charset);

        try {
            return new PDO(
                $dsn,
                isset($parts['user']) ? rawurldecode((string) $parts['user']) : null,
                isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(sprintf('No fue posible conectar con la base de datos: %s', $exception->getMessage()), 0, $exception);
        }
    }
}

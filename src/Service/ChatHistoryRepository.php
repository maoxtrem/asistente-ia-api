<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

final class ChatHistoryRepository
{
    private const CONVERSATION_ID_LENGTH = 32;

    private PDO $pdo;
    private bool $schemaReady = false;

    public function __construct(
        private readonly string $databaseUrl,
    ) {
        $this->pdo = $this->createPdo($databaseUrl);
    }

    public function ensureConversation(string $conversationId, string $tenant): void
    {
        $conversationId = $this->normalizeConversationId($conversationId);
        $this->initializeSchema();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $sql = <<<'SQL'
INSERT INTO chat_conversations (id, tenant, created_at, updated_at, last_message_at)
VALUES (:id, :tenant, :created_at, :updated_at, :last_message_at)
ON DUPLICATE KEY UPDATE
    tenant = VALUES(tenant),
    updated_at = VALUES(updated_at)
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $conversationId,
            ':tenant' => $tenant,
            ':created_at' => $now,
            ':updated_at' => $now,
            ':last_message_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function appendMessage(string $conversationId, string $tenant, string $role, string $content, array $metadata = []): void
    {
        $conversationId = $this->normalizeConversationId($conversationId);
        $this->initializeSchema();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $sql = <<<'SQL'
INSERT INTO chat_messages (conversation_id, tenant, role, content, metadata, created_at)
VALUES (:conversation_id, :tenant, :role, :content, :metadata, :created_at)
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':conversation_id' => $conversationId,
            ':tenant' => $tenant,
            ':role' => $role,
            ':content' => $content,
            ':metadata' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':created_at' => $now,
        ]);

        $this->touchConversation($conversationId, $tenant, $now);
    }

    /**
     * @return array<int, array{role:string, content:string, created_at:string, metadata:array<string, mixed>}>
     */
    public function fetchMessages(string $conversationId, string $tenant, int $limit = 20): array
    {
        $conversationId = $this->normalizeConversationId($conversationId);
        $this->initializeSchema();

        $sql = <<<'SQL'
SELECT role, content, metadata, created_at
FROM chat_messages
WHERE conversation_id = :conversation_id AND tenant = :tenant
ORDER BY id DESC
LIMIT :limit
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':conversation_id', $conversationId, PDO::PARAM_STR);
        $stmt->bindValue(':tenant', $tenant, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = array_reverse($rows);

        return array_map(static function (array $row): array {
            $metadata = [];
            if (isset($row['metadata']) && is_string($row['metadata']) && trim($row['metadata']) !== '') {
                $decoded = json_decode($row['metadata'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }

            return [
                'role' => (string) ($row['role'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'metadata' => $metadata,
            ];
        }, $rows);
    }

    public function conversationExists(string $conversationId, string $tenant): bool
    {
        $conversationId = $this->normalizeConversationId($conversationId);
        $this->initializeSchema();

        $stmt = $this->pdo->prepare('SELECT 1 FROM chat_conversations WHERE id = :id AND tenant = :tenant LIMIT 1');
        $stmt->execute([
            ':id' => $conversationId,
            ':tenant' => $tenant,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function touchConversation(string $conversationId, string $tenant, string $updatedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE chat_conversations SET updated_at = :updated_at, last_message_at = :last_message_at WHERE id = :id AND tenant = :tenant'
        );
        $stmt->execute([
            ':id' => $conversationId,
            ':tenant' => $tenant,
            ':updated_at' => $updatedAt,
            ':last_message_at' => $updatedAt,
        ]);
    }

    private function initializeSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
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

        $this->pdo->exec(<<<'SQL'
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

        $this->schemaReady = true;
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
            $pdo = new PDO(
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

        return $pdo;
    }

    private function normalizeConversationId(string $conversationId): string
    {
        $conversationId = trim($conversationId);

        if ($conversationId === '') {
            throw new RuntimeException('El identificador de conversacion no puede estar vacio.');
        }

        if (strlen($conversationId) !== self::CONVERSATION_ID_LENGTH) {
            throw new RuntimeException(sprintf(
                'El identificador de conversacion debe tener %d caracteres hexadecimales.',
                self::CONVERSATION_ID_LENGTH
            ));
        }

        if (!ctype_xdigit($conversationId)) {
            throw new RuntimeException('El identificador de conversacion debe ser hexadecimal.');
        }

        return strtolower($conversationId);
    }
}

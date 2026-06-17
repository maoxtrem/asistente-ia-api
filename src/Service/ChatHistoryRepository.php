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
     * @param array<string, mixed> $metadata
     */
    public function appendFeedback(string $conversationId, string $tenant, bool $helpful, string $question, string $answer, array $metadata = []): void
    {
        $conversationId = $this->normalizeConversationId($conversationId, false);
        $this->initializeSchema();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $sql = <<<'SQL'
INSERT INTO chat_feedback (conversation_id, tenant, helpful, question, answer, metadata, created_at)
VALUES (:conversation_id, :tenant, :helpful, :question, :answer, :metadata, :created_at)
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':conversation_id' => $conversationId,
            ':tenant' => $tenant,
            ':helpful' => $helpful ? 1 : 0,
            ':question' => $question,
            ':answer' => $answer,
            ':metadata' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':created_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $metadata
     */
    public function upsertKnowledgeCandidate(
        string $candidateKey,
        string $conversationId,
        string $tenant,
        bool $helpful,
        string $question,
        string $answer,
        string $status,
        array $analysis = [],
        array $metadata = []
    ): void {
        $conversationId = $this->normalizeConversationId($conversationId, false);
        $candidateKey = trim($candidateKey);
        if ($candidateKey === '') {
            throw new RuntimeException('El identificador del candidato no puede estar vacio.');
        }

        $this->initializeSchema();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $sql = <<<'SQL'
INSERT INTO chat_knowledge_candidates (
    candidate_key, conversation_id, tenant, helpful, question, answer, status, title, summary, content, language, confidence,
    should_index, duplicate_of, analysis, metadata, created_at, updated_at, indexed_at, indexed_point_id
)
VALUES (
    :candidate_key, :conversation_id, :tenant, :helpful, :question, :answer, :status, :title, :summary, :content, :language, :confidence,
    :should_index, :duplicate_of, :analysis, :metadata, :created_at, :updated_at, :indexed_at, :indexed_point_id
)
ON DUPLICATE KEY UPDATE
    conversation_id = VALUES(conversation_id),
    tenant = VALUES(tenant),
    helpful = VALUES(helpful),
    question = VALUES(question),
    answer = VALUES(answer),
    status = VALUES(status),
    title = VALUES(title),
    summary = VALUES(summary),
    content = VALUES(content),
    language = VALUES(language),
    confidence = VALUES(confidence),
    should_index = VALUES(should_index),
    duplicate_of = VALUES(duplicate_of),
    analysis = VALUES(analysis),
    metadata = VALUES(metadata),
    updated_at = VALUES(updated_at),
    indexed_at = VALUES(indexed_at),
    indexed_point_id = VALUES(indexed_point_id)
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':candidate_key' => $candidateKey,
            ':conversation_id' => $conversationId,
            ':tenant' => $tenant,
            ':helpful' => $helpful ? 1 : 0,
            ':question' => $question,
            ':answer' => $answer,
            ':status' => $status,
            ':title' => trim((string) ($analysis['title'] ?? '')),
            ':summary' => trim((string) ($analysis['summary'] ?? '')),
            ':content' => trim((string) ($analysis['content'] ?? '')),
            ':language' => trim((string) ($analysis['language'] ?? '')),
            ':confidence' => isset($analysis['confidence']) ? (float) $analysis['confidence'] : null,
            ':should_index' => isset($analysis['should_index']) ? ((bool) $analysis['should_index'] ? 1 : 0) : null,
            ':duplicate_of' => isset($analysis['duplicate_of']) ? trim((string) $analysis['duplicate_of']) : null,
            ':analysis' => $analysis !== [] ? json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':metadata' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':created_at' => $now,
            ':updated_at' => $now,
            ':indexed_at' => $this->normalizeDateTimeValue($analysis['indexed_at'] ?? null),
            ':indexed_point_id' => isset($analysis['indexed_point_id']) ? trim((string) $analysis['indexed_point_id']) : null,
        ]);
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

    public function conversationIdFromClientKey(string $tenant, string $clientKey): string
    {
        $tenant = trim($tenant);
        $clientKey = trim($clientKey);

        if ($tenant === '' || $clientKey === '') {
            throw new RuntimeException('tenant y clientKey son obligatorios para resolver la conversacion.');
        }

        return $this->normalizeConversationId(md5(mb_strtolower($tenant . '|' . $clientKey)));
    }

    /**
     * @return array{conversation_id:string, messages:array<int, array{role:string, content:string, created_at:string, metadata:array<string, mixed>}>}
     */
    public function bootstrapConversation(string $tenant, string $clientKey, int $limit = 20): array
    {
        $conversationId = $this->conversationIdFromClientKey($tenant, $clientKey);
        $this->ensureConversation($conversationId, $tenant);

        return [
            'conversation_id' => $conversationId,
            'messages' => $this->fetchMessages($conversationId, $tenant, $limit),
        ];
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

        $this->pdo->exec(<<<'SQL'
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

        $this->pdo->exec(<<<'SQL'
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

    private function normalizeConversationId(string $conversationId, bool $strict = true): string
    {
        $conversationId = trim($conversationId);

        if ($conversationId === '') {
            throw new RuntimeException('El identificador de conversacion no puede estar vacio.');
        }

        if (strlen($conversationId) !== self::CONVERSATION_ID_LENGTH) {
            if (!$strict) {
                return md5($conversationId);
            }

            throw new RuntimeException(sprintf(
                'El identificador de conversacion debe tener %d caracteres hexadecimales.',
                self::CONVERSATION_ID_LENGTH
            ));
        }

        if (!ctype_xdigit($conversationId)) {
            if (!$strict) {
                return md5($conversationId);
            }

            throw new RuntimeException('El identificador de conversacion debe ser hexadecimal.');
        }

        return strtolower($conversationId);
    }

    private function normalizeDateTimeValue(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        $formats = [
            'Y-m-d H:i:s',
            DateTimeImmutable::ATOM,
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s.uP',
            'Y-m-d\TH:i:s',
        ];

        foreach ($formats as $format) {
            $dateTime = DateTimeImmutable::createFromFormat($format, $normalized);
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime->format('Y-m-d H:i:s');
            }
        }

        try {
            return (new DateTimeImmutable($normalized))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}

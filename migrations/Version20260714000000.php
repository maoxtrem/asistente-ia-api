<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea la tabla html_templates para almacenar plantillas HTML.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE html_templates (
    id INT AUTO_INCREMENT NOT NULL,
    uuid VARCHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    html_content LONGTEXT NOT NULL,
    json_content JSON NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uq_html_templates_uuid (uuid),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE html_templates');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea la tabla para registrar el texto extraido de PDFs en chat tool.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_tool_pdf_logers (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_tool_pdf_logers');
    }
}

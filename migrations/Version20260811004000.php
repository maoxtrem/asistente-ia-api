<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811004000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guarda la respuesta JSON de Docling para cada imagen del documento.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images ADD docling_json JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images DROP docling_json');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guarda el resultado de la revisión preliminar de las imágenes del ChatTool PDF.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images ADD approved TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images DROP approved');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guarda la confianza y el razonamiento de la revisión preliminar de imágenes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images ADD confidence_score INT DEFAULT NULL, ADD reasoning LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images DROP confidence_score, DROP reasoning');
    }
}

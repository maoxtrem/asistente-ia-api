<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guarda el resultado de la extracción de contexto general de las imágenes aprobadas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images ADD context_general_analyzed TINYINT(1) DEFAULT NULL, ADD context_genera_json JSON DEFAULT NULL, ADD description_context_general LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images DROP context_general_analyzed, DROP context_genera_json, DROP description_context_general');
    }
}

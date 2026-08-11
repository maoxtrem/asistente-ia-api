<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega la fase 2 de materiales y sistemas constructivos a las imágenes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images DROP description_context_general, ADD materials_systems_analyzed TINYINT(1) DEFAULT NULL, ADD materials_systems_json JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images DROP materials_systems_analyzed, DROP materials_systems_json, ADD description_context_general LONGTEXT DEFAULT NULL');
    }
}

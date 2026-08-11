<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega la fase 3 geométrica, de metrados y síntesis final a las imágenes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images ADD geometry_quantities_analyzed TINYINT(1) DEFAULT NULL, ADD geometry_quantities_json JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf_images DROP geometry_quantities_analyzed, DROP geometry_quantities_json');
    }
}

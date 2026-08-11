<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guarda la clave del ZIP de imágenes generado desde el PDF adjunto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf ADD attachment_zip_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_history_pdf DROP attachment_zip_key');
    }
}

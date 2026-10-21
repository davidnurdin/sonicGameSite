<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021150458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corbeille : date de mise à la corbeille des créations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE level ADD trashed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE sound ADD trashed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE sprite_set ADD trashed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE tileset ADD trashed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE level DROP trashed_at');
        $this->addSql('ALTER TABLE sound DROP trashed_at');
        $this->addSql('ALTER TABLE sprite_set DROP trashed_at');
        $this->addSql('ALTER TABLE tileset DROP trashed_at');
    }
}

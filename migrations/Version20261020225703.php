<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020225703 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Éditeurs : comportement des blocs, style des tiles, musique des niveaux';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE level ADD music SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE tileset ADD behaviour JSON DEFAULT \'[]\' NOT NULL, ADD style SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE level DROP music');
        $this->addSql('ALTER TABLE tileset DROP behaviour, DROP style');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026075956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Noms de joueur : 15 caractères au plus (8 avant)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` CHANGE username username VARCHAR(15) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // only while no name is longer than 8
        $this->addSql('ALTER TABLE `user` CHANGE username username VARCHAR(8) NOT NULL');
    }
}

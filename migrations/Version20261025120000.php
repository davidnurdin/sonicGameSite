<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compteur de visiteurs : retire les visites comptées sous l\'adresse du proxy (avant le protocole PROXY)';
    }

    public function up(Schema $schema): void
    {
        // every visit so far was seen from Traefik's address: one wrong row, the browsers count again (visit.js)
        $this->addSql('DELETE FROM visitor');
    }

    public function down(Schema $schema): void
    {
    }
}

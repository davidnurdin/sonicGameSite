<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020195556 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Statistiques des joueurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE player_stats (totals JSON NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE player_stats ADD CONSTRAINT FK_E8351CECA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_stats DROP FOREIGN KEY FK_E8351CECA76ED395');
        $this->addSql('DROP TABLE player_stats');
    }
}

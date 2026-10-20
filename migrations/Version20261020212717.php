<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020212717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replays des parties (historique, Revoir)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE replay (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(8) NOT NULL, mode VARCHAR(12) NOT NULL, level INT NOT NULL, match_id VARCHAR(16) DEFAULT NULL, players JSON NOT NULL, result JSON NOT NULL, duration DOUBLE PRECISION NOT NULL, data LONGBLOB NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX replay_created (created_at), UNIQUE INDEX replay_match_user (match_id, user_id), INDEX IDX_D937F4F2A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE replay ADD CONSTRAINT FK_D937F4F2A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE replay DROP FOREIGN KEY FK_D937F4F2A76ED395');
        $this->addSql('DROP TABLE replay');
    }
}

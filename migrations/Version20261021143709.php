<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021143709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historique des créations, joueurs qui les modifient avec leur créateur, forks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE creation_member (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(8) NOT NULL, creation_id INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX member_creation_user (kind, creation_id, user_id), INDEX IDX_1EB3220DA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE revision (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(8) NOT NULL, creation_id INT NOT NULL, action VARCHAR(12) NOT NULL, name VARCHAR(40) NOT NULL, changes JSON NOT NULL, other_id INT DEFAULT NULL, data LONGBLOB DEFAULT NULL, hash VARCHAR(40) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX revision_creation (kind, creation_id), INDEX revision_created (created_at), INDEX IDX_6D6315CCA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE creation_member ADD CONSTRAINT FK_1EB3220DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE revision ADD CONSTRAINT FK_6D6315CCA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE level ADD forked_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE level ADD CONSTRAINT FK_9AEACC13684644A7 FOREIGN KEY (forked_from_id) REFERENCES level (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9AEACC13684644A7 ON level (forked_from_id)');
        $this->addSql('ALTER TABLE sound ADD forked_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sound ADD CONSTRAINT FK_F88EC384684644A7 FOREIGN KEY (forked_from_id) REFERENCES sound (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F88EC384684644A7 ON sound (forked_from_id)');
        $this->addSql('ALTER TABLE sprite_set ADD forked_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sprite_set ADD CONSTRAINT FK_B5565E33684644A7 FOREIGN KEY (forked_from_id) REFERENCES sprite_set (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B5565E33684644A7 ON sprite_set (forked_from_id)');
        $this->addSql('ALTER TABLE tileset ADD forked_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tileset ADD CONSTRAINT FK_E3DF1467684644A7 FOREIGN KEY (forked_from_id) REFERENCES tileset (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E3DF1467684644A7 ON tileset (forked_from_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creation_member DROP FOREIGN KEY FK_1EB3220DA76ED395');
        $this->addSql('ALTER TABLE revision DROP FOREIGN KEY FK_6D6315CCA76ED395');
        $this->addSql('DROP TABLE creation_member');
        $this->addSql('DROP TABLE revision');
        $this->addSql('ALTER TABLE level DROP FOREIGN KEY FK_9AEACC13684644A7');
        $this->addSql('DROP INDEX IDX_9AEACC13684644A7 ON level');
        $this->addSql('ALTER TABLE level DROP forked_from_id');
        $this->addSql('ALTER TABLE sound DROP FOREIGN KEY FK_F88EC384684644A7');
        $this->addSql('DROP INDEX IDX_F88EC384684644A7 ON sound');
        $this->addSql('ALTER TABLE sound DROP forked_from_id');
        $this->addSql('ALTER TABLE sprite_set DROP FOREIGN KEY FK_B5565E33684644A7');
        $this->addSql('DROP INDEX IDX_B5565E33684644A7 ON sprite_set');
        $this->addSql('ALTER TABLE sprite_set DROP forked_from_id');
        $this->addSql('ALTER TABLE tileset DROP FOREIGN KEY FK_E3DF1467684644A7');
        $this->addSql('DROP INDEX IDX_E3DF1467684644A7 ON tileset');
        $this->addSql('ALTER TABLE tileset DROP forked_from_id');
    }
}

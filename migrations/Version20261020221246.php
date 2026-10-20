<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020221246 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Éditeurs : niveaux et jeux de tiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE level (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(40) NOT NULL, public TINYINT NOT NULL, width SMALLINT NOT NULL, height SMALLINT NOT NULL, blocks LONGBLOB NOT NULL, start JSON NOT NULL, objects JSON NOT NULL, thumb LONGBLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, tileset_id INT NOT NULL, INDEX level_public (public, updated_at), INDEX IDX_9AEACC13A76ED395 (user_id), INDEX IDX_9AEACC13131B0066 (tileset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tileset (id INT AUTO_INCREMENT NOT NULL, zone VARCHAR(20) DEFAULT NULL, name VARCHAR(40) NOT NULL, public TINYINT NOT NULL, solidity JSON NOT NULL, image LONGBLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_E3DF1467A0EBC007 (zone), INDEX IDX_E3DF1467A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE level ADD CONSTRAINT FK_9AEACC13A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE level ADD CONSTRAINT FK_9AEACC13131B0066 FOREIGN KEY (tileset_id) REFERENCES tileset (id)');
        $this->addSql('ALTER TABLE tileset ADD CONSTRAINT FK_E3DF1467A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE level DROP FOREIGN KEY FK_9AEACC13A76ED395');
        $this->addSql('ALTER TABLE level DROP FOREIGN KEY FK_9AEACC13131B0066');
        $this->addSql('ALTER TABLE tileset DROP FOREIGN KEY FK_E3DF1467A76ED395');
        $this->addSql('DROP TABLE level');
        $this->addSql('DROP TABLE tileset');
    }
}

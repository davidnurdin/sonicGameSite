<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021112938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Éditeur de sons : musiques et bruitages (sound)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sound (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(40) NOT NULL, kind VARCHAR(5) NOT NULL, public TINYINT NOT NULL, data JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_F88EC384A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sound ADD CONSTRAINT FK_F88EC384A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sound DROP FOREIGN KEY FK_F88EC384A76ED395');
        $this->addSql('DROP TABLE sound');
    }
}

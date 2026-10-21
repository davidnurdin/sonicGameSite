<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021110723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Éditeur de sprites (sprite_set) et les sprites d\'un niveau (level.sprite_set_id)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sprite_set (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(40) NOT NULL, public TINYINT NOT NULL, sheets JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_B5565E33A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sprite_set ADD CONSTRAINT FK_B5565E33A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE level ADD sprite_set_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE level ADD CONSTRAINT FK_9AEACC13E65C1A57 FOREIGN KEY (sprite_set_id) REFERENCES sprite_set (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9AEACC13E65C1A57 ON level (sprite_set_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE level DROP FOREIGN KEY FK_9AEACC13E65C1A57');
        $this->addSql('DROP INDEX IDX_9AEACC13E65C1A57 ON level');
        $this->addSql('ALTER TABLE level DROP sprite_set_id');
        $this->addSql('ALTER TABLE sprite_set DROP FOREIGN KEY FK_B5565E33A76ED395');
        $this->addSql('DROP TABLE sprite_set');
    }
}

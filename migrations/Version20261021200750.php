<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021200750 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meilleurs temps des niveaux du site';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE level_record (id INT AUTO_INCREMENT NOT NULL, version VARCHAR(16) NOT NULL, time DOUBLE PRECISION NOT NULL, created_at DATETIME NOT NULL, level_id INT NOT NULL, user_id INT NOT NULL, replay_id INT DEFAULT NULL, INDEX level_record_best (level_id, version, time), UNIQUE INDEX level_record_once (level_id, user_id, version), INDEX IDX_E282FE305FB14BA7 (level_id), INDEX IDX_E282FE30A76ED395 (user_id), INDEX IDX_E282FE30186CE3E1 (replay_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE level_record ADD CONSTRAINT FK_E282FE305FB14BA7 FOREIGN KEY (level_id) REFERENCES level (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE level_record ADD CONSTRAINT FK_E282FE30A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE level_record ADD CONSTRAINT FK_E282FE30186CE3E1 FOREIGN KEY (replay_id) REFERENCES replay (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE level_record DROP FOREIGN KEY FK_E282FE305FB14BA7');
        $this->addSql('ALTER TABLE level_record DROP FOREIGN KEY FK_E282FE30A76ED395');
        $this->addSql('ALTER TABLE level_record DROP FOREIGN KEY FK_E282FE30186CE3E1');
        $this->addSql('DROP TABLE level_record');
    }
}

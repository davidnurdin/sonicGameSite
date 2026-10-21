<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021115435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sons dans le jeu : leur rendu WAV (sound_audio), la musique et les bruitages d\'un niveau (level.music_sound_id, level.sfx)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sound_audio (wav LONGBLOB NOT NULL, sound_id INT NOT NULL, PRIMARY KEY (sound_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sound_audio ADD CONSTRAINT FK_F80FDAC6AAA5C3E FOREIGN KEY (sound_id) REFERENCES sound (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE level ADD sfx JSON DEFAULT \'{}\' NOT NULL, ADD music_sound_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE level ADD CONSTRAINT FK_9AEACC13ADB90018 FOREIGN KEY (music_sound_id) REFERENCES sound (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9AEACC13ADB90018 ON level (music_sound_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sound_audio DROP FOREIGN KEY FK_F80FDAC6AAA5C3E');
        $this->addSql('DROP TABLE sound_audio');
        $this->addSql('ALTER TABLE level DROP FOREIGN KEY FK_9AEACC13ADB90018');
        $this->addSql('DROP INDEX IDX_9AEACC13ADB90018 ON level');
        $this->addSql('ALTER TABLE level DROP sfx, DROP music_sound_id');
    }
}

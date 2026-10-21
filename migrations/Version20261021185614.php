<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021185614 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes, favoris et parties des niveaux';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE level_favourite (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, level_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX level_favourite_once (level_id, user_id), INDEX IDX_E4FCB1685FB14BA7 (level_id), INDEX IDX_E4FCB168A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE level_vote (id INT AUTO_INCREMENT NOT NULL, stars SMALLINT NOT NULL, updated_at DATETIME NOT NULL, level_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX level_vote_once (level_id, user_id), INDEX IDX_33357E755FB14BA7 (level_id), INDEX IDX_33357E75A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE level_favourite ADD CONSTRAINT FK_E4FCB1685FB14BA7 FOREIGN KEY (level_id) REFERENCES level (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE level_favourite ADD CONSTRAINT FK_E4FCB168A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE level_vote ADD CONSTRAINT FK_33357E755FB14BA7 FOREIGN KEY (level_id) REFERENCES level (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE level_vote ADD CONSTRAINT FK_33357E75A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE level ADD plays INT DEFAULT 0 NOT NULL, ADD vote_count INT DEFAULT 0 NOT NULL, ADD vote_sum INT DEFAULT 0 NOT NULL, ADD score DOUBLE PRECISION DEFAULT 3 NOT NULL, ADD favourites INT DEFAULT 0 NOT NULL');
        // no vote yet: the neutral score (LevelRatings::score)
        $this->addSql('UPDATE level SET score = 3');
        $this->addSql('CREATE INDEX level_score ON level (public, score)');
        $this->addSql('CREATE INDEX level_plays ON level (public, plays)');
        $this->addSql('CREATE INDEX level_favourites ON level (public, favourites)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE level_favourite DROP FOREIGN KEY FK_E4FCB1685FB14BA7');
        $this->addSql('ALTER TABLE level_favourite DROP FOREIGN KEY FK_E4FCB168A76ED395');
        $this->addSql('ALTER TABLE level_vote DROP FOREIGN KEY FK_33357E755FB14BA7');
        $this->addSql('ALTER TABLE level_vote DROP FOREIGN KEY FK_33357E75A76ED395');
        $this->addSql('DROP TABLE level_favourite');
        $this->addSql('DROP TABLE level_vote');
        $this->addSql('DROP INDEX level_score ON level');
        $this->addSql('DROP INDEX level_plays ON level');
        $this->addSql('DROP INDEX level_favourites ON level');
        $this->addSql('ALTER TABLE level DROP plays, DROP vote_count, DROP vote_sum, DROP score, DROP favourites');
    }
}

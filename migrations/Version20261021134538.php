<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021134538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE replay ADD site_level_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE replay ADD CONSTRAINT FK_D937F4F253FCF47D FOREIGN KEY (site_level_id) REFERENCES level (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D937F4F253FCF47D ON replay (site_level_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE replay DROP FOREIGN KEY FK_D937F4F253FCF47D');
        $this->addSql('DROP INDEX IDX_D937F4F253FCF47D ON replay');
        $this->addSql('ALTER TABLE replay DROP site_level_id');
    }
}

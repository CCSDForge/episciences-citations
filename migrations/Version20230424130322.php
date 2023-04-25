<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20230424130322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_informations (id INT NOT NULL, name VARCHAR(255) DEFAULT NULL, surname VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE paper_references ADD CONSTRAINT FK_6E08CF2C534B549B FOREIGN KEY (uid) REFERENCES user_informations (id)');
        $this->addSql('CREATE INDEX IDX_6E08CF2C534B549B ON paper_references (uid)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE paper_references DROP FOREIGN KEY FK_6E08CF2C534B549B');
        $this->addSql('DROP TABLE user_informations');
        $this->addSql('DROP INDEX IDX_6E08CF2C534B549B ON paper_references');
    }
}

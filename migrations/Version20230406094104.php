<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20230406094104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paper_references ADD source VARCHAR(255) NOT NULL, DROP source_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paper_references ADD source_id INT NOT NULL, DROP source');
    }
}

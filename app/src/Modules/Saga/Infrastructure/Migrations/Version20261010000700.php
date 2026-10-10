<?php

declare(strict_types=1);

namespace App\Modules\Saga\Infrastructure\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the saga inbox.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on PostgreSQL.');

        $this->addSql('CREATE SCHEMA IF NOT EXISTS saga');
        $this->addSql('CREATE TABLE saga.inbox (consumer VARCHAR(255) NOT NULL, event_id VARCHAR(32) NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (consumer, event_id))');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on PostgreSQL.');

        $this->addSql('DROP TABLE saga.inbox');
        $this->addSql('DROP SCHEMA saga');
    }
}

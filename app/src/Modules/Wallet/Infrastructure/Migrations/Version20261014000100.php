<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the wallet schema and wallets table.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on PostgreSQL.');

        $this->addSql('CREATE SCHEMA IF NOT EXISTS wallet');
        $this->addSql('CREATE TABLE wallet.wallets (id VARCHAR(255) NOT NULL, user_id BIGINT NOT NULL, balance BIGINT NOT NULL, PRIMARY KEY (id), CONSTRAINT wallets_user_id_check CHECK (user_id > 0), CONSTRAINT wallets_balance_check CHECK (balance >= 0))');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on PostgreSQL.');

        $this->addSql('DROP TABLE wallet.wallets');
        $this->addSql('DROP SCHEMA wallet');
    }
}

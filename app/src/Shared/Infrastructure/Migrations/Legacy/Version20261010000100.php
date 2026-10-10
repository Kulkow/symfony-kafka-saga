<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Preserve the identity of the previously executed monolithic migration. */
final class Version20261010000100 extends AbstractMigration
{
    public function getDescription(): string { return 'Legacy version marker; tables are now owned by module migrations'; }
    public function up(Schema $schema): void {}
    public function down(Schema $schema): void {}
}

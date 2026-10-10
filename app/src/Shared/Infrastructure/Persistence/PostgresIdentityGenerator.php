<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Persistence;
use App\Shared\Application\IdentityGenerator;
use Doctrine\DBAL\Connection;
final readonly class PostgresIdentityGenerator implements IdentityGenerator
{
    public function __construct(private Connection $connection) {}
    public function next(string $table): int
    {
        // Table names come from repository constants, never from request input.
        return (int) $this->connection->fetchOne('SELECT nextval(pg_get_serial_sequence(?, ?))', [$table, 'id']);
    }
}

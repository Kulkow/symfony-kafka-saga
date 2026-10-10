<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Messaging;
use App\Shared\Application\EventSender;
use App\Shared\Domain\DomainEvent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;

final readonly class OutboxRelay
{
    public function __construct(
        private Connection $connection,
        private EventSender $sender,
    ) {}

    /** Publish one committed event. Failure keeps the outbox row pending. */
    public function publishNext(): bool
    {
        return $this->connection->transactional(function (): bool {
            $lock = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM shared.outbox WHERE published_at IS NULL ORDER BY id LIMIT 1'.$lock);
            if ($row === false) { return false; }
            $event = DomainEvent::fromArray(json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR));
            $this->sender->send($event);
            $this->connection->update('shared.outbox', ['published_at' => new \DateTimeImmutable()],
                ['id' => $row['id']], ['published_at' => Types::DATETIME_IMMUTABLE]);
            return true;
        });
    }
}

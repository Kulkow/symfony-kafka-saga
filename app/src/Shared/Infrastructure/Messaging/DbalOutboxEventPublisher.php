<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Messaging;
use App\Shared\Application\EventPublisher;
use App\Shared\Domain\DomainEvent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class DbalOutboxEventPublisher implements EventPublisher
{
    public function __construct(private Connection $connection) {}
    public function publish(DomainEvent $event): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Outbox events must be recorded in the business transaction.');
        }
        $this->connection->insert('shared.outbox', [
            'event_id' => $event->id, 'partition_key' => $event->key,
            'payload' => $event->toArray(), 'created_at' => $event->occurredAt,
        ], ['payload' => Types::JSON, 'created_at' => Types::DATETIME_IMMUTABLE]);
    }
}

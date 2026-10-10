<?php
declare(strict_types=1);
namespace App\Modules\Saga\Infrastructure\Messaging;
use App\Modules\Saga\Application\ProductionSaga;
use App\Shared\Domain\DomainEvent;
use App\Shared\Application\TransactionManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class ProductionSagaProcessor
{
    public function __construct(private Connection $connection, private ProductionSaga $saga, private TransactionManager $transactions) {}

    /** False means this event was already processed by this consumer group. */
    public function process(DomainEvent $event, string $consumer): bool
    {
        return $this->transactions->run(function () use ($event, $consumer): bool {
            $inserted = $this->connection->executeStatement(
                'INSERT INTO saga.inbox (consumer, event_id, processed_at) VALUES (?, ?, ?)'
                .' ON CONFLICT (consumer, event_id) DO NOTHING',
                [$consumer, $event->id, new \DateTimeImmutable()], [Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE]);
            if ($inserted === 0) { return false; }
            $this->saga->handle($event);
            return true;
        });
    }
}

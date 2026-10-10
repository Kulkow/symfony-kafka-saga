<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Kafka;
use App\Shared\Application\EventSender;
use App\Shared\Domain\DomainEvent;

final readonly class KafkaEventSender implements EventSender
{
    public function __construct(private KafkaClientFactory $kafka, private string $topic) {}

    public function send(DomainEvent $event): void
    {
        $delivered = false;
        $error = null;
        $producer = $this->kafka->createProducer(
            static function ($client, $message) use (&$delivered, &$error): void {
                $delivered = true;
                $error = $message->err;
            });
        $producer->newTopic($this->topic)->produce(RD_KAFKA_PARTITION_UA, 0,
            json_encode($event->toArray(), JSON_THROW_ON_ERROR), $event->key);
        $result = $producer->flush(10000);
        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR || !$delivered || $error !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new \RuntimeException('Kafka delivery was not acknowledged; outbox event remains pending.');
        }
    }
}

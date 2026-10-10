<?php
declare(strict_types=1);
namespace App\Modules\Saga\Infrastructure\Console;
use App\Modules\Saga\Infrastructure\Messaging\ProductionSagaProcessor;
use App\Shared\Domain\DomainEvent;
use App\Shared\Infrastructure\Kafka\KafkaClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:saga:consume', description: 'Run the production saga Kafka consumer')]
final class ConsumeSagaCommand extends Command
{
    public function __construct(
        private readonly KafkaClientFactory $kafka,
        private readonly ProductionSagaProcessor $processor,
        private readonly string $topic,
        private readonly string $consumerGroup,
    ) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
            pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
        }
        $consumer = $this->kafka->createConsumer($this->consumerGroup);
        $consumer->subscribe([$this->topic]);
        try {
            while ($running) {
                $message = $consumer->consume(1000);
                if (in_array($message->err, [RD_KAFKA_RESP_ERR__TIMED_OUT, RD_KAFKA_RESP_ERR__PARTITION_EOF], true)) {
                    continue;
                }
                if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    throw new \RuntimeException('Kafka consume failed: '.$message->errstr());
                }
                $event = DomainEvent::fromArray(json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR));
                if ($event->key !== $message->key) {
                    throw new \RuntimeException('Event key does not match the Kafka partition key.');
                }
                $processed = $this->processor->process($event, $this->consumerGroup);
                // A crash between the DB commit and this commit is safe: inbox deduplicates replay.
                $consumer->commit($message);
                $output->writeln(($processed ? 'Processed ' : 'Duplicate ').$event->name.' '.$event->id);
            }
        } finally {
            $consumer->close();
        }
        return Command::SUCCESS;
    }
}

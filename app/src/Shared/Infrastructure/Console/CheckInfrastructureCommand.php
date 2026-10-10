<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Infrastructure\Kafka\KafkaClientFactory;
use Doctrine\DBAL\Connection;
use Redis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:infrastructure:check', description: 'Check PostgreSQL, Redis and Kafka connectivity')]
final class CheckInfrastructureCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Redis $redis,
        private readonly KafkaClientFactory $kafka,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $checks = [
            'PostgreSQL' => fn () => $this->connection->fetchOne('SELECT 1'),
            'Redis' => fn () => $this->redis->ping(),
            'Kafka' => fn () => $this->kafka->createProducer()->getMetadata(true, null, 5000),
        ];
        $failed = false;

        foreach ($checks as $service => $check) {
            try {
                if (false === $check()) {
                    throw new \RuntimeException('Connection check returned false.');
                }
                $io->writeln($service.': OK');
            } catch (\Throwable $exception) {
                $failed = true;
                // Connection errors can contain credentials; keep them out of console output.
                $io->writeln($service.': FAILED ('.$exception::class.')');
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}

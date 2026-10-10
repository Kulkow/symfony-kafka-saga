<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Console;
use App\Shared\Infrastructure\Messaging\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:publish', description: 'Publish committed outbox events to Kafka')]
final class PublishOutboxCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('watch', null, InputOption::VALUE_NONE, 'Continue polling for new events');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
            pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
        }
        do {
            if ($this->relay->publishNext()) {
                $output->writeln('Published an outbox event.');
                continue;
            }
            if (!$input->getOption('watch')) { break; }
            usleep(250000);
        } while ($running);
        return Command::SUCCESS;
    }
}

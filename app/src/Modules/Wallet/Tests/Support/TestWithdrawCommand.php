<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Support;

use App\Modules\Wallet\Application\Command\WithdrawMoneyCommand;
use App\Modules\Wallet\Application\UseCase\WithdrawMoneyUseCase;
use App\Modules\Wallet\Domain\{WalletException, WalletId, WalletRepository};
use App\Modules\Wallet\Infrastructure\Persistence\Doctrine\WalletRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'test:wallet:withdraw')]
final class TestWithdrawCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WalletRepository $wallets,
        private readonly WithdrawMoneyUseCase $withdrawMoney,
        private readonly string $redisUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('schema', InputArgument::REQUIRED)
            ->addArgument('wallet-id', InputArgument::REQUIRED)
            ->addArgument('worker', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schema = $input->getArgument('schema');
        if (!preg_match('/^wallet_test_[a-f0-9]{16}$/D', $schema)) {
            throw new \InvalidArgumentException('Invalid test schema.');
        }
        $connection = $this->entityManager->getConnection();
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*_test$/D', $connection->getDatabase())) {
            throw new \RuntimeException('Wallet database tests require a database with a name ending in _test.');
        }
        $connection->executeStatement("SET statement_timeout = '10s'");
        $this->entityManager->getClassMetadata(WalletRecord::class)
            ->setPrimaryTable(['name' => 'wallets', 'schema' => $schema]);
        $redis = null;

        try {
            $id = WalletId::fromString($input->getArgument('wallet-id'));
            if ($this->wallets->findById($id)?->balance() !== 100) {
                throw new \RuntimeException('Expected initial balance 100.');
            }
            $redis = RedisAdapter::createConnection($this->redisUrl, ['timeout' => 2, 'read_timeout' => 6]);
            $redis->multi()->rPush($schema.':ready', $input->getArgument('worker'))
                ->expire($schema.':ready', 30)->exec();
            $permit = $redis->blPop([$schema.':release'], 5);
            if (!$permit || $permit[1] !== 'go') {
                throw new \RuntimeException('Redis synchronization barrier timed out.');
            }

            try {
                $this->withdrawMoney->execute(new WithdrawMoneyCommand($id, 80));
                $output->writeln('SUCCESS');
            } catch (WalletException $error) {
                if ($error->getMessage() !== 'Insufficient funds.') {
                    throw $error;
                }
                $output->writeln('INSUFFICIENT_FUNDS');
            }

            return Command::SUCCESS;
        } finally {
            $redis?->close();
            $connection->close();
        }
    }
}

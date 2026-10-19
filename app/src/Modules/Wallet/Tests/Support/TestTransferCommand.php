<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Support;

use App\Modules\Wallet\Application\Command\TransferMoneyCommand;
use App\Modules\Wallet\Application\UseCase\TransferMoneyUseCase;
use App\Modules\Wallet\Domain\{WalletException, WalletId, WalletRepository};
use App\Modules\Wallet\Infrastructure\Persistence\Doctrine\WalletRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'test:wallet:transfer')]
final class TestTransferCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WalletRepository $wallets,
        private readonly TransferMoneyUseCase $transferMoney,
        private readonly string $redisUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('schema', InputArgument::REQUIRED)
            ->addArgument('from-wallet-id', InputArgument::REQUIRED)
            ->addArgument('to-wallet-id', InputArgument::REQUIRED)
            ->addArgument('amount', InputArgument::REQUIRED)
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
        $connection->executeQuery("SELECT set_config('application_name', ?, false)", [
            $schema.':'.$input->getArgument('worker'),
        ])->free();
        $this->entityManager->getClassMetadata(WalletRecord::class)
            ->setPrimaryTable(['name' => 'wallets', 'schema' => $schema]);
        $redis = null;

        try {
            $from = WalletId::fromString($input->getArgument('from-wallet-id'));
            $to = WalletId::fromString($input->getArgument('to-wallet-id'));
            // Preload both records before the barrier to exercise refresh after locking.
            foreach ([$from, $to] as $id) {
                if ($this->wallets->findById($id) === null) {
                    throw new \RuntimeException('Transfer wallet is missing.');
                }
            }
            $redis = RedisAdapter::createConnection($this->redisUrl, ['timeout' => 2, 'read_timeout' => 6]);
            $redis->multi()->rPush($schema.':ready', $input->getArgument('worker'))
                ->expire($schema.':ready', 30)->exec();
            $permit = $redis->blPop([$schema.':release'], 5);
            if (!$permit || $permit[1] !== 'go') {
                throw new \RuntimeException('Redis synchronization barrier timed out.');
            }

            try {
                $this->transferMoney->execute(new TransferMoneyCommand($from, $to, (int) $input->getArgument('amount')));
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

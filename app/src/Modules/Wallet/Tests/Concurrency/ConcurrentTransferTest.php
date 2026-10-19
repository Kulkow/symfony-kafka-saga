<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Concurrency;

use App\Modules\Wallet\Domain\{Wallet, WalletId};
use App\Modules\Wallet\Tests\Support\DatabaseTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Process\Process;

final class ConcurrentTransferTest extends DatabaseTestCase
{
    public function testConcurrentTransfers(): void
    {
        $this->runConcurrentTransfers(
            ['a' => 100, 'b' => 100, 'c' => 0],
            [['a', 'c', 80], ['b', 'c', 80]],
            ['SUCCESS', 'SUCCESS'],
        );
    }

    public function testOppositeTransfersDoNotDeadlock(): void
    {
        $this->runConcurrentTransfers(
            ['a' => 100, 'b' => 100],
            [['a', 'b', 80], ['b', 'a', 80]],
            ['SUCCESS', 'SUCCESS'],
        );
    }

    public function testInsufficientFundsUnderConcurrency(): void
    {
        $this->runConcurrentTransfers(
            ['a' => 100, 'b' => 0, 'c' => 0],
            [['a', 'b', 80], ['a', 'c', 80]],
            ['INSUFFICIENT_FUNDS', 'SUCCESS'],
        );
    }

    private function runConcurrentTransfers(array $balances, array $transfers, array $expectedResults): void
    {
        $this->transactions->run(function () use ($balances): void {
            foreach ($balances as $id => $balance) {
                $this->wallets->add(Wallet::restore(WalletId::fromString($id), 1, $balance));
            }
        });
        $redis = RedisAdapter::createConnection($_ENV['REDIS_URL'], ['timeout' => 2, 'read_timeout' => 6]);
        $readyKey = $this->schema.':ready';
        $releaseKey = $this->schema.':release';
        $processes = [];

        try {
            foreach ($transfers as $worker => [$from, $to, $amount]) {
                $process = $this->createWorker($from, $to, $amount, $worker);
                $processes[] = $process;
                $process->start();
            }

            $readyWorkers = [];
            foreach ($processes as $process) {
                $ready = $redis->blPop([$readyKey], 5);
                self::assertNotEmpty($ready, 'Worker did not reach the Redis barrier. '.$process->getErrorOutput());
                $readyWorkers[] = $ready[1];
            }
            sort($readyWorkers);
            self::assertSame(['0', '1'], $readyWorkers);
            $redis->multi()->rPush($releaseKey, 'go', 'go')->expire($releaseKey, 30)->exec();

            $results = [];
            $expectedBalances = $balances;
            foreach ($processes as $worker => $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                self::assertSame('', $process->getErrorOutput());
                $result = trim($process->getOutput());
                $results[] = $result;
                if ($result === 'SUCCESS') {
                    [$from, $to, $amount] = $transfers[$worker];
                    $expectedBalances[$from] -= $amount;
                    $expectedBalances[$to] += $amount;
                }
            }
            sort($results);
            self::assertSame($expectedResults, $results);

            $actualBalances = [];
            foreach ($balances as $id => $balance) {
                $actualBalances[$id] = $this->wallets->findById(WalletId::fromString($id))->balance();
            }
            self::assertSame($expectedBalances, $actualBalances);
            self::assertSame(array_sum($balances), array_sum($actualBalances));
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            $redis->del($readyKey, $releaseKey);
            $redis->close();
        }
    }

    public function testTransferWaitsForLockedWallet(): void
    {
        $from = WalletId::fromString('a');
        $to = WalletId::fromString('b');
        $this->transactions->run(function () use ($from, $to): void {
            $this->wallets->add(Wallet::restore($from, 1, 100));
            $this->wallets->add(Wallet::open($to, 2));
        });
        $redis = RedisAdapter::createConnection($_ENV['REDIS_URL'], ['timeout' => 2, 'read_timeout' => 6]);
        $process = $this->createWorker('a', 'b', 80, 0);

        try {
            $this->connection->beginTransaction();
            $this->wallets->findByIdForUpdate($from);
            $blockerPid = (int) $this->connection->fetchOne('SELECT pg_backend_pid()');
            $process->start();
            $ready = $redis->blPop([$this->schema.':ready'], 5);
            self::assertNotEmpty($ready, $process->getErrorOutput());
            self::assertSame('0', $ready[1]);
            $redis->multi()->rPush($this->schema.':release', 'go')->expire($this->schema.':release', 30)->exec();

            $deadline = microtime(true) + 5;
            $waitingPid = false;
            do {
                $process->checkTimeout();
                $this->connection->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
                $waitingPid = $this->connection->fetchOne(
                    "SELECT pid FROM pg_stat_activity WHERE application_name = ?
                        AND wait_event_type = 'Lock' AND ? = ANY(pg_blocking_pids(pid))",
                    [$this->schema.':0', $blockerPid],
                );
                if ($waitingPid !== false || !$process->isRunning()) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);

            self::assertNotFalse($waitingPid, 'Transfer must wait for the parent transaction lock. '.$process->getErrorOutput());
            self::assertTrue($process->isRunning());
            self::assertSame('', $process->getOutput());
            self::assertSame(100, $this->wallets->findById($from)->balance());
            self::assertSame(0, $this->wallets->findById($to)->balance());

            $this->connection->commit();
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('', $process->getErrorOutput());
            self::assertSame('SUCCESS', trim($process->getOutput()));
            self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();
            self::assertSame(20, $this->wallets->findById($from)->balance());
            self::assertSame(80, $this->wallets->findById($to)->balance());
        } finally {
            $process->stop(0);
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $redis->del($this->schema.':ready', $this->schema.':release');
            $redis->close();
        }
    }

    private function createWorker(string $from, string $to, int $amount, int $worker): Process
    {
        return new Process(
            [PHP_BINARY, dirname(__DIR__, 5).'/bin/console', 'test:wallet:transfer',
                $this->schema, $from, $to, (string) $amount, (string) $worker,
                '--env=test', '--no-debug', '--no-interaction'],
            env: [
                'SHELL_VERBOSITY' => '0',
                'WALLET_TEST_DATABASE_URL' => $_ENV['WALLET_TEST_DATABASE_URL'],
                'REDIS_URL' => $_ENV['REDIS_URL'],
            ],
            timeout: 20,
        );
    }
}

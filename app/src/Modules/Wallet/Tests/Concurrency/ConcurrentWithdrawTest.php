<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Concurrency;

use App\Modules\Wallet\Domain\{Wallet, WalletId};
use App\Modules\Wallet\Tests\Support\DatabaseTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Process\Process;

final class ConcurrentWithdrawTest extends DatabaseTestCase
{
    public function testConcurrentWithdraw(): void
    {
        $id = WalletId::fromString('concurrent-wallet');
        $this->transactions->run(fn() => $this->wallets->add(Wallet::restore($id, 1, 100)));
        $redis = RedisAdapter::createConnection($_ENV['REDIS_URL'], ['timeout' => 2, 'read_timeout' => 6]);
        $readyKey = $this->schema . ':ready';
        $releaseKey = $this->schema . ':release';
        $processes = [];

        try {
            for ($worker = 0; $worker < 2; ++$worker) {
                $process = new Process(
                    [PHP_BINARY, dirname(__DIR__, 5).'/bin/console', 'test:wallet:withdraw', $this->schema, $id->value, (string) $worker, '--env=test', '--no-debug', '--no-interaction'],
                    env: [
                        // Keep parent console verbosity from enabling debug logs in workers.
                        'SHELL_VERBOSITY' => '0',
                        'WALLET_TEST_DATABASE_URL' => $_ENV['WALLET_TEST_DATABASE_URL'],
                        'REDIS_URL' => $_ENV['REDIS_URL'],
                    ],
                    timeout: 20,
                );
                $processes[] = $process;
                $process->start();
            }

            // Both workers must preload the balance before either may withdraw.
            $readyWorkers = [];
            foreach ($processes as $process) {
                $ready = $redis->blPop([$readyKey], 5);
                self::assertNotEmpty($ready, 'Worker did not reach the Redis barrier. ' . $process->getErrorOutput());
                $readyWorkers[] = $ready[1];
            }
            sort($readyWorkers);
            self::assertSame(['0', '1'], $readyWorkers);

            // Publish both permits atomically, releasing the two waiting workers.
            $redis->multi()->rPush($releaseKey, 'go', 'go')->expire($releaseKey, 30)->exec();

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                self::assertSame('', $process->getErrorOutput());
                $results[] = trim($process->getOutput());
            }
            sort($results);

            self::assertSame(['INSUFFICIENT_FUNDS', 'SUCCESS'], $results);
            self::assertSame(20, $this->wallets->findById($id)->balance());
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            $redis->del($readyKey, $releaseKey);
            $redis->close();
        }
    }
}

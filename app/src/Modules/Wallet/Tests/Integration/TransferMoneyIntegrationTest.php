<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Integration;

use App\Modules\Wallet\Application\Command\TransferMoneyCommand;
use App\Modules\Wallet\Application\UseCase\TransferMoneyUseCase;
use App\Modules\Wallet\Domain\{Wallet, WalletException, WalletId};
use App\Modules\Wallet\Tests\Support\DatabaseTestCase;
use App\Shared\Application\NotFound;
use PHPUnit\Framework\Attributes\DataProvider;

final class TransferMoneyIntegrationTest extends DatabaseTestCase
{
    #[DataProvider('successfulTransfers')]
    public function testTransferPersistsBothBalances(string $from, string $to, int $amount): void
    {
        $this->seedWallets(100, 20, $from, $to);
        $this->transfer($from, $to, $amount);

        $this->assertBalances(100 - $amount, 20 + $amount, $from, $to);
    }

    public static function successfulTransfers(): iterable
    {
        yield 'forward' => ['a', 'b', 80];
        yield 'full balance' => ['a', 'b', 100];
        yield 'reverse' => ['b', 'a', 80];
        yield 'numeric IDs' => ['2', '10', 80];
    }

    public function testSelfTransferIsRejected(): void
    {
        $this->seedWallets(100, 20);

        try {
            $this->transfer('a', 'a', 10);
            self::fail('Expected WalletException.');
        } catch (WalletException $error) {
            self::assertSame('Cannot transfer money to the same wallet.', $error->getMessage());
            $this->assertBalances(100, 20);
        }
    }

    #[DataProvider('rejectedTransfers')]
    public function testRejectedTransferPreservesBothBalances(int $balance, int $amount): void
    {
        $this->seedWallets(100, $balance);
        try {
            $this->transfer('a', 'b', $amount);
            self::fail('Expected WalletException.');
        } catch (WalletException) {
            $this->assertBalances(100, $balance);
        }
    }

    public static function rejectedTransfers(): iterable
    {
        yield 'insufficient funds' => [20, 101];
        yield 'zero' => [20, 0];
        yield 'negative' => [20, -1];
        yield 'recipient overflow' => [PHP_INT_MAX, 1];
    }

    #[DataProvider('missingWallets')]
    public function testMissingWalletPreservesExistingBalance(string $from, string $to): void
    {
        $this->seedWallets(100, 20);
        try {
            $this->transfer($from, $to, 10);
            self::fail('Expected NotFound.');
        } catch (NotFound) {
            $this->assertBalances(100, 20);
        }
    }

    public static function missingWallets(): iterable
    {
        yield 'source' => ['missing', 'b'];
        yield 'recipient' => ['a', 'missing'];
    }

    public function testTransferRollback(): void
    {
        $this->seedWallets(100, 20);
        $this->connection->executeStatement("CREATE FUNCTION {$this->schema}.reject_credit() RETURNS trigger
            LANGUAGE plpgsql AS \$\$ BEGIN
                IF (SELECT balance FROM {$this->schema}.wallets WHERE id = 'a') <> 20 THEN
                    RAISE EXCEPTION 'Expected source debit before recipient failure';
                END IF;
                RAISE EXCEPTION 'Test credit failure';
            END; \$\$");
        $this->connection->executeStatement("CREATE TRIGGER reject_credit BEFORE UPDATE ON {$this->schema}.wallets
            FOR EACH ROW WHEN (NEW.id = 'b') EXECUTE FUNCTION {$this->schema}.reject_credit()");

        try {
            $this->transfer('a', 'b', 80);
            self::fail('Expected recipient persistence failure.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('Test credit failure', $error->getMessage());
            $this->assertBalances(100, 20);
        }
    }

    private function seedWallets(int $fromBalance, int $toBalance, string $fromId = 'a', string $toId = 'b'): void
    {
        $this->transactions->run(function () use ($fromBalance, $toBalance, $fromId, $toId): void {
            $this->wallets->add(Wallet::restore(WalletId::fromString($fromId), 1, $fromBalance));
            $this->wallets->add(Wallet::restore(WalletId::fromString($toId), 2, $toBalance));
        });
    }

    private function transfer(string $from, string $to, int $amount): void
    {
        self::getContainer()->get(TransferMoneyUseCase::class)->execute(
            new TransferMoneyCommand(WalletId::fromString($from), WalletId::fromString($to), $amount),
        );
    }

    private function assertBalances(int $from, int $to, string $fromId = 'a', string $toId = 'b'): void
    {
        self::assertSame($from, $this->wallets->findById(WalletId::fromString($fromId))->balance());
        self::assertSame($to, $this->wallets->findById(WalletId::fromString($toId))->balance());
        self::assertFalse($this->connection->isTransactionActive());
    }
}

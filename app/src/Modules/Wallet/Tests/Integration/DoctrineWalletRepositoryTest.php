<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Integration;

use App\Modules\Wallet\Domain\{Wallet, WalletId};
use App\Modules\Wallet\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DoctrineWalletRepositoryTest extends DatabaseTestCase
{
    public function testPersistsWallet(): void
    {
        $wallet = Wallet::restore(WalletId::fromString('repository-wallet'), 7, PHP_INT_MAX);
        $this->transactions->run(fn () => $this->wallets->add($wallet));

        $loaded = $this->wallets->findById($wallet->id);

        self::assertNotNull($loaded);
        self::assertTrue($wallet->id->equals($loaded->id));
        self::assertSame(7, $loaded->userId);
        self::assertSame(PHP_INT_MAX, $loaded->balance());
    }

    public function testMissingWalletReturnsNull(): void
    {
        $id = WalletId::fromString('missing');

        self::assertNull($this->wallets->findById($id));
        self::assertNull($this->transactions->run(
            fn () => $this->wallets->findByIdForUpdate($id),
        ));
    }

    #[DataProvider('transactionalOperations')]
    public function testOperationRequiresTransaction(string $method): void
    {
        $wallet = Wallet::open(WalletId::fromString('repository-wallet'), 1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Wallet writes and locking reads require an application transaction.');

        $this->wallets->$method($method === 'findByIdForUpdate' ? $wallet->id : $wallet);
    }

    public static function transactionalOperations(): iterable
    {
        yield 'add' => ['add'];
        yield 'save' => ['save'];
        yield 'locking read' => ['findByIdForUpdate'];
    }

    public function testDuplicateWalletIsRejected(): void
    {
        $wallet = Wallet::open(WalletId::fromString('repository-wallet'), 1);
        $this->transactions->run(fn () => $this->wallets->add($wallet));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Wallet already exists: '.$wallet->id->value);

        $this->transactions->run(fn () => $this->wallets->add($wallet));
    }

    public function testLockingReadRefreshesManagedBalanceAndSavesWithdrawal(): void
    {
        $id = WalletId::fromString('repository-wallet');
        $this->transactions->run(fn () => $this->wallets->add(Wallet::restore($id, 1, 200)));
        $this->wallets->findById($id);

        $this->transactions->run(function () use ($id): void {
            $this->connection->executeStatement(
                'UPDATE '.$this->schema.'.wallets SET balance = 100 WHERE id = ?',
                [$id->value],
            );
            $wallet = $this->wallets->findByIdForUpdate($id);
            self::assertNotNull($wallet);
            self::assertSame(100, $wallet->balance());
            $wallet->withdraw(10);
            $this->wallets->save($wallet);
        });

        self::assertSame(90, $this->wallets->findById($id)->balance());
    }

    public function testFailedTransactionRollsBackBalance(): void
    {
        $id = WalletId::fromString('repository-wallet');
        $this->transactions->run(fn () => $this->wallets->add(Wallet::restore($id, 1, 100)));
        $failure = new \RuntimeException('Abort');

        try {
            $this->transactions->run(function () use ($id, $failure): void {
                $wallet = $this->wallets->findByIdForUpdate($id);
                $wallet->withdraw(20);
                $this->wallets->save($wallet);
                throw $failure;
            });
            self::fail('Expected the transaction to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }

        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame(100, $this->wallets->findById($id)->balance());
    }

    public function testSavingMissingWalletIsRejected(): void
    {
        $wallet = Wallet::open(WalletId::fromString('missing'), 1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Wallet does not exist: '.$wallet->id->value);

        $this->transactions->run(fn () => $this->wallets->save($wallet));
    }
}

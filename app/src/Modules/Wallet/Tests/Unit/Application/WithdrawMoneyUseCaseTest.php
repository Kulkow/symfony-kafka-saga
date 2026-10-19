<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Unit\Application;

use App\Modules\Wallet\Domain\{Wallet, WalletId, WalletRepository, WalletException};
use App\Modules\Wallet\Application\{TransactionManager, Command\WithdrawMoneyCommand, UseCase\WithdrawMoneyUseCase};
use App\Shared\Application\NotFound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WithdrawMoneyUseCaseTest extends TestCase
{
    public function testWithdrawLocksAndSavesWithinTransaction(): void
    {
        $id = WalletId::fromString('unit-wallet');
        $wallet = Wallet::restore($id, 1, 100);
        $repository = $this->createMock(WalletRepository::class);
        $transactions = $this->createMock(TransactionManager::class);
        $active = false;
        $transactions->expects(self::once())->method('run')->willReturnCallback(
            static function (callable $operation) use (&$active): mixed {
                $active = true;
                try { return $operation(); } finally { $active = false; }
            }
        );
        $repository->expects(self::never())->method('findById');
        $repository->expects(self::never())->method('add');
        $repository->expects(self::once())->method('findByIdForUpdate')->with($id)->willReturnCallback(
            static function () use (&$active, $wallet): Wallet {
                self::assertTrue($active);
                return $wallet;
            }
        );
        $repository->expects(self::once())->method('save')->with($wallet)->willReturnCallback(
            static function (Wallet $saved) use (&$active): void {
                self::assertTrue($active);
                self::assertSame(60, $saved->balance());
            }
        );

        (new WithdrawMoneyUseCase($repository, $transactions))->execute(new WithdrawMoneyCommand($id, 40));
        self::assertFalse($active);
        self::assertSame(60, $wallet->balance());
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidWithdrawalDoesNotSave(int $amount): void
    {
        $id = WalletId::fromString('unit-wallet');
        $wallet = Wallet::restore($id, 1, 60);
        $repository = $this->createMock(WalletRepository::class);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::once())->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $repository->expects(self::never())->method('findById');
        $repository->expects(self::never())->method('add');
        $repository->expects(self::once())->method('findByIdForUpdate')->with($id)->willReturn($wallet);
        $repository->expects(self::never())->method('save');

        try {
            (new WithdrawMoneyUseCase($repository, $transactions))->execute(new WithdrawMoneyCommand($id, $amount));
            self::fail('Expected WalletException.');
        } catch (WalletException) {
            self::assertSame(60, $wallet->balance());
        }
    }

    public static function invalidAmounts(): iterable
    {
        yield 'insufficient funds' => [61];
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testMissingWalletDoesNotSave(): void
    {
        $id = WalletId::fromString('missing');
        $repository = $this->createMock(WalletRepository::class);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::once())->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $repository->expects(self::never())->method('findById');
        $repository->expects(self::never())->method('add');
        $repository->expects(self::once())->method('findByIdForUpdate')->with($id)->willReturn(null);
        $repository->expects(self::never())->method('save');

        $this->expectException(NotFound::class);
        (new WithdrawMoneyUseCase($repository, $transactions))->execute(new WithdrawMoneyCommand($id, 1));
    }

    public function testPersistenceFailurePropagatesThroughTransaction(): void
    {
        $id = WalletId::fromString('unit-wallet');
        $wallet = Wallet::restore($id, 1, 100);
        $repository = $this->createMock(WalletRepository::class);
        $transactions = $this->createMock(TransactionManager::class);
        $failure = new \RuntimeException('Persistence failed.');
        $transactions->expects(self::once())->method('run')->willReturnCallback(
            static function (callable $operation) use ($failure): mixed {
                try { $operation(); } catch (\RuntimeException $error) {
                    self::assertSame($failure, $error);
                    throw $error;
                }
                self::fail('The transaction must receive the persistence failure.');
            }
        );
        $repository->expects(self::never())->method('findById');
        $repository->expects(self::never())->method('add');
        $repository->expects(self::once())->method('findByIdForUpdate')->with($id)->willReturn($wallet);
        $repository->expects(self::once())->method('save')->with($wallet)->willThrowException($failure);

        $this->expectExceptionObject($failure);
        (new WithdrawMoneyUseCase($repository, $transactions))->execute(new WithdrawMoneyCommand($id, 40));
    }
}

<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Unit\Domain;

use App\Modules\Wallet\Domain\{Wallet, WalletId, WalletException};
use App\Shared\Domain\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WalletTest extends TestCase
{
    public function testOpenCreatesAnEmptyWallet(): void
    {
        $id = WalletId::fromString('unit-wallet');
        $wallet = Wallet::open($id, 1);

        self::assertSame($id, $wallet->id);
        self::assertSame(1, $wallet->userId);
        self::assertSame(0, $wallet->balance());
    }

    public function testDepositAndWithdrawChangeBalance(): void
    {
        $wallet = Wallet::open(WalletId::fromString('unit-wallet'), 1);
        $wallet->deposit(100);
        $wallet->withdraw(40);
        self::assertSame(60, $wallet->balance());
        $wallet->withdraw(60);
        self::assertSame(0, $wallet->balance());
    }

    #[DataProvider('invalidOperations')]
    public function testRejectedOperationPreservesBalance(string $method, int $amount): void
    {
        $wallet = Wallet::restore(WalletId::fromString('unit-wallet'), 1, 60);
        try {
            $wallet->$method($amount);
            self::fail('Expected WalletException.');
        } catch (WalletException) {
            self::assertSame(60, $wallet->balance());
        }
    }

    public static function invalidOperations(): iterable
    {
        yield 'insufficient funds' => ['withdraw', 61];
        yield 'zero withdrawal' => ['withdraw', 0];
        yield 'negative withdrawal' => ['withdraw', -1];
        yield 'zero deposit' => ['deposit', 0];
        yield 'negative deposit' => ['deposit', -1];
        yield 'overflow' => ['deposit', PHP_INT_MAX];
    }

    public function testRestorePreservesBalance(): void
    {
        $wallet = Wallet::restore(WalletId::fromString('unit-wallet'), 1, PHP_INT_MAX);
        self::assertSame(PHP_INT_MAX, $wallet->balance());
    }

    #[DataProvider('invalidUserIds')]
    public function testOpenRejectsInvalidUserId(int $userId): void
    {
        $this->expectException(DomainViolation::class);
        Wallet::open(WalletId::fromString('unit-wallet'), $userId);
    }

    public static function invalidUserIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testRestoreRejectsNegativeBalance(): void
    {
        $this->expectException(DomainViolation::class);
        Wallet::restore(WalletId::fromString('unit-wallet'), 1, -1);
    }
}

<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Integration;

use App\Modules\Wallet\Application\Command\WithdrawMoneyCommand;
use App\Modules\Wallet\Application\UseCase\WithdrawMoneyUseCase;
use App\Modules\Wallet\Domain\{Wallet, WalletException, WalletId};
use App\Modules\Wallet\Tests\Support\DatabaseTestCase;
use App\Shared\Application\NotFound;
use PHPUnit\Framework\Attributes\DataProvider;

final class WithdrawMoneyIntegrationTest extends DatabaseTestCase
{
    private WalletId $id;
    private WithdrawMoneyUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = WalletId::fromString('withdraw-wallet');
        $this->transactions->run(fn () => $this->wallets->add(Wallet::restore($this->id, 1, 100)));
        $this->useCase = self::getContainer()->get(WithdrawMoneyUseCase::class);
    }

    #[DataProvider('validAmounts')]
    public function testWithdrawalPersistsBalance(int $amount, int $balance): void
    {
        $this->useCase->execute(new WithdrawMoneyCommand($this->id, $amount));

        self::assertSame($balance, $this->wallets->findById($this->id)->balance());
        self::assertFalse($this->connection->isTransactionActive());
    }

    public static function validAmounts(): iterable
    {
        yield 'partial withdrawal' => [40, 60];
        yield 'full withdrawal' => [100, 0];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectedWithdrawalPreservesBalance(int $amount): void
    {
        try {
            $this->useCase->execute(new WithdrawMoneyCommand($this->id, $amount));
            self::fail('Expected WalletException.');
        } catch (WalletException) {
            self::assertSame(100, $this->wallets->findById($this->id)->balance());
            self::assertFalse($this->connection->isTransactionActive());
        }
    }

    public static function invalidAmounts(): iterable
    {
        yield 'insufficient funds' => [101];
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testMissingWalletIsRejected(): void
    {
        $this->expectException(NotFound::class);

        $this->useCase->execute(new WithdrawMoneyCommand(WalletId::fromString('missing'), 1));
    }
}

<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

use App\Shared\Domain\Assert;
use App\Shared\Domain\DomainViolation;

final class Wallet
{
    private function __construct(
        public readonly WalletId $id,
        public readonly int      $userId,
        private int              $balance,
    )
    {
        if ($userId <= 0) {
            throw new DomainViolation('UserId must be positive.');
        }

        Assert::nonNegative($balance, 'balance');
    }

    public static function open(
        WalletId $id,
        int      $userId,
    ): self
    {
        return new self(
            id: $id,
            userId: $userId,
            balance: 0,
        );
    }

    public static function restore(
        WalletId $id,
        int      $userId,
        int      $balance,
    ): self
    {
        return new self(
            id: $id,
            userId: $userId,
            balance: $balance,
        );
    }

    public function balance(): int
    {
        return $this->balance;
    }

    /**
     * @throws WalletException
     */
    public function withdraw(int $amount): void
    {
        $this->assertPositiveAmount($amount);

        if ($this->balance < $amount) {
            throw new WalletException('Insufficient funds.');
        }

        $this->balance -= $amount;
    }

    /**
     * @throws WalletException
     */
    public function deposit(int $amount): void
    {
        $this->assertPositiveAmount($amount);

        if ($this->balance > PHP_INT_MAX - $amount) {
            throw new WalletException('Balance overflow.');
        }

        $this->balance += $amount;
    }

    private function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new WalletException(
                'Amount must be greater than zero.'
            );
        }
    }
}

<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Application\UseCase;

use App\Modules\Wallet\Application\Command\WithdrawMoneyCommand;
use App\Modules\Wallet\Application\TransactionManager;
use App\Modules\Wallet\Domain\WalletRepository;
use App\Shared\Application\NotFound;

final readonly class WithdrawMoneyUseCase
{
    public function __construct(
        private WalletRepository $wallets,
        private TransactionManager $transactions,
    ) {}

    public function execute(WithdrawMoneyCommand $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $wallet = $this->wallets->findByIdForUpdate($command->walletId)
                ?? throw new NotFound('Wallet not found: '.$command->walletId->value);

            $wallet->withdraw($command->amount);
            $this->wallets->save($wallet);
        });
    }
}

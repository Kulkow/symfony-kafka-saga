<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Application\UseCase;

use App\Modules\Wallet\Application\Command\OpenWalletCommand;
use App\Modules\Wallet\Application\TransactionManager;
use App\Modules\Wallet\Domain\{Wallet, WalletId, WalletIdGenerator, WalletRepository};

final readonly class OpenWalletUseCase
{
    public function __construct(
        private WalletRepository $wallets,
        private WalletIdGenerator $ids,
        private TransactionManager $transactions,
    ) {}

    public function execute(OpenWalletCommand $command): WalletId
    {
        return $this->transactions->run(function () use ($command): WalletId {
            $wallet = Wallet::open($this->ids->next(), $command->userId);
            $this->wallets->add($wallet);

            return $wallet->id;
        });
    }
}

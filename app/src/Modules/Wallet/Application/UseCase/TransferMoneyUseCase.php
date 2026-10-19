<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Application\UseCase;

use App\Modules\Wallet\Application\Command\TransferMoneyCommand;
use App\Modules\Wallet\Application\TransactionManager;
use App\Modules\Wallet\Domain\{WalletException, WalletRepository};
use App\Shared\Application\NotFound;

final readonly class TransferMoneyUseCase
{
    public function __construct(
        private WalletRepository $wallets,
        private TransactionManager $transactions,
    ) {}

    public function execute(TransferMoneyCommand $command): void
    {
        if ($command->fromWalletId->equals($command->toWalletId)) {
            throw new WalletException('Cannot transfer money to the same wallet.');
        }

        $this->transactions->run(function () use ($command): void {
            // Every transfer takes locks in the same order, including opposite transfers.
            $ids = [$command->fromWalletId, $command->toWalletId];
            usort($ids, static fn ($left, $right): int => strcmp($left->value, $right->value));
            $first = $this->wallets->findByIdForUpdate($ids[0])
                ?? throw new NotFound('Wallet not found: '.$ids[0]->value);
            $second = $this->wallets->findByIdForUpdate($ids[1])
                ?? throw new NotFound('Wallet not found: '.$ids[1]->value);
            [$from, $to] = $first->id->equals($command->fromWalletId) ? [$first, $second] : [$second, $first];

            $from->withdraw($command->amount);
            $to->deposit($command->amount);
            $this->wallets->save($from);
            $this->wallets->save($to);
        });
    }
}

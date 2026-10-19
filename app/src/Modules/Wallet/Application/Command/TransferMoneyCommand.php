<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Application\Command;

use App\Modules\Wallet\Domain\WalletId;

final readonly class TransferMoneyCommand
{
    /** Amount is expressed in minor currency units. */
    public function __construct(
        public WalletId $fromWalletId,
        public WalletId $toWalletId,
        public int $amount,
    ) {}
}

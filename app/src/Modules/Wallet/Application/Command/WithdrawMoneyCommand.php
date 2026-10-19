<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Application\Command;

use App\Modules\Wallet\Domain\WalletId;

final readonly class WithdrawMoneyCommand
{
    /** Amount is expressed in minor currency units. */
    public function __construct(public WalletId $walletId, public int $amount) {}
}

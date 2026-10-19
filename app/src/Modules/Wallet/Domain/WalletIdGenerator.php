<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

interface WalletIdGenerator
{
    public function next(): WalletId;
}

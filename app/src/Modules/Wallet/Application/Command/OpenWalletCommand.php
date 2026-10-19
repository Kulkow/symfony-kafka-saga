<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Application\Command;

final readonly class OpenWalletCommand
{
    public function __construct(public int $userId) {}
}

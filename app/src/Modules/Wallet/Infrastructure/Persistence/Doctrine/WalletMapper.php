<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Persistence\Doctrine;

use App\Modules\Wallet\Domain\{Wallet, WalletId};

final class WalletMapper
{
    public function toDomain(WalletRecord $record): Wallet
    {
        return Wallet::restore(
            id: WalletId::fromString($record->id),
            userId: $record->userId,
            balance: $record->balance,
        );
    }

    public function toRecord(Wallet $wallet, ?WalletRecord $record = null): WalletRecord
    {
        $record ??= new WalletRecord();
        $record->id = $wallet->id->value;
        $record->userId = $wallet->userId;
        $record->balance = $wallet->balance();

        return $record;
    }
}

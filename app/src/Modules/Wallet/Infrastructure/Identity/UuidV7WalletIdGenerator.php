<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Identity;

use App\Modules\Wallet\Domain\{WalletId, WalletIdGenerator};
use Symfony\Component\Uid\Uuid;

final class UuidV7WalletIdGenerator implements WalletIdGenerator
{
    public function next(): WalletId
    {
        return WalletId::fromString(Uuid::v7()->toRfc4122());
    }
}

<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wallets', schema: 'wallet')]
final class WalletRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 255)]
    public string $id;

    #[ORM\Column(name: 'user_id', type: 'bigint')]
    public int $userId;

    #[ORM\Column(type: 'bigint')]
    public int $balance;
}

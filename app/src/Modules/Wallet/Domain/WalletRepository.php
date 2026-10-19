<?php
declare(strict_types=1);
namespace App\Modules\Wallet\Domain;

interface WalletRepository
{
    public function findById(WalletId $id): ?Wallet;
    public function findByIdForUpdate(WalletId $id): ?Wallet;
    public function add(Wallet $wallet): void;
    public function save(Wallet $wallet): void;
}

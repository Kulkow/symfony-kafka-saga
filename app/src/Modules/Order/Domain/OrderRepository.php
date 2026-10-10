<?php
declare(strict_types=1);
namespace App\Modules\Order\Domain;
interface OrderRepository
{
    public function nextIdentity(): int;
    public function find(int $id): ?Order;
    public function findByNumber(string $number): ?Order;
    public function save(Order $order): void;
}

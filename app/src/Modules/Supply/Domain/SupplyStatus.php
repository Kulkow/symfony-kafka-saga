<?php
declare(strict_types=1);
namespace App\Modules\Supply\Domain;
enum SupplyStatus: string
{
    case Draft = 'draft';
    case InWork = 'in_work';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Completed = 'completed';

    public function next(): ?self
    {
        return match ($this) {
            self::Draft => self::InWork, self::InWork => self::Delivering,
            self::Delivering => self::Delivered, self::Delivered => self::Completed,
            self::Completed => null,
        };
    }
}

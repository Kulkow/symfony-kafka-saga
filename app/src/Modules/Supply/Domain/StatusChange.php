<?php
declare(strict_types=1);
namespace App\Modules\Supply\Domain;
final readonly class StatusChange
{
    public function __construct(
        public SupplyStatus $oldStatus,
        public SupplyStatus $newStatus,
        public \DateTimeImmutable $date,
    ) {}
}

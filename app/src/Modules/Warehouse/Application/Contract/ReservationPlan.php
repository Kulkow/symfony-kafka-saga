<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Application\Contract;
final readonly class ReservationPlan
{
    public function __construct(
        public array $reservedProducts,
        public array $productionProducts,
        public array $requiredMaterials,
        public array $shortages,
    ) {}

    public function toArray(): array
    {
        return ['reservedProducts' => $this->reservedProducts, 'productionProducts' => $this->productionProducts,
            'requiredMaterials' => $this->requiredMaterials, 'shortages' => $this->shortages];
    }
}

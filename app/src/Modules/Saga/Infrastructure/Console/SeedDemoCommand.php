<?php
declare(strict_types=1);
namespace App\Modules\Saga\Infrastructure\Console;
use App\Modules\Catalog\Application\CatalogService;
use App\Modules\Order\Application\OrderService;
use App\Modules\Warehouse\Application\WarehouseService;
use App\Shared\Application\TransactionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:saga:demo', description: 'Create a demo: 10 ordered products, 5 in stock, 2 of 3 materials available')]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly WarehouseService $warehouse,
        private readonly OrderService $orders,
        private readonly TransactionManager $transactions,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('number', InputArgument::OPTIONAL, 'Unique customer order number', 'DEMO-1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $number = $input->getArgument('number');
        $id = $this->transactions->run(function () use ($number): int {
            $existing = $this->orders->findByNumber($number);
            if ($existing !== null) { return $existing->id; }
            $unitId = $this->catalog->createMeasureUnit('шт');
            $materials = [];
            foreach (['A', 'B', 'C'] as $name) {
                $materials[] = $this->catalog->createMaterial('Материал '.$name.' / '.$number, $unitId);
            }
            $productId = $this->catalog->createProduct('Учебное изделие / '.$number, 10000,
                'DEMO-'.$number, array_fill_keys($materials, 1));
            $warehouseId = $this->warehouse->createWarehouse('Учебный склад / '.$number);
            $this->warehouse->receiveStock($warehouseId, 1, $productId, 5, $unitId);
            foreach (array_slice($materials, 0, 2) as $materialId) {
                $this->warehouse->receiveStock($warehouseId, 2, $materialId, 5, $unitId);
            }
            return $this->orders->create($number, $warehouseId, [$productId => 10]);
        });
        $output->writeln('Order ID: '.$id);
        $output->writeln('Run app:outbox:publish --watch and app:saga:consume, then app:order:show '.$id);
        return Command::SUCCESS;
    }
}

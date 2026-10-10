<?php
declare(strict_types=1);
namespace App\Modules\Order\Infrastructure\Console;
use App\Modules\Order\Application\OrderService;
use App\Modules\Supply\Application\SupplyService;
use App\Modules\Warehouse\Application\WarehouseService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:order:show', description: 'Show an order, its reservation plan and supply request')]
final class ShowOrderCommand extends Command
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly WarehouseService $warehouse,
        private readonly SupplyService $supply,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Customer order ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        if (!ctype_digit($id) || (int) $id < 1) {
            throw new \InvalidArgumentException('Order ID must be a positive integer.');
        }
        $id = (int) $id;
        $output->writeln(json_encode([
            'order' => $this->orders->get($id),
            'reservationPlan' => $this->warehouse->plan($id),
            'supplyRequest' => $this->supply->requestForOrder($id),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}

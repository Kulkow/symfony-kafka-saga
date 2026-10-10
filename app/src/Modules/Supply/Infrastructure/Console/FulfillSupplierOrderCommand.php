<?php
declare(strict_types=1);
namespace App\Modules\Supply\Infrastructure\Console;
use App\Modules\Supply\Application\SupplyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:supply:fulfill', description: 'Record that a supplier order has been fulfilled')]
final class FulfillSupplierOrderCommand extends Command
{
    public function __construct(private readonly SupplyService $supply) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('id', InputArgument::REQUIRED, 'Supplier order ID'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        if (!ctype_digit($id) || (int) $id < 1) {
            throw new \InvalidArgumentException('Supplier order ID must be a positive integer.');
        }
        $this->supply->fulfillSupplierOrder((int) $id);
        $output->writeln('Supplier fulfillment recorded. The saga will receive and reserve the delivery.');
        return Command::SUCCESS;
    }
}

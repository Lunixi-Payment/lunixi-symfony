<?php
declare(strict_types=1);
namespace Lunixi\Symfony;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallLedgerCommand extends Command
{
    public function __construct(private readonly DoctrineLedgerStore $ledger) { parent::__construct('lunixi:ledger:install'); }
    protected function configure(): void { $this->setDescription('Create the durable Lunixi attempt and webhook inbox tables.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ledger->install();
        $output->writeln('Lunixi ledger schema is ready. Existing financial history was preserved.');
        return Command::SUCCESS;
    }
}

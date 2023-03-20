<?php declare(strict_types=1);

namespace LedgerDirect\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use LedgerDirect\Service\XrplTransactionFileService;
use LedgerDirect\Service\XrplTxService;

class XrplTransactionLookupCommand extends Command
{
    protected static $defaultName = 'ledger-direct:xrpl-transaction:lookup';

    protected XrplTxService $txService;

    public function __construct(
        XrplTxService $txService
    ) {
        parent::__construct();

        $this->txService = $txService;
    }

    public function configure()
    {
        parent::configure();

        $this->setDescription('XRPL transaction lookup');
        $this->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'Hash identifying a tx');
        $this->addOption('ctid', null, InputOption::VALUE_OPTIONAL, 'CTID identifying a validated tx');
        $this->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Tx source - XRPL, DB or BOTH');
        $this->addOption('write', null, InputOption::VALUE_OPTIONAL, 'Write result to file system');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hash = $input->getOption('hash');
        $ctid  = $input->getOption('ctid');

        if ($hash xor $ctid) {

            $source = $input->getOption('source');

            if ($source === 'XRPL' or $source === 'BOTH') {

            }

            if ($source === 'DB' or $source === 'BOTH') {

            }

            return Command::SUCCESS;
        }

        $output->writeln('Either a --hash or a --ctid is required as a parameter');

        return Command::FAILURE;
    }
}
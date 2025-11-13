<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Command;

use Hardcastle\LedgerDirect\Service\XrplTxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class XrplTransactionLookupCommand extends Command
{
    protected static $defaultName = 'ledger-direct:xrpl-transaction:lookup';

    protected XrplTxService $txService;

    public function __construct(XrplTxService $txService) {
        parent::__construct(self::$defaultName);
        $this->txService = $txService;
    }

    /**
     * Configure the command options and description.
     *
     * @return void
     */
    public function configure(): void
    {
        parent::configure();

        $this->setDescription('XRPL transaction lookup');
        $this->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'Hash identifying a tx');
        $this->addOption('ctid', null, InputOption::VALUE_OPTIONAL, 'CTID identifying a validated tx');
        $this->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Tx source - XRPL, DB or BOTH');
        $this->addOption('write', null, InputOption::VALUE_OPTIONAL, 'Write result to file system');
    }

    /**
     * Executes the command logic.
     *
     * Retrieves the 'hash' and 'ctid' options from the input. Ensures that
     * exactly one of these options is provided. Based on the provided 'source'
     * option, it processes the input against the specified source ('XRPL', 'DB', or 'BOTH').
     * Returns a success code if the operation completes, or an error code with
     * a message if neither 'hash' nor 'ctid' is provided.
     *
     * @param InputInterface $input The input interface containing command options.
     * @param OutputInterface $output The output interface for writing command result.
     *
     * @return int Returns Command::SUCCESS on successful execution,
     *             or Command::FAILURE if the required options are not provided.
     */
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
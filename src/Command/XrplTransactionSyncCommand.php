<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Command;

use Doctrine\DBAL\Exception;
use GuzzleHttp\Exception\GuzzleException;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class XrplTransactionSyncCommand extends Command
{
    protected static $defaultName = 'ledger-direct:xrpl-transaction:sync';

    protected XrplTxService $txService;

    public function __construct(XrplTxService $txService)
    {
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

        $this->setDescription('XRPL tx sync');
        $this->addOption('address', null, InputOption::VALUE_REQUIRED, 'XRPL Address to check for incoming transactions');
        $this->addOption('force', null, InputOption::VALUE_OPTIONAL, 'Truncate table upfront');
    }

    /**
     * Executes the command to synchronize transactions for the specified address.
     *
     * Retrieves the 'address' option provided by the user. If the 'force' option is enabled,
     * it resets the database before proceeding with the synchronization process.
     *
     * @param InputInterface $input The input instance containing command options and arguments.
     * @param OutputInterface $output The output instance to provide feedback during execution.
     *
     * @return int Returns the command exit status, indicating success.
     * @throws Exception
     * @throws GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = $input->getOption('address');

        if ($input->hasOption('force')) {
            $this->txService->resetDatabase();
        }

        $this->txService->syncTransactions($address);

        return Command::SUCCESS;
    }
}
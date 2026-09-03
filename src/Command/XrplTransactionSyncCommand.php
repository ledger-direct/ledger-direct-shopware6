<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Command;

use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Port\DbalXrplTransactionRepository;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class XrplTransactionSyncCommand extends Command
{
    protected static $defaultName = 'ledger-direct:xrpl-transaction:sync';

    private SyncService $syncService;

    private DbalXrplTransactionRepository $transactionRepository;

    private ShopwareConfigProvider $configProvider;

    public function __construct(
        SyncService $syncService,
        DbalXrplTransactionRepository $transactionRepository,
        ShopwareConfigProvider $configProvider
    ) {
        parent::__construct(self::$defaultName);
        $this->syncService = $syncService;
        $this->transactionRepository = $transactionRepository;
        $this->configProvider = $configProvider;
    }

    /**
     * Configure the command options and description.
     */
    public function configure(): void
    {
        parent::configure();

        $this->setDescription('XRPL tx sync');
        $this->addOption('address', null, InputOption::VALUE_REQUIRED, 'XRPL Address to check for incoming transactions');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Truncate the synced transaction table upfront');
    }

    /**
     * Synchronizes incoming transactions for the given address.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = (string) $input->getOption('address');

        /*
         * hasOption() only asks whether the option is *defined*, so the old
         * check was always true and every run truncated the table. getOption()
         * is what actually reports whether the flag was passed.
         */
        if ($input->getOption('force')) {
            $this->transactionRepository->truncate();
        }

        $this->syncService->syncTransactions(
            $address,
            $this->configProvider->getNetwork(ShopwareConfigProvider::CHAIN)
        );

        return Command::SUCCESS;
    }
}

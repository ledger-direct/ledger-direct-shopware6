<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Command;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Stellar\SyncService as StellarSyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\DbalStellarPaymentRepository;
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
        ShopwareConfigProvider $configProvider,
        private readonly StellarSyncService $stellarSync,
        private readonly DbalStellarPaymentRepository $stellarRepository,
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

        $this->setDescription('Sync the incoming payments of a receiving account from the ledger');
        $this->addOption('chain', null, InputOption::VALUE_REQUIRED, 'XRPL or STELLAR', ChainCatalog::XRPL);
        $this->addOption('address', null, InputOption::VALUE_REQUIRED, 'The receiving account to check for incoming payments');
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Truncate the synced transaction table upfront. No longer needed after an XRPL testnet '
                . 'reset — the sync recovers from a stranded cursor by itself.'
        );
    }

    /**
     * Synchronizes incoming transactions for the given address.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = (string) $input->getOption('address');
        $chain = strtoupper((string) $input->getOption('chain'));

        if (!isset(PaymentMethodInstaller::HANDLER_BY_CHAIN[$chain])) {
            $output->writeln('Unknown chain ' . $chain . '; one of ' . implode(', ', array_keys(PaymentMethodInstaller::HANDLER_BY_CHAIN)));

            return Command::FAILURE;
        }

        $network = $this->configProvider->getNetwork($chain);

        /*
         * hasOption() only asks whether the option is *defined*, so the old
         * check was always true and every run truncated the table. getOption()
         * is what actually reports whether the flag was passed.
         */
        if ($input->getOption('force')) {
            $chain === ChainCatalog::STELLAR ? $this->stellarRepository->truncate() : $this->transactionRepository->truncate();
        }

        $chain === ChainCatalog::STELLAR
            ? $this->stellarSync->sync($address, $network)
            : $this->syncService->syncTransactions($address, $network);

        return Command::SUCCESS;
    }
}

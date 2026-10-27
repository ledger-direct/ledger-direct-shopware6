<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Command;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Port\DbalStellarPaymentRepository;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class XrplTransactionLookupCommand extends Command
{
    protected static $defaultName = 'ledger-direct:xrpl-transaction:lookup';

    private XrplClient $xrplClient;

    private ShopwareConfigProvider $configProvider;

    public function __construct(
        XrplClient $xrplClient,
        ShopwareConfigProvider $configProvider,
        private readonly DbalStellarPaymentRepository $stellarRepository,
    ) {
        parent::__construct(self::$defaultName);
        $this->xrplClient = $xrplClient;
        $this->configProvider = $configProvider;
    }

    /**
     * Configure the command options and description.
     */
    public function configure(): void
    {
        parent::configure();

        $this->setDescription('Transaction lookup: XRPL on the ledger by --hash or --ctid; Stellar in the synced table by --account and --id');
        $this->addOption('chain', null, InputOption::VALUE_REQUIRED, 'XRPL or STELLAR', ChainCatalog::XRPL);
        $this->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'XRPL: hash identifying a tx');
        $this->addOption('ctid', null, InputOption::VALUE_OPTIONAL, 'XRPL: CTID identifying a validated tx');
        $this->addOption('account', null, InputOption::VALUE_OPTIONAL, 'Stellar: the receiving account');
        $this->addOption('id', null, InputOption::VALUE_OPTIONAL, 'Stellar: the memo id shown on the payment page');
    }

    /**
     * Looks a single transaction up on the ledger. Exactly one of --hash or
     * --ctid identifies it; the XRPL `tx` method accepts either.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (strtoupper((string) $input->getOption('chain')) === ChainCatalog::STELLAR) {
            $account = (string) $input->getOption('account');
            $memoId = (string) $input->getOption('id');

            if ($account === '' || $memoId === '') {
                $output->writeln('Stellar: --account and --id are required');

                return Command::FAILURE;
            }

            $payments = array_map(static fn ($p): array => get_object_vars($p), $this->stellarRepository->findPayments($account, $memoId));
            $output->writeln(json_encode($payments, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $hash = $input->getOption('hash');
        $ctid = $input->getOption('ctid');

        if (!($hash xor $ctid)) {
            $output->writeln('Either a --hash or a --ctid is required as a parameter');

            return Command::FAILURE;
        }

        $transaction = $this->xrplClient->tx(
            (string) ($hash ?: $ctid),
            $this->configProvider->getNetwork(ShopwareConfigProvider::CHAIN)
        );

        if ($transaction === null) {
            $output->writeln('Transaction not found on the ledger.');

            return Command::FAILURE;
        }

        $output->writeln(json_encode($transaction, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}

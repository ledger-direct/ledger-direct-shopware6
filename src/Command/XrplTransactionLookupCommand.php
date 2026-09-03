<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Command;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
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

    public function __construct(XrplClient $xrplClient, ShopwareConfigProvider $configProvider)
    {
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

        $this->setDescription('XRPL transaction lookup');
        $this->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'Hash identifying a tx');
        $this->addOption('ctid', null, InputOption::VALUE_OPTIONAL, 'CTID identifying a validated tx');
    }

    /**
     * Looks a single transaction up on the ledger. Exactly one of --hash or
     * --ctid identifies it; the XRPL `tx` method accepts either.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

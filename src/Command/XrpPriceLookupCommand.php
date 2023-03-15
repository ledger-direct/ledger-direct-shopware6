<?php declare(strict_types=1);

namespace LedgerDirect\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use LedgerDirect\Provider\CryptoPriceProviderInterface;

class XrpPriceLookupCommand extends Command
{
    protected static $defaultName = 'ledger-direct:xrp-price:lookup';

    protected CryptoPriceProviderInterface $priceFinder;

    public function __construct(CryptoPriceProviderInterface $priceFinder)
    {
        parent::__construct();

        $this->priceFinder = $priceFinder;
    }

    public function configure()
    {
        parent::configure();

        $this->setDescription('XRP price lookup, when no options are provided, default price providers will be looked up');
        $this->addOption('provider', 's', InputOption::VALUE_OPTIONAL, 'define providers to be queried for price');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $currentPrice = $this->priceFinder->getCurrentExchangeRate();
        $output->writeln('Current XRP price: ' . $currentPrice);

        return Command::SUCCESS;
    }
}
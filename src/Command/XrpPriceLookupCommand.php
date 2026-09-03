<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Command;

use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class XrpPriceLookupCommand extends Command
{
    protected static $defaultName = 'ledger-direct:xrp-price:lookup';

    private PriceService $priceService;

    private ShopwareConfigProvider $configProvider;

    public function __construct(PriceService $priceService, ShopwareConfigProvider $configProvider)
    {
        parent::__construct(self::$defaultName);
        $this->priceService = $priceService;
        $this->configProvider = $configProvider;
    }

    /**
     * Configure the command options and description.
     */
    public function configure(): void
    {
        parent::configure();

        $this->setDescription('XRP price lookup against the core price oracles');
        $this->addOption('iso', null, InputOption::VALUE_REQUIRED, 'Quote currency ISO code, e.g. EUR');
    }

    /**
     * Performs the price query for XRP and outputs the result in the console.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $quoteCurrency = (string) $input->getOption('iso');
        $network = $this->configProvider->getNetwork(ShopwareConfigProvider::CHAIN);

        // A total of 1.0 makes the returned quote's exchange rate the price of one XRP.
        $quote = $this->priceService->getCryptoPriceForOrder(1.0, $quoteCurrency, 'XRP', $network);

        $output->writeln('Current XRP price: ' . $quote->exchangeRate);

        return Command::SUCCESS;
    }
}

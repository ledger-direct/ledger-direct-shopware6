<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Command;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
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

        $this->setDescription('Price lookup against the core price oracles');
        $this->addOption('iso', null, InputOption::VALUE_REQUIRED, 'Quote currency ISO code, e.g. EUR');
        $this->addOption('chain', null, InputOption::VALUE_REQUIRED, 'XRPL or STELLAR', ChainCatalog::XRPL);
        $this->addOption('asset', null, InputOption::VALUE_OPTIONAL, 'The asset; the chain\'s native one by default');
    }

    /**
     * Performs the price query for XRP and outputs the result in the console.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $quoteCurrency = (string) $input->getOption('iso');
        $chain = strtoupper((string) $input->getOption('chain'));

        if (!ChainCatalog::has($chain)) {
            $output->writeln('Unknown chain ' . $chain);

            return Command::FAILURE;
        }

        $asset = strtoupper((string) ($input->getOption('asset') ?: ChainCatalog::chain($chain)->nativeAsset));
        $network = $this->configProvider->getNetwork($chain);

        // A total of 1.0 makes the returned quote's exchange rate the price of one unit.
        $quote = $this->priceService->quoteAmount(1.0, $quoteCurrency, $asset, $network);

        $output->writeln('Current ' . $asset . ' price: ' . $quote->exchangeRate . ' ' . $quoteCurrency);

        return Command::SUCCESS;
    }
}

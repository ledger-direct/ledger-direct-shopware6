<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\StablecoinProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;

use Hardcastle\LedgerDirect\Service\XrplTxService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Mockery;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

class OrderTransactionServiceMock
{
    public static function createInstance(): OrderTransactionService
    {
        $configurationService = ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration()); // ConfigurationService
        $orderRepository = Mockery::mock(EntityRepository::class); // EntityRepository
        $orderTransactionRepository = Mockery::mock(EntityRepository::class); // EntityRepository
        $xrplSyncService = Mockery::mock(XrplTxService::class); // XrplTxService
        $currencyRepository = Mockery::mock(EntityRepository::class); // EntityRepository
        $xrpPriceProvider = Mockery::mock(CryptoPriceProviderInterface::class); // CryptoPriceProviderInterface
        $rlusdPriceProvider = Mockery::mock(CryptoPriceProviderInterface::class); // CryptoPriceProviderInterface
        $usdcPriceProvider = Mockery::mock(CryptoPriceProviderInterface::class); // CryptoPriceProviderInterface

        $stablecoinProvider = new StablecoinProvider($configurationService);

        $currencyMock = Mockery::mock();
        $currencyMock->shouldReceive('getIsoCode')
            ->andReturn('EUR');

        $entitySearchResult = Mockery::mock(EntitySearchResult::class);
        $entitySearchResult->shouldReceive('first')
            ->andReturn($currencyMock);

        $currencyRepository->shouldReceive('search')
            ->andReturn($entitySearchResult);

        $xrpPriceProvider->shouldReceive('getCurrentExchangeRate')
            ->with('EUR')
            ->andReturn(2.5);

        $rlusdPriceProvider->shouldReceive('getCurrentExchangeRate')
            ->with('EUR')
            ->andReturn(1.18);
        $rlusdPriceProvider->shouldReceive('getCurrentExchangeRate')
            ->with('USD')
            ->andReturn(1);

        $usdcPriceProvider->shouldReceive('getCurrentExchangeRate')
            ->with('EUR')
            ->andReturn(1.0);
        $usdcPriceProvider->shouldReceive('getCurrentExchangeRate')
            ->with('USD')
            ->andReturn(1);

        return new OrderTransactionService(
            $configurationService,
            $orderRepository,
            $orderTransactionRepository,
            $xrplSyncService,
            $currencyRepository,
            $xrpPriceProvider,
            $rlusdPriceProvider,
            $usdcPriceProvider,
            $stablecoinProvider
        );
    }

    public static function createMock(): OrderTransactionService
    {
        return Mockery::mock(OrderTransactionService::class);
    }
}


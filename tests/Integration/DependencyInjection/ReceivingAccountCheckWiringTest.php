<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\DependencyInjection;

use Hardcastle\LedgerDirect\Api\ReceivingAccountController;
use Hardcastle\LedgerDirect\Checkout\PaymentMethodRouteDecorator;
use Hardcastle\LedgerDirect\Core\Account\ReceivingAccountCheck;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Xrpl\AccountInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * The checkout and the configuration card ask one receiving-account check per
 * chain. A chain without one would get its tokens offered unchecked — the
 * decorator then falls back to "tokens out", but the wiring must not need the
 * fallback: every chain of the catalogue has its check, the XRPL-protocol ones
 * on their own chain and with the shop's cache (without it, every checkout asks
 * the node for every method).
 */
class ReceivingAccountCheckWiringTest extends TestCase
{
    use KernelTestBehaviour;

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function consumers(): iterable
    {
        yield 'checkout' => [PaymentMethodRouteDecorator::class];
        yield 'configuration card' => [ReceivingAccountController::class];
    }

    #[DataProvider('consumers')]
    public function testEveryChainHasItsCheck(string $serviceId): void
    {
        $checks = self::property($this->getContainer()->get($serviceId), 'checks');

        foreach (ChainCatalog::chains() as $chain) {
            $check = $checks[$chain->chain] ?? null;
            $this->assertInstanceOf(ReceivingAccountCheck::class, $check, "{$serviceId}: no check for {$chain->chain}");

            if ($check instanceof AccountInspector) {
                $this->assertSame($chain->chain, self::property($check, 'chain'));
                $this->assertInstanceOf(CacheInterface::class, self::property($check, 'cache'), "{$chain->chain} inspector has no cache");
            }
        }
    }

    private static function property(object $service, string $name): mixed
    {
        return (new \ReflectionProperty($service, $name))->getValue($service);
    }
}

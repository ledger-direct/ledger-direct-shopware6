<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\DependencyInjection;

use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * The core's sync remembers a testnet reset only when it has a cache; without
 * one every sync after a reset starts with a failing request, and nothing
 * else notices. Both XRPL-protocol syncs must therefore get the cache their
 * throttle already has, and their own chain, which keeps an account that
 * exists on both chains apart in that cache.
 */
class SyncServiceWiringTest extends TestCase
{
    use KernelTestBehaviour;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function chains(): iterable
    {
        yield 'XRP Ledger' => [SyncService::class, SyncThrottle::class, 'XRPL'];
        yield 'Xahau' => ['ledger_direct.xahau.sync', 'ledger_direct.xahau.sync_throttle', 'XAHAU'];
    }

    #[DataProvider('chains')]
    public function testTheSyncGetsTheThrottlesCacheAndItsChain(string $syncId, string $throttleId, string $chain): void
    {
        $sync = $this->getContainer()->get($syncId);
        $throttle = $this->getContainer()->get($throttleId);

        $this->assertInstanceOf(SyncService::class, $sync);
        $this->assertInstanceOf(SyncThrottle::class, $throttle);

        $cache = self::property($sync, 'cache');
        $this->assertInstanceOf(CacheInterface::class, $cache, "{$syncId} has no cache");
        $this->assertSame(self::property($throttle, 'cache'), $cache, "{$syncId} uses another cache than {$throttleId}");
        $this->assertSame($chain, self::property($sync, 'chain'));
    }

    private static function property(object $service, string $name): mixed
    {
        return (new \ReflectionProperty($service, $name))->getValue($service);
    }
}

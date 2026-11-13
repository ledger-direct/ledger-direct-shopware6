<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Lifecycle;

use Hardcastle\LedgerDirect\LedgerDirect;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * The update lifecycle runs with the shop's real container, in which private
 * services cannot be fetched. A unit test with a mocked container does not
 * notice a `get()` on one of them — the update then fails in every shop and
 * in no test. So this one runs `update()` against the kernel's own container,
 * not the test container that hands out everything.
 */
class PluginUpdateTest extends TestCase
{
    use IntegrationTestBehaviour;
    use MockeryPHPUnitIntegration;

    public function testUpdateRunsAgainstTheShopsOwnContainer(): void
    {
        $plugin = new LedgerDirect(true, dirname(__DIR__, 3));
        $plugin->setContainer(self::getKernel()->getContainer());

        $plugin->update(new UpdateContext(
            $plugin,
            Context::createDefaultContext(),
            '6.7.0.0',
            '1.10.2',
            Mockery::mock(MigrationCollection::class),
            '1.11.0',
        ));

        $this->addToAssertionCount(1);
    }
}

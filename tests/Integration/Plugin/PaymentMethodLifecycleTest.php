<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Plugin;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\LedgerDirect;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The plugin's payment methods follow the plugin: on when it is activated,
 * off when it is deactivated or uninstalled. The second half is the one
 * that matters. With the plugin inactive nothing of it runs, the filter of
 * the checkout included, and Shopware goes on offering every method that is
 * still switched on, with no handler behind it.
 *
 * Through the plugin class and the real payment_method table: the installer
 * had the three methods all along, and nothing called them.
 */
class PaymentMethodLifecycleTest extends TestCase
{
    use IntegrationTestBehaviour; // wraps each test in a rolled-back DB transaction

    private const RELEASED = ['ledgerdirect_xahau_xah', 'ledgerdirect_xrpl_rlusd', 'ledgerdirect_xrpl_usdc', 'ledgerdirect_xrpl_xrp'];

    private const STELLAR = ['ledgerdirect_stellar_eurc', 'ledgerdirect_stellar_usdc', 'ledgerdirect_stellar_xlm'];

    private LedgerDirect $plugin;

    protected function setUp(): void
    {
        $this->plugin = new LedgerDirect(true, \dirname(__DIR__, 3));
        $this->plugin->setContainer($this->getContainer());

        // Whatever the test database was left with: every test starts from the released
        // chains' methods, switched off, and without a preview chain's.
        $this->connection()->executeStatement(
            'DELETE FROM `payment_method` WHERE `id` IN (:ids)',
            ['ids' => array_map(static fn (array $method): string => Uuid::fromHexToBytes($method['id']), PaymentMethodInstaller::paymentMethodsFor(ChainCatalog::STELLAR))],
            ['ids' => ArrayParameterType::BINARY]
        );
        $this->switchAll(false);
    }

    public function testActivatingThePluginSwitchesItsPaymentMethodsOn(): void
    {
        $this->plugin->activate(new ActivateContext($this->plugin, Context::createDefaultContext(), '6.7.0.0', '1.0.0', $this->migrations()));

        $this->assertSame(array_fill_keys(self::RELEASED, true), $this->active());
    }

    /**
     * A preview chain's methods come into being when the merchant switches
     * the chain on, not with the plugin. Activating creates none of them;
     * where they exist they follow the plugin like the others.
     */
    public function testActivatingCreatesNoPreviewMethodsAndSwitchesExistingOnesOn(): void
    {
        $this->plugin->activate(new ActivateContext($this->plugin, Context::createDefaultContext(), '6.7.0.0', '1.0.0', $this->migrations()));
        $this->assertSame(self::RELEASED, array_keys($this->active()), 'no Stellar method was created');

        $this->installer()->installChain(ChainCatalog::STELLAR, Context::createDefaultContext());
        $this->switchAll(false);

        $this->plugin->activate(new ActivateContext($this->plugin, Context::createDefaultContext(), '6.7.0.0', '1.0.0', $this->migrations()));

        $this->assertSame(array_fill_keys([...self::RELEASED, ...self::STELLAR], true), $this->active());
    }

    public function testDeactivatingThePluginSwitchesEveryPaymentMethodOff(): void
    {
        $this->installer()->installChain(ChainCatalog::STELLAR, Context::createDefaultContext());
        $this->switchAll(true);

        $this->plugin->deactivate(new DeactivateContext($this->plugin, Context::createDefaultContext(), '6.7.0.0', '1.0.0', $this->migrations()));

        $this->assertSame(array_fill_keys([...self::RELEASED, ...self::STELLAR], false), $this->active());
    }

    /**
     * A sales channel's default payment method can be one of the plugin's.
     * That must not keep the plugin from being deactivated, or the method
     * from going off.
     */
    public function testAMethodThatIsASalesChannelsDefaultGoesOffToo(): void
    {
        $this->switchAll(true);
        $this->connection()->executeStatement(
            'UPDATE `sales_channel` SET `payment_method_id` = :id',
            ['id' => Uuid::fromHexToBytes(PaymentMethodInstaller::XRP_PAYMENT_ID)]
        );

        $this->plugin->deactivate(new DeactivateContext($this->plugin, Context::createDefaultContext(), '6.7.0.0', '1.0.0', $this->migrations()));

        $this->assertSame(array_fill_keys(self::RELEASED, false), $this->active());
    }

    /**
     * Shopware deactivates an active plugin before it uninstalls it. This
     * is the other case: the plugin was inactive, and a method was switched
     * on by hand. The methods stay, for the orders that carry them, whether
     * or not the merchant asked for the plugin's data to be removed.
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testUninstallingSwitchesThePaymentMethodsOffAndKeepsThem(bool $keepUserData): void
    {
        $this->switchAll(true);

        $this->plugin->uninstall(new UninstallContext($this->plugin, Context::createDefaultContext(), '6.7.0.0', '1.0.0', $this->migrations(), $keepUserData));

        $this->assertSame(array_fill_keys(self::RELEASED, false), $this->active());
    }

    /**
     * An update is not an activation: a method the merchant switched off
     * stays off, and one that is on stays on.
     */
    public function testAnUpdateLeavesTheSwitchesAsTheMerchantSetThem(): void
    {
        $this->switchAll(true);
        $this->connection()->executeStatement(
            'UPDATE `payment_method` SET `active` = 0 WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes(PaymentMethodInstaller::USDC_PAYMENT_ID)]
        );

        $this->plugin->update(new UpdateContext($this->plugin, Context::createDefaultContext(), '6.7.0.0', '1.0.0', $this->migrations(), '1.0.1'));

        $this->assertSame(
            ['ledgerdirect_xahau_xah' => true, 'ledgerdirect_xrpl_rlusd' => true, 'ledgerdirect_xrpl_usdc' => false, 'ledgerdirect_xrpl_xrp' => true],
            $this->active()
        );
    }

    /**
     * @return array<string, bool> technical name => active, of every method of the plugin that exists
     */
    private function active(): array
    {
        $rows = $this->connection()->fetchAllKeyValue(
            'SELECT `technical_name`, `active` FROM `payment_method` WHERE `id` IN (:ids) ORDER BY `technical_name`',
            ['ids' => $this->ids()],
            ['ids' => ArrayParameterType::BINARY]
        );

        $released = [];
        $stellar = [];
        foreach ($rows as $name => $active) {
            if (\in_array($name, self::STELLAR, true)) {
                $stellar[(string) $name] = (bool) $active;
            } else {
                $released[(string) $name] = (bool) $active;
            }
        }

        return $released + $stellar;
    }

    private function switchAll(bool $active): void
    {
        $this->connection()->executeStatement(
            'UPDATE `payment_method` SET `active` = :active WHERE `id` IN (:ids)',
            ['active' => (int) $active, 'ids' => $this->ids()],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    /**
     * @return list<string> the binary ids of every method the plugin knows
     */
    private function ids(): array
    {
        return array_map(static fn (string $id): string => Uuid::fromHexToBytes($id), PaymentMethodInstaller::paymentMethodIds());
    }

    private function installer(): PaymentMethodInstaller
    {
        return new PaymentMethodInstaller(
            $this->getContainer()->get('payment_method.repository'),
            $this->getContainer()->get(PluginIdProvider::class)
        );
    }

    private function migrations(): MigrationCollection
    {
        return $this->createMock(MigrationCollection::class);
    }

    private function connection(): Connection
    {
        return $this->getContainer()->get(Connection::class);
    }
}

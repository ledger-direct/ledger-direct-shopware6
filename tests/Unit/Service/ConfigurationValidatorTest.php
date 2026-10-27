<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Exception\InvalidConfigurationException;
use Hardcastle\LedgerDirect\Service\ConfigurationValidator;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigChangedEvent;

/**
 * A receiving account is checked against the core's format rule the moment
 * it is saved: refused when it is not an account on that chain, trimmed when
 * it is, left alone when the key is not an account at all.
 */
class ConfigurationValidatorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testAnAccountThatIsNotOneIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid XRP Ledger account \(mainnet\)/');

        $this->validator()->onBeforeChange(new BeforeSystemConfigChangedEvent('LedgerDirect.config.xrplMainnetDestinationAccount', 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA', null));
    }

    public function testAStellarKeyIsCheckedAgainstTheStellarFormat(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid Stellar account \(testnet\)/');

        $this->validator()->onBeforeChange(new BeforeSystemConfigChangedEvent('LedgerDirect.config.stellarTestnetDestinationAccount', 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr', null));
    }

    public function testAValidAccountIsTrimmedAndKept(): void
    {
        $event = new BeforeSystemConfigChangedEvent('LedgerDirect.config.xrplTestnetDestinationAccount', " rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh\n", null);

        $this->validator()->onBeforeChange($event);

        $this->assertSame('rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh', $event->getValue());
    }

    public function testAnEmptyAccountAndOtherKeysPass(): void
    {
        $validator = $this->validator();

        $empty = new BeforeSystemConfigChangedEvent('LedgerDirect.config.xrplMainnetDestinationAccount', '  ', null);
        $validator->onBeforeChange($empty);
        $this->assertSame('', $empty->getValue(), 'cleared, not refused');

        $other = new BeforeSystemConfigChangedEvent('LedgerDirect.config.paymentPageAccentColor', 'not an account', null);
        $validator->onBeforeChange($other);
        $this->assertSame('not an account', $other->getValue());

        $foreign = new BeforeSystemConfigChangedEvent('core.basicInformation.shopName', 'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh', null);
        $validator->onBeforeChange($foreign);
        $this->assertSame('rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh', $foreign->getValue());
    }

    /**
     * Impossible between the XRPL and Stellar by format; the rule is there
     * for a chain that shares the XRPL's address format. A warning, not a
     * refusal: the merchant may mean it.
     */
    public function testTheSameAccountOnTwoChainsIsLoggedNotRefused(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->with('LedgerDirect: the same receiving account is configured on two chains', Mockery::type('array'));

        // Pretend another chain already holds the XRPL testnet account of the fixture under a Stellar key —
        // the configuration service reads keys by name, so the format of the other chain plays no role here.
        $configuration = Fixtures::getStaticConfiguration() + ['LedgerDirect.config.stellarMainnetDestinationAccount' => 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr'];
        $validator = new ConfigurationValidator(ConfigurationServiceMock::createInstance($configuration), $logger);

        $event = new BeforeSystemConfigChangedEvent('LedgerDirect.config.xrplMainnetDestinationAccount', 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr', null);
        $validator->onBeforeChange($event);

        $this->assertSame('rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr', $event->getValue());
    }

    private function validator(): ConfigurationValidator
    {
        return new ConfigurationValidator(ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration()), new NullLogger());
    }
}

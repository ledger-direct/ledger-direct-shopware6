<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Exception\InvalidConfigurationException;
use Hardcastle\LedgerDirect\Service\ConfigurationValidator;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigMultipleChangedEvent;

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

    /*
     * The external front end's URL templates: checked on save like an
     * account — a template a guest could not open is refused.
     */

    public function testATemplateWithoutTheCodeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/must contain \{deepLinkCode\}/');

        $this->validator()->onBeforeChange(new BeforeSystemConfigChangedEvent('LedgerDirect.config.headlessPaymentPageUrl', 'https://frontend.example/pay/{orderId}', null));
    }

    public function testARelativeTemplateIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/absolute URL/');

        $this->validator()->onBeforeChange(new BeforeSystemConfigChangedEvent('LedgerDirect.config.headlessOrderPageUrl', '/orders/{orderId}?code={deepLinkCode}', null));
    }

    public function testAPlainHttpTemplateToARealHostIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/must use https/');

        $this->validator()->onBeforeChange(new BeforeSystemConfigChangedEvent('LedgerDirect.config.headlessPaymentPageUrl', 'http://frontend.example/pay/{orderId}?code={deepLinkCode}', null));
    }

    public function testAnUnknownPlaceholderIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/unknown placeholder \{token\}/');

        $this->validator()->onBeforeChange(new BeforeSystemConfigChangedEvent('LedgerDirect.config.headlessOrderPageUrl', 'https://frontend.example/orders/{deepLinkCode}?t={token}', null));
    }

    public function testAValidTemplateIsTrimmedAndKeptAndAnEmptyOneClears(): void
    {
        $validator = $this->validator();

        $event = new BeforeSystemConfigChangedEvent('LedgerDirect.config.headlessPaymentPageUrl', " https://frontend.example/pay/{orderId}?code={deepLinkCode}&return={returnUrl}\n", null);
        $validator->onBeforeChange($event);
        $this->assertSame('https://frontend.example/pay/{orderId}?code={deepLinkCode}&return={returnUrl}', $event->getValue());

        $empty = new BeforeSystemConfigChangedEvent('LedgerDirect.config.headlessOrderPageUrl', '  ', null);
        $validator->onBeforeChange($empty);
        $this->assertSame('', $empty->getValue(), 'cleared, not refused');
    }

    /**
     * Impossible between the XRPL and Stellar by format; the rule is there
     * for a chain that shares the XRPL's address format. A warning, not a
     * refusal: the merchant may mean it.
     */
    public function testTheSameAccountOnTwoChainsIsLoggedNotRefused(): void
    {
        /** @var LoggerInterface&MockInterface $logger */
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

    /**
     * The real case: an `r…` address is valid on the XRP Ledger and on
     * Xahau, and the same keys open the same address on both. A merchant may
     * mean it, so it is saved — and logged, because a payment sent on the
     * wrong network would then arrive at an account the shop does watch, in
     * the other chain's table.
     */
    public function testAnXrplAccountSavedForXahauIsKeptAndLogged(): void
    {
        /** @var LoggerInterface&MockInterface $logger */
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->with(
            'LedgerDirect: the same receiving account is configured on two chains',
            ['account' => 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr', 'chains' => ['XAHAU/testnet', 'XRPL/testnet']],
        );
        $validator = new ConfigurationValidator(ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration()), $logger);

        $event = new BeforeSystemConfigChangedEvent('LedgerDirect.config.xahauTestnetDestinationAccount', ' rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr ', null);
        $validator->onBeforeChange($event);

        $this->assertSame('rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr', $event->getValue());
    }

    /**
     * A preview chain runs on its testnet only. The administration saves the toggle
     * as a bool; a client may send it as a string or a number, all read as off.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('offValues')]
    public function testSwitchingAPreviewChainToTheMainnetIsRefused(mixed $off): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Stellar is a preview and runs on the testnet only/');

        $this->validator()->onBeforeChange(new BeforeSystemConfigChangedEvent('LedgerDirect.config.useStellarTestnet', $off, null));
    }

    /** @return array<string, array{0: mixed}> */
    public static function offValues(): array
    {
        return ['bool' => [false], 'int' => [0], 'string zero' => ['0'], 'string false' => ['false'], 'empty' => ['']];
    }

    public function testAReleasedChainMaySwitchToTheMainnetAndAPreviewStayOnTheTestnet(): void
    {
        $validator = $this->validator();

        foreach ([['LedgerDirect.config.useXahauTestnet', false], ['LedgerDirect.config.useXrplTestnet', false], ['LedgerDirect.config.useStellarTestnet', true]] as [$key, $value]) {
            $event = new BeforeSystemConfigChangedEvent($key, $value, null);
            $validator->onBeforeChange($event);
            $this->assertSame($value, $event->getValue(), $key);
        }
    }

    public function testAStellarKeyIsNotAXahauAccount(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $validator = new ConfigurationValidator(ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration()), new NullLogger());
        $validator->onBeforeChange(new BeforeSystemConfigChangedEvent('LedgerDirect.config.xahauMainnetDestinationAccount', Fixtures::STELLAR_ACCOUNT, null));
    }

    /**
     * The administration saves a card as one batch; the batch is checked
     * before anything is written, so a bad account in the middle of it does
     * not leave the keys before it stored. Valid accounts come out trimmed,
     * other keys untouched.
     */
    public function testABatchIsCheckedAsAWholeBeforeAnythingIsWritten(): void
    {
        $validator = $this->validator();

        $clean = new BeforeSystemConfigMultipleChangedEvent([
            'LedgerDirect.config.useXrplTestnet' => false,
            'LedgerDirect.config.xrplMainnetDestinationAccount' => ' rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De ',
            'LedgerDirect.config.xrplIsUsdcEnabled' => true,
        ], null);
        $validator->onBeforeMultipleChange($clean);
        $this->assertSame([
            'LedgerDirect.config.useXrplTestnet' => false,
            'LedgerDirect.config.xrplMainnetDestinationAccount' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            'LedgerDirect.config.xrplIsUsdcEnabled' => true,
        ], $clean->getConfig());

        $this->expectException(InvalidConfigurationException::class);
        $validator->onBeforeMultipleChange(new BeforeSystemConfigMultipleChangedEvent([
            'LedgerDirect.config.useXrplTestnet' => false,
            'LedgerDirect.config.xrplMainnetDestinationAccount' => 'not an account',
        ], null));
    }

    private function validator(): ConfigurationValidator
    {
        return new ConfigurationValidator(ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration()), new NullLogger());
    }
}

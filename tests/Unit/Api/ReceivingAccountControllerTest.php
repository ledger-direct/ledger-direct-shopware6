<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Api;

use Hardcastle\LedgerDirect\Api\ReceivingAccountController;
use Hardcastle\LedgerDirect\Core\Account\Assessment;
use Hardcastle\LedgerDirect\Core\Account\Finding;
use Hardcastle\LedgerDirect\Core\Account\ReceivingAccountCheck;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use PHPUnit\Framework\TestCase;

/**
 * The configuration card's check: the saved receiving account of one chain,
 * assessed for the assets that are switched on; nothing for a chain that is off.
 */
class ReceivingAccountControllerTest extends TestCase
{
    /** @var list<array{account: string, network: string, assets: list<string>}> */
    private array $assessed = [];

    public function testTheFindingsOfTheSavedAccountForTheAssetsThatAreOn(): void
    {
        // Fixture: XRPL on, RLUSD on, USDC off.
        $configuration = Fixtures::getStaticConfiguration();
        $controller = $this->controller($configuration, [
            new Finding(Finding::TRUSTLINE_MISSING, 'RLUSD', true, ['issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV']),
        ]);

        $body = json_decode((string) $controller->check('xrpl')->getContent(), true);

        $this->assertSame([[
            'account' => $configuration['LedgerDirect.config.xrplTestnetDestinationAccount'],
            'network' => 'testnet',
            'assets' => ['XRP', 'RLUSD'],
        ]], $this->assessed);
        $this->assertSame('XRPL', $body['chain']);
        $this->assertSame('XRP', $body['nativeAsset']);
        $this->assertSame([[
            'code' => 'trustline_missing',
            'asset' => 'RLUSD',
            'blocksOffer' => true,
            'context' => ['issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'],
        ]], $body['findings']);
    }

    public function testAChainThatIsOffIsNotChecked(): void
    {
        $configuration = Fixtures::getStaticConfiguration();
        $configuration['LedgerDirect.config.xrplEnabled'] = false;

        $body = json_decode((string) $this->controller($configuration)->check('XRPL')->getContent(), true);

        $this->assertNull($body['account']);
        $this->assertSame([], $body['findings']);
        $this->assertSame([], $this->assessed);
    }

    public function testAnUnknownChainIs404(): void
    {
        $this->assertSame(404, $this->controller(Fixtures::getStaticConfiguration())->check('DOGE')->getStatusCode());
    }

    /**
     * @param array<string, mixed> $configuration
     * @param list<Finding> $findings
     */
    private function controller(array $configuration, array $findings = []): ReceivingAccountController
    {
        $check = new class($this, $findings) implements ReceivingAccountCheck {
            /** @param list<Finding> $findings */
            public function __construct(private readonly ReceivingAccountControllerTest $test, private readonly array $findings)
            {
            }

            public function offers(string $account, string $asset, string $network, ?string $amount = null): bool
            {
                return true;
            }

            public function assess(string $account, string $network, array $assets, bool $fresh = true): Assessment
            {
                $this->test->recordAssessment($account, $network, $assets);

                return new Assessment($account, $network, $this->findings);
            }
        };

        return new ReceivingAccountController(
            new ShopwareConfigProvider(ConfigurationServiceMock::createInstance($configuration)),
            ['XRPL' => $check],
        );
    }

    /**
     * @internal for the stub above
     * @param list<string> $assets
     */
    public function recordAssessment(string $account, string $network, array $assets): void
    {
        $this->assessed[] = ['account' => $account, 'network' => $network, 'assets' => $assets];
    }
}

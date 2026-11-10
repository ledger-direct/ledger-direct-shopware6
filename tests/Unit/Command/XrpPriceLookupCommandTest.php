<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Command;

use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Command\XrpPriceLookupCommand;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\Http\StubHttpClient;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The console's price lookup asks the same core PriceService a checkout
 * does — real service, stubbed oracles.
 */
class XrpPriceLookupCommandTest extends TestCase
{
    public function testPrintsThePrice(): void
    {
        $tester = $this->lookup(new StubHttpClient(2.5));

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Current XRP price: 2.5 EUR', $tester->getDisplay());
    }

    /**
     * Oracles that disagree give no price since core 0.11.1: a plain error
     * line and a failing exit code, not a stack trace.
     */
    public function testNoPriceIsAnErrorMessageNotAnException(): void
    {
        $tester = $this->lookup(StubHttpClient::withDisagreeingOracles());

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No XRP price right now', $tester->getDisplay());
        $this->assertStringContainsString('the oracles disagree', $tester->getDisplay());
    }

    private function lookup(StubHttpClient $httpClient): CommandTester
    {
        $httpFactory = new HttpFactory();
        $command = new XrpPriceLookupCommand(
            new PriceService($httpClient, $httpFactory, new NullLogger()),
            new ShopwareConfigProvider(ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration()))
        );

        $tester = new CommandTester($command);
        $tester->execute(['--iso' => 'EUR']);

        return $tester;
    }
}

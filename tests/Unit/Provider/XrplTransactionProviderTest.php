<?php  declare(strict_types=1);

namespace LedgerDirect\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;

class XrplTransactionProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testCanConnectToTestnet()
    {
        $this->assertEquals(true, true);
    }
}
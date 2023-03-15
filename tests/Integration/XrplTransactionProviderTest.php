<?php  declare(strict_types=1);

namespace LedgerDirect\Tests\Integration\Provider;

use PHPUnit\Framework\TestCase;

class XrplTransactionProviderTest extends TestCase
{
    protected string $standbyAccount;

    protected string $operationalAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->standbyAccount = 'rMKBvkKGvbUVSTrULGWhY32fVvq88pZDLp';
        $this->operationalAccount = 'rwif7LDjdrRVUUPeeeY3FPNWHn1JPWyKkv';
    }

    public function testCanConnectToTestnet()
    {

    }
}
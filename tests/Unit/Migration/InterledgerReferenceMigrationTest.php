<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Migration;

use Hardcastle\LedgerDirect\Interledger\Schema;
use Hardcastle\LedgerDirect\Migration\Migration1793485162InterledgerReference;
use PHPUnit\Framework\TestCase;

/**
 * The reference table's DDL is the package's. The migration carries it as a
 * literal rather than importing it, because the package is optional and the
 * migration has to run either way — so the two have to be checked against
 * each other, and this is where that happens.
 */
class InterledgerReferenceMigrationTest extends TestCase
{
    public function testTheMigrationCarriesExactlyThePackagesDdl(): void
    {
        $statements = Schema::mysql();

        $this->assertCount(1, $statements, 'one table: the reference counter');
        $this->assertSame($statements[0], Migration1793485162InterledgerReference::SQL);
    }

    public function testTheTimestampMatchesTheClassName(): void
    {
        $this->assertSame(1793485162, (new Migration1793485162InterledgerReference())->getCreationTimestamp());
    }
}

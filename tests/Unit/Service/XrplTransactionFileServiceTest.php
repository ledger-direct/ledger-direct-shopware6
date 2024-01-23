<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\XrplTransactionFileService;
use PHPUnit\Framework\TestCase;

class XrplTransactionFileServiceTest extends TestCase
{
    private XrplTransactionFileService $xrplTransactionFileService;
    private string $testDirectory;

    protected function setUp(): void
    {
        $this->testDirectory = sys_get_temp_dir();
        $this->xrplTransactionFileService = new XrplTransactionFileService($this->testDirectory);
    }

    public function testSaveAccountTxResult(): void
    {
        $mockAccountTransactionResult = json_encode(['foo' => 'bar']);

        $this->xrplTransactionFileService->saveAccountTxResult($mockAccountTransactionResult);

        $files = glob($this->testDirectory . DIRECTORY_SEPARATOR . XrplTransactionFileService::LEDGER_EXPORT_DIRECTORY . DIRECTORY_SEPARATOR . 'account_tx_*.json');
        $this->assertNotEmpty($files, 'No files found');

        $latestFile = array_reduce($files, function ($a, $b) {
            if (is_null($a)) {
                return $b;
            }
            return filemtime($a) > filemtime($b) ? $a : $b;
        });

        $this->assertFileExists($latestFile);
        $this->assertEquals($mockAccountTransactionResult, file_get_contents($latestFile));
    }
}
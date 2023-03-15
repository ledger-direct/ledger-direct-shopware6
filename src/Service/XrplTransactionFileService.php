<?php declare(strict_types=1);

namespace LedgerDirect\Service;

class XrplTransactionFileService
{
    const LEDGER_EXPORT_DIRECTORY  = 'ledger-direct';

    private string $filesDirectory;

    public function __construct(string $filesDirectory)
    {
        $this->filesDirectory = $filesDirectory;
    }

    public function saveAccountTxResult(string $accountTransactionResult): void
    {
        $path = $this->filesDirectory . DIRECTORY_SEPARATOR . self::LEDGER_EXPORT_DIRECTORY;
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $fileName = $path . DIRECTORY_SEPARATOR . 'account_tx_' . time() . '.json';
        file_put_contents($fileName, $accountTransactionResult);

    }
}
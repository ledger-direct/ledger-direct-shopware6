<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An XRPL or Xahau node for tests that have to know what a sync asked for:
 * it answers `account_tx` with the payments it was given for the account
 * named in the request, remembers every account asked, and can be down for
 * some accounts or for all, or answer without the list.
 *
 * StubHttpClient answers every account alike and never fails, which is all
 * the tests of the quoting and matching need.
 */
class LedgerNodeStub implements ClientInterface
{
    /** @var array<string, list<array<string, mixed>>> what the ledger holds, per receiving account */
    public array $onLedger = [];

    /** @var list<string> the accounts asked for, in order */
    public array $asked = [];

    /** No account gets an answer. */
    public bool $down = false;

    /** @var list<string> these accounts get no answer */
    public array $downFor = [];

    /** The node reports success and sends no list of transactions. */
    public bool $withoutList = false;

    /**
     * A payment as `account_tx` hands it out.
     *
     * @param string|array{currency: string, value: string, issuer: string} $delivered drops, or a token amount
     */
    public function receives(string $account, string $hash, int $destinationTag, string|array $delivered, int $ledgerIndex): void
    {
        $this->onLedger[$account][] = [
            'tx' => [
                'TransactionType' => 'Payment',
                'Account' => 'rSender',
                'Destination' => $account,
                'DestinationTag' => $destinationTag,
                'hash' => $hash,
                'ctid' => 'C' . str_pad(strtoupper(dechex($ledgerIndex)), 7, '0', \STR_PAD_LEFT) . '00010001',
                'ledger_index' => $ledgerIndex,
                'date' => 848499942,
            ],
            'meta' => ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => $delivered],
            'validated' => true,
        ];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $account = (string) ($body['params'][0]['account'] ?? '');
        $this->asked[] = $account;

        if ($this->down || \in_array($account, $this->downFor, true)) {
            throw new class('The node did not answer.') extends \RuntimeException implements ClientExceptionInterface {};
        }

        if ($this->withoutList) {
            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'result' => ['status' => 'success'],
            ]));
        }

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'result' => ['transactions' => $this->onLedger[$account] ?? [], 'marker' => null],
        ]));
    }
}

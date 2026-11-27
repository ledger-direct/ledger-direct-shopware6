<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A Horizon for tests that have to know what a Stellar sync asked for: it
 * remembers every address requested, answers an account without payments,
 * and can be down.
 */
class HorizonStub implements ClientInterface
{
    /** @var list<string> the addresses asked for, in order */
    public array $asked = [];

    public bool $down = false;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->asked[] = (string) $request->getUri();

        if ($this->down) {
            throw new class('Horizon did not answer.') extends \RuntimeException implements ClientExceptionInterface {};
        }

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['_embedded' => ['records' => []]]));
    }
}

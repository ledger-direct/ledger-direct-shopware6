<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentAttempt;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which transaction a request is about, for an order that has had more than
 * one attempt: the newest is the current one, as it is for Shopware; the
 * newest with a payment record is what a LedgerDirect answer is built from;
 * and a request naming another transaction than the current one comes from a
 * page that shows a replaced attempt.
 *
 * The records are real ones in the transactions' custom fields, read by the
 * real OrderTransactionService::readPaymentIntent().
 */
class PaymentAttemptTest extends TestCase
{
    public function testAnOrderWithOneAttemptIsAboutThatAttempt(): void
    {
        $only = $this->transaction('tx-1', quoted: true);

        $attempt = $this->attempt([$only]);

        $this->assertNotNull($attempt);
        $this->assertSame($only, $attempt->current);
        $this->assertSame($only, $attempt->quoted);
        $this->assertFalse($attempt->superseded);
        $this->assertTrue($attempt->currentIsQuoted());
        $this->assertTrue($attempt->isCurrent());
    }

    /**
     * The collection comes sorted by creation, oldest first; Shopware takes
     * the last one (OrderService, SetPaymentOrderRoute), and so does this.
     */
    public function testTheNewestTransactionIsTheCurrentOneNotTheFirst(): void
    {
        $first = $this->transaction('tx-1', quoted: true);
        $second = $this->transaction('tx-2', quoted: true);

        $attempt = $this->attempt([$first, $second]);

        $this->assertNotNull($attempt);
        $this->assertSame($second, $attempt->current);
        $this->assertSame($second, $attempt->quoted, 'the record of the current attempt, not of the one before');
        $this->assertTrue($attempt->isCurrent());
    }

    public function testAfterAChangeFromAnotherMethodTheNewAttemptIsOurs(): void
    {
        $foreign = $this->transaction('tx-1', quoted: false);
        $ours = $this->transaction('tx-2', quoted: true);

        $attempt = $this->attempt([$foreign, $ours]);

        $this->assertNotNull($attempt);
        $this->assertSame($ours, $attempt->current);
        $this->assertTrue($attempt->isCurrent());
    }

    /**
     * The order was quoted, and is not ours any more: the record that is left
     * is the cancelled attempt's, and nothing is current for a ledger payment.
     */
    public function testAfterAChangeToAnotherMethodTheOrderKeepsItsRecordButNoCurrentAttempt(): void
    {
        $ours = $this->transaction('tx-1', quoted: true);
        $alsoOurs = $this->transaction('tx-2', quoted: true);
        $foreign = $this->transaction('tx-3', quoted: false);

        $attempt = $this->attempt([$ours, $alsoOurs, $foreign]);

        $this->assertNotNull($attempt);
        $this->assertSame($foreign, $attempt->current);
        $this->assertSame($alsoOurs, $attempt->quoted, 'the newest record');
        $this->assertFalse($attempt->currentIsQuoted());
        $this->assertFalse($attempt->isCurrent());
        $this->assertFalse($attempt->superseded, 'no transaction was named');
    }

    public function testAnOrderNeverQuotedHasNoRecord(): void
    {
        $attempt = $this->attempt([$this->transaction('tx-1', quoted: false), $this->transaction('tx-2', quoted: false)]);

        $this->assertNotNull($attempt);
        $this->assertNull($attempt->quoted);
        $this->assertFalse($attempt->isCurrent());
    }

    /**
     * The page names the transaction it was rendered for. The same one as the
     * current: nothing has changed. Another one: that page shows an attempt
     * which has been replaced. None (a bookmark, a mail, an external front
     * end): the request is about the current attempt.
     */
    public function testARequestNamingAnotherTransactionThanTheCurrentOneIsSuperseded(): void
    {
        $transactions = [$this->transaction('tx-1', quoted: true), $this->transaction('tx-2', quoted: true)];

        $namesTheCurrent = $this->attempt($transactions, ['orderTransactionId' => 'tx-2']);
        $namesTheReplaced = $this->attempt($transactions, ['orderTransactionId' => 'tx-1']);
        $namesNone = $this->attempt($transactions);
        $namesNothingKnown = $this->attempt($transactions, ['orderTransactionId' => 'not-a-transaction-of-this-order']);
        $namesItInAForm = PaymentAttempt::of($this->order($transactions), new Request([], ['orderTransactionId' => 'tx-1']), $this->service());

        foreach ([$namesTheCurrent, $namesTheReplaced, $namesNone, $namesNothingKnown, $namesItInAForm] as $attempt) {
            $this->assertNotNull($attempt);
        }

        $this->assertFalse($namesTheCurrent->superseded);
        $this->assertTrue($namesTheCurrent->isCurrent());
        $this->assertTrue($namesTheReplaced->superseded);
        $this->assertFalse($namesTheReplaced->isCurrent());
        $this->assertTrue($namesTheReplaced->currentIsQuoted(), 'there is a current attempt to send the page to');
        $this->assertFalse($namesNone->superseded);
        $this->assertTrue($namesNothingKnown->superseded);
        $this->assertTrue($namesItInAForm->superseded, 'the page\'s forms post the id');
    }

    public function testAnOrderWithoutATransactionHasNoAttempt(): void
    {
        $this->assertNull($this->attempt([]));
        $this->assertNull(PaymentAttempt::of(new OrderEntity(), new Request(), $this->service()), 'transactions not loaded');
    }

    /**
     * @param list<OrderTransactionEntity> $transactions oldest first
     * @param array<string, string> $query
     */
    private function attempt(array $transactions, array $query = []): ?PaymentAttempt
    {
        return PaymentAttempt::of($this->order($transactions), new Request($query), $this->service());
    }

    /**
     * @param list<OrderTransactionEntity> $transactions
     */
    private function order(array $transactions): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setTransactions(new OrderTransactionCollection($transactions));

        return $order;
    }

    /** readPaymentIntent() reads the entity's custom fields and none of the service's collaborators. */
    private function service(): OrderTransactionService
    {
        return (new \ReflectionClass(OrderTransactionService::class))->newInstanceWithoutConstructor();
    }

    private function transaction(string $id, bool $quoted): OrderTransactionEntity
    {
        $transaction = new OrderTransactionEntity();
        $transaction->setId($id);
        $transaction->setUniqueIdentifier($id);

        if ($quoted) {
            $transaction->setCustomFields([OrderTransactionService::CUSTOM_FIELDS_KEY => PaymentIntent::quote(
                type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
                pairing: 'XRP/EUR', exchangeRate: 2.5, amountRequested: 40.0,
                destinationAccount: 'rMerchant', destinationTag: 114729, expiry: time() + 300,
            )->toArray()]);
        }

        return $transaction;
    }
}

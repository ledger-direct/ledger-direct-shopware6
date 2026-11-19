<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\ScheduledTask;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\ScheduledTask\SettleOpenTransactionsTask;
use Hardcastle\LedgerDirect\ScheduledTask\SettleOpenTransactionsTaskHandler;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * One node request per receiving account and network, every open order
 * matched against the table, the ones that settle moved to paid.
 */
class SettleOpenTransactionsTaskHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private OrderTransactionService $orderTransactionService;

    private OrderTransactionStateHandler $stateHandler;

    /** The configured receiving account; empty means none. */
    private string $configuredAccount = '';

    private string $configuredStellarAccount = '';

    private string $configuredXahauAccount = '';
    /** Interledger is in the registry too, and the task asks every chain for its account. */
    private string $configuredInterledgerWallet = '';

    protected function setUp(): void
    {
        $this->orderTransactionService = Mockery::mock(OrderTransactionService::class);
        $this->stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        // No cancelled Interledger order unless a test says so.
        $this->orderTransactionService->shouldReceive('findCancelledInterledgerTransactions')
            ->andReturn(new OrderTransactionCollection([]))->byDefault();
    }

    public function testTheTaskRunsEveryFiveMinutes(): void
    {
        $this->assertSame('ledger_direct.settle_open_transactions', SettleOpenTransactionsTask::getTaskName());
        $this->assertSame(300, SettleOpenTransactionsTask::getDefaultInterval());
    }

    public function testOneSyncPerAccountThenEveryOpenOrderIsMatched(): void
    {
        $first = $this->transaction('tx-1', 'open');
        $second = $this->transaction('tx-2', 'paid_partially');
        $onMainnet = $this->transaction('tx-3', 'open');

        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([$first, $second, $onMainnet]));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($first)->andReturn($this->quote('testnet'));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($second)->andReturn($this->quote('testnet'));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($onMainnet)->andReturn($this->quote('mainnet'));

        // Two orders on the testnet account, one on the mainnet account: two syncs, both unthrottled.
        $this->orderTransactionService->shouldReceive('syncLedger')->once()->with('XRPL', 'rMerchant', 'testnet', false);
        $this->orderTransactionService->shouldReceive('syncLedger')->once()->with('XRPL', 'rMerchant', 'mainnet', false);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->never();

        // The first settles, the second is topped up to paid, the third is still waiting.
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($first, Mockery::type(Context::class))
            ->andReturn($this->quote('testnet')->withFulfillment('HASH1', 40.0, 'CTID'));
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($second, Mockery::type(Context::class))
            ->andReturn($this->quote('testnet')->withFulfillment('HASH2', 40.0, 'CTID'));
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($onMainnet, Mockery::type(Context::class))
            ->andReturn(null);

        $this->stateHandler->shouldReceive('paid')->once()->with('tx-1', Mockery::type(Context::class));
        $this->stateHandler->shouldReceive('paid')->once()->with('tx-2', Mockery::type(Context::class));
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->with('tx-1', Mockery::type(Context::class))->andReturn($this->transaction('tx-1', 'paid'));
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->with('tx-2', Mockery::type(Context::class))->andReturn($this->transaction('tx-2', 'paid'));

        $this->handler()->run();
    }

    public function testAnOrderThatThrowsDoesNotStopTheOthers(): void
    {
        $broken = $this->transaction('tx-broken', 'open');
        $fine = $this->transaction('tx-fine', 'open');

        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([$broken, $fine]));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($broken)->andReturn($this->quote('testnet'));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($fine)->andReturn($this->quote('testnet'));
        $this->orderTransactionService->shouldReceive('syncLedger')->once();
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($broken, Mockery::any())
            ->andThrow(new \RuntimeException('node hiccup'));
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($fine, Mockery::any())
            ->andReturn($this->quote('testnet')->withFulfillment('HASH', 40.0, 'CTID'));
        $this->stateHandler->shouldReceive('paid')->once()->with('tx-fine', Mockery::any());
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->andReturn($this->transaction('tx-fine', 'paid'));

        $this->handler()->run();
    }

    public function testNothingOpenAndNoAccountConfiguredMeansNoNodeRequest(): void
    {
        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([]));
        $this->orderTransactionService->shouldReceive('syncLedger')->never();

        $this->handler()->run();
    }

    /**
     * A payment on an order the merchant cancelled, with nothing else open,
     * must still reach the transaction table: the configured account is
     * synced on every run, whether or not an order points at it.
     */
    public function testTheConfiguredAccountIsSyncedEvenWithNothingOpen(): void
    {
        $this->configuredAccount = 'rConfiguredAccount';
        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([]));
        $this->orderTransactionService->shouldReceive('syncLedger')
            ->once()->with('XRPL', 'rConfiguredAccount', 'testnet', false);

        $this->handler()->run();
    }

    /**
     * Two chains, two syncs: the configured Stellar account and an open Stellar
     * order's account are synced through the Stellar sync, keyed by chain.
     */
    public function testEveryEnabledChainIsSyncedWithItsOwnSync(): void
    {
        $this->configuredAccount = 'rConfiguredAccount';
        $this->configuredStellarAccount = 'GCONFIGURED';
        $stellarOrder = $this->transaction('tx-stellar', 'open');

        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([$stellarOrder]));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($stellarOrder)->andReturn($this->stellarQuote('GORDERACCOUNT'));

        $this->orderTransactionService->shouldReceive('syncLedger')->once()->with('XRPL', 'rConfiguredAccount', 'testnet', false);
        $this->orderTransactionService->shouldReceive('syncLedger')->once()->with('STELLAR', 'GCONFIGURED', 'testnet', false);
        $this->orderTransactionService->shouldReceive('syncLedger')->once()->with('STELLAR', 'GORDERACCOUNT', 'testnet', false);
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($stellarOrder, Mockery::any())->andReturn(null);

        $this->handler()->run();
    }

    /**
     * An `r…` address is valid on the XRP Ledger and on Xahau. Configured on
     * both, the account is synced on both: the chain is part of what makes a
     * sync target, not the address alone.
     */
    public function testTheSameAccountOnTheXrpLedgerAndXahauIsSyncedOnBoth(): void
    {
        $this->configuredAccount = 'rSharedAccount';
        $this->configuredXahauAccount = 'rSharedAccount';

        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([]));

        $this->orderTransactionService->shouldReceive('syncLedger')->once()->with('XRPL', 'rSharedAccount', 'testnet', false);
        $this->orderTransactionService->shouldReceive('syncLedger')->once()->with('XAHAU', 'rSharedAccount', 'testnet', false);

        $this->handler()->run();
    }

    /**
     * PS-11 over Interledger. The merchant cancelled, the customer paid anyway: on a
     * ledger the account's sync puts that payment in the transaction table. Here it sits
     * in the order's own incoming payment, so the task reads that one, and what arrived
     * goes on the order's record. The order stays cancelled.
     */
    public function testAPaymentIntoACancelledInterledgerOrderIsPutOnItsRecordAndNothingElseChanges(): void
    {
        $cancelled = $this->transaction('tx-cancelled', 'cancelled');

        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([]));
        $this->orderTransactionService->shouldReceive('findCancelledInterledgerTransactions')
            ->once()->andReturn(new OrderTransactionCollection([$cancelled]));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($cancelled)
            ->andReturn($this->interledgerQuote(time() + 120));
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->once()->with($cancelled, Mockery::type(Context::class))
            ->andReturn($this->interledgerQuote(time() + 120)->withFulfillment('3f2c0d8e', ['value' => '1.120060000', 'issuer' => 'https://merchant.example/pay/xrp', 'currency' => 'XRP']));

        $this->stateHandler->shouldNotReceive('paid');
        $this->stateHandler->shouldNotReceive('payPartially');
        $this->stateHandler->shouldNotReceive('reopen');
        $this->orderTransactionService->shouldNotReceive('syncLedger');

        $this->handler()->run();
    }

    /**
     * An incoming payment that lapsed takes nothing more. The order is read for an
     * hour after that, which covers the read that is the last word, and then left alone:
     * a cancelled order is not asked about at the wallet forever.
     */
    public function testACancelledInterledgerOrderIsNotReadOnceItsIncomingPaymentIsLongOver(): void
    {
        $stillReadable = $this->transaction('tx-recent', 'cancelled');
        $longOver = $this->transaction('tx-old', 'cancelled');

        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([]));
        $this->orderTransactionService->shouldReceive('findCancelledInterledgerTransactions')
            ->once()->andReturn(new OrderTransactionCollection([$stillReadable, $longOver]));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($stillReadable)
            ->andReturn($this->interledgerQuote(time() - 3000));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($longOver)
            ->andReturn($this->interledgerQuote(time() - 4000));

        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->once()->with($stillReadable, Mockery::any())->andReturn(null);
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->never()->with($longOver, Mockery::any());

        $this->handler()->run();
    }

    /** A wallet that cannot be read for one cancelled order does not stop the next. */
    public function testACancelledOrderThatCannotBeReadDoesNotStopTheOthers(): void
    {
        $unreadable = $this->transaction('tx-unreadable', 'cancelled');
        $fine = $this->transaction('tx-fine', 'cancelled');

        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([]));
        $this->orderTransactionService->shouldReceive('findCancelledInterledgerTransactions')
            ->once()->andReturn(new OrderTransactionCollection([$unreadable, $fine]));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->andReturn($this->interledgerQuote(time() + 120));
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->once()->with($unreadable, Mockery::any())
            ->andThrow(new \RuntimeException('wallet hiccup'));
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->once()->with($fine, Mockery::any())->andReturn(null);

        $this->handler()->run();
    }

    private function interledgerQuote(int $expiry): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'interledger-xrp-payment',
            chain: 'INTERLEDGER',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 0.8928,
            // Over Interledger every amount names its wallet address, as an issued one names its issuer.
            amountRequested: ['value' => '1.120060000', 'issuer' => 'https://merchant.example/pay/xrp', 'currency' => 'XRP'],
            destinationAccount: 'https://merchant.example/incoming-payments/3f2c0d8e',
            destinationTag: 123456,
            expiry: $expiry,
        );
    }

    private function stellarQuote(string $account): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'stellar-xlm-payment',
            chain: 'STELLAR',
            network: 'testnet',
            baseAsset: 'XLM',
            quoteCurrency: 'EUR',
            pairing: 'XLM/EUR',
            exchangeRate: 0.4,
            amountRequested: 250.0,
            destinationAccount: $account,
            destinationTag: 123456,
            expiry: time() + 300,
        );
    }

    private function handler(): SettleOpenTransactionsTaskHandler
    {
        return new SettleOpenTransactionsTaskHandler(
            Mockery::mock(EntityRepository::class),
            new NullLogger(),
            $this->orderTransactionService,
            new PaymentStateService($this->stateHandler, $this->orderTransactionService, new SettlementPolicy(), new NullLogger()),
            $this->configuration()
        );
    }

    private function configuration(): ShopwareConfigProvider
    {
        $configuration = Mockery::mock(ShopwareConfigProvider::class);
        $configuration->shouldReceive('getDestinationAccount')->with('XRPL')->andReturn($this->configuredAccount);
        $configuration->shouldReceive('getNetwork')->with('XRPL')->andReturn('testnet');
        $configuration->shouldReceive('getDestinationAccount')->with('STELLAR')->andReturn($this->configuredStellarAccount);
        $configuration->shouldReceive('getNetwork')->with('STELLAR')->andReturn('testnet');
        $configuration->shouldReceive('getDestinationAccount')->with('XAHAU')->andReturn($this->configuredXahauAccount);
        $configuration->shouldReceive('getNetwork')->with('XAHAU')->andReturn('testnet');
        $configuration->shouldReceive('getDestinationAccount')->with('INTERLEDGER')->andReturn($this->configuredInterledgerWallet);
        $configuration->shouldReceive('getNetwork')->with('INTERLEDGER')->andReturn('testnet');

        return $configuration;
    }

    private function transaction(string $id, string $state): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);

        $transaction = new OrderTransactionEntity();
        $transaction->setId($id);
        $transaction->setUniqueIdentifier($id);
        $transaction->setStateMachineState($stateEntity);

        return $transaction;
    }

    private function quote(string $network): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: $network,
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.5,
            amountRequested: 40.0,
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
            expiry: time() + 300,
        );
    }
}

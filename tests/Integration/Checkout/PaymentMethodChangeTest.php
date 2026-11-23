<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Checkout;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Exception\TransactionLifetimeException;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentAttempt;
use Hardcastle\LedgerDirect\Storefront\Controller\XrplPaymentController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\SetPaymentOrderRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use Shopware\Core\Test\Integration\Traits\OrderFixture;
use Shopware\Core\Test\TestDefaults;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * An order whose customer changed the payment method after ordering, in the
 * booted shop and on its database: what Shopware leaves behind, and what the
 * plugin's routes make of it.
 *
 * The first test lets Shopware do the change, through the route its
 * storefront and Store API use (SetPaymentOrderRoute): from a LedgerDirect
 * attempt to a method that is not ours. That runs offline, and it is the case
 * every shop has. A change to a LedgerDirect method cannot go through that
 * route here: it asks the ledger whether the receiving account can take the
 * asset, and paying would ask for a price. The second test therefore writes
 * the second transaction the way that route does (the first cancelled, a
 * new one in the initial state) and checks what does not need the network:
 * which transaction is the current one as the database returns them, and
 * what a page of the replaced attempt is told.
 */
class PaymentMethodChangeTest extends TestCase
{
    use IntegrationTestBehaviour;
    use OrderFixture;

    private const ACCOUNT = 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr';

    private Context $context;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
    }

    public function testAfterShopwareChangesToAnotherMethodTheOrderLeavesLedgerDirect(): void
    {
        [$orderId, $customerId, $code] = $this->givenOrderPaidWith(PaymentMethodInstaller::XRP_PAYMENT_ID, $this->quote('xrp-payment', 'XRP', 40.0, 110001));
        $salesChannelContext = $this->contextOf($customerId);
        $another = $this->aMethodTheCustomerMayChangeTo($salesChannelContext);

        $this->getContainer()->get(SetPaymentOrderRoute::class)->setPayment(
            new Request([], ['orderId' => $orderId, 'paymentMethodId' => $another]),
            $salesChannelContext
        );

        // What Shopware left: the attempt of ours cancelled, a new transaction of the other method, open and last.
        $order = $this->order($orderId);
        $this->assertSame(
            [[PaymentMethodInstaller::XRP_PAYMENT_ID, OrderTransactionStates::STATE_CANCELLED, true], [$another, OrderTransactionStates::STATE_OPEN, false]],
            $this->transactionsOf($order)
        );

        $attempt = PaymentAttempt::of($order, new Request(), $this->getContainer()->get(OrderTransactionService::class));
        $this->assertNotNull($attempt);
        $this->assertSame($another, $attempt->current->getPaymentMethodId());
        $this->assertSame(PaymentMethodInstaller::XRP_PAYMENT_ID, $attempt->quoted?->getPaymentMethodId());
        $this->assertFalse($attempt->isCurrent());

        // The status says leave, for the order page; there is no quote; the page leaves the same way.
        $route = $this->getContainer()->get(PaymentRoute::class);
        $status = $route->check($orderId, new Request(['deepLinkCode' => $code]), $salesChannelContext)->getObject()->getVars();

        $this->assertSame('XRP', $status['base_asset']);
        $this->assertStringEndsWith('/account/order/' . $code, $status['redirect']);

        try {
            $route->quote($orderId, new Request(['deepLinkCode' => $code]), $salesChannelContext);
            $this->fail('expected a 410');
        } catch (TransactionLifetimeException $exception) {
            $this->assertSame(410, $exception->getStatusCode());
        }

        $page = $this->getContainer()->get(XrplPaymentController::class)->payment($salesChannelContext, $orderId, new Request(['deepLinkCode' => $code]));
        $this->assertInstanceOf(RedirectResponse::class, $page);
        $this->assertStringEndsWith('/account/order/' . $code, $page->getTargetUrl());

        // And none of that touched a transaction.
        $this->assertSame(
            [[PaymentMethodInstaller::XRP_PAYMENT_ID, OrderTransactionStates::STATE_CANCELLED, true], [$another, OrderTransactionStates::STATE_OPEN, false]],
            $this->transactionsOf($this->order($orderId))
        );
    }

    public function testTheTransactionCreatedLastIsTheCurrentOne(): void
    {
        [$orderId, $customerId, $code] = $this->givenOrderPaidWith(PaymentMethodInstaller::XRP_PAYMENT_ID, $this->quote('xrp-payment', 'XRP', 40.0, 110001));
        $first = $this->order($orderId)->getTransactions()?->first();
        $this->assertNotNull($first);

        // As SetPaymentOrderRoute::setPaymentMethod() does it: cancel what there is, add a transaction in the initial state.
        usleep(5000);
        $this->getContainer()->get(OrderTransactionStateHandler::class)->cancel($first->getId(), $this->context);
        $secondId = Uuid::randomHex();
        $this->getContainer()->get('order.repository')->update([[
            'id' => $orderId,
            'transactions' => [$this->transaction($secondId, PaymentMethodInstaller::RLUSD_PAYMENT_ID, $this->quote(
                'rlusd-payment',
                'RLUSD',
                ['currency' => '524C555344000000000000000000000000000000', 'value' => '40.00', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'],
                220002
            ))],
        ]], $this->context);

        $order = $this->order($orderId);
        $service = $this->getContainer()->get(OrderTransactionService::class);
        $attempt = PaymentAttempt::of($order, new Request(), $service);

        $this->assertSame([$first->getId(), $secondId], array_values($order->getTransactions()?->getIds() ?? []), 'oldest first, as the service loads them');
        $this->assertSame($secondId, $attempt?->current->getId(), 'the new attempt, not the cancelled first one');
        $this->assertSame(220002, $service->readPaymentIntent($attempt->current)?->destinationTag);
        $this->assertTrue($attempt->isCurrent());

        // A page of the first attempt names it on its poll, and is sent to the order's payment page.
        $status = $this->getContainer()->get(PaymentRoute::class)
            ->check($orderId, new Request(['deepLinkCode' => $code, 'orderTransactionId' => $first->getId()]), $this->contextOf($customerId))
            ->getObject()->getVars();

        $this->assertSame('RLUSD', $status['base_asset']);
        $this->assertStringContainsString('/ledger-direct/payment/' . $orderId, $status['redirect']);
        $this->assertStringContainsString('deepLinkCode=' . $code, $status['redirect']);
        $this->assertStringNotContainsString('orderTransactionId', $status['redirect']);
        $this->assertStringNotContainsString('returnUrl', $status['redirect']);
    }

    /**
     * @return array{0: string, 1: string, 2: string} order id, customer id, deepLinkCode
     */
    private function givenOrderPaidWith(string $paymentMethodId, PaymentIntent $intent): array
    {
        $orderId = Uuid::randomHex();
        $customerId = Uuid::randomHex();

        $data = $this->getOrderData($orderId, $this->context, $customerId);
        $data[0]['transactions'] = [$this->transaction(Uuid::randomHex(), $paymentMethodId, $intent)];
        $this->getContainer()->get('order.repository')->create($data, $this->context);

        return [$orderId, $customerId, (string) $data[0]['deepLinkCode']];
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(string $id, string $paymentMethodId, PaymentIntent $intent): array
    {
        return [
            'id' => $id,
            'paymentMethodId' => $paymentMethodId,
            'stateId' => $this->getContainer()->get(InitialStateIdLoader::class)->get(OrderTransactionStates::STATE_MACHINE),
            'amount' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
            'customFields' => [OrderTransactionService::CUSTOM_FIELDS_KEY => $intent->toArray()],
        ];
    }

    /**
     * @param float|array{currency: string, value: string, issuer: string} $amount
     */
    private function quote(string $type, string $asset, float|array $amount, int $tag): PaymentIntent
    {
        return PaymentIntent::quote(
            type: $type, chain: 'XRPL', network: 'testnet', baseAsset: $asset, quoteCurrency: 'EUR',
            pairing: $asset . '/EUR', exchangeRate: 2.5, amountRequested: $amount,
            destinationAccount: self::ACCOUNT, destinationTag: $tag, expiry: time() + 300,
        );
    }

    private function contextOf(string $customerId): SalesChannelContext
    {
        return $this->getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL, [SalesChannelContextService::CUSTOMER_ID => $customerId]);
    }

    /**
     * The sales channel's own payment method, made changeable after the order
     * as Shopware's methods are in a shop ("allow change after checkout").
     */
    private function aMethodTheCustomerMayChangeTo(SalesChannelContext $salesChannelContext): string
    {
        $paymentMethodId = $salesChannelContext->getSalesChannel()->getPaymentMethodId();
        $this->getContainer()->get('payment_method.repository')->update([['id' => $paymentMethodId, 'active' => true, 'afterOrderEnabled' => true]], $this->context);

        return $paymentMethodId;
    }

    private function order(string $orderId): OrderEntity
    {
        $order = $this->getContainer()->get(OrderTransactionService::class)->getOrderWithTransactions($orderId, $this->context);
        $this->assertNotNull($order);

        return $order;
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}> per transaction, oldest first: payment method, state, whether it carries our record
     */
    private function transactionsOf(OrderEntity $order): array
    {
        $rows = [];

        foreach ($order->getTransactions() ?? [] as $transaction) {
            $rows[] = [
                $transaction->getPaymentMethodId(),
                (string) $transaction->getStateMachineState()?->getTechnicalName(),
                isset(($transaction->getCustomFields() ?? [])[OrderTransactionService::CUSTOM_FIELDS_KEY]),
            ];
        }

        return $rows;
    }
}

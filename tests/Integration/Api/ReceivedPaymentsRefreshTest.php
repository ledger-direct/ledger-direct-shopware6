<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Api;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Api\ReceivedPaymentsController;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\DbalXrplTransactionRepository;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use Shopware\Core\Test\Integration\Traits\OrderFixture;

/**
 * The refresh of the received payments in the booted shop and on its
 * database, as a user of the administration who may read orders and nothing
 * else.
 *
 * The route asks for `order:read`, and a refresh can settle a transaction
 * that is still open: Shopware then writes the transaction, its state
 * history and the payment record. With the user's own rights that write is
 * refused, and the refresh would fail for exactly the order it has news for.
 * The state is the ledger's finding, not the user's edit: it is written as
 * the system, and the state history names no user for it.
 *
 * The sync is held back by the throttle here, so the test asks no node: what
 * pays the order is already in the table, as after a sync a moment ago.
 */
class ReceivedPaymentsRefreshTest extends TestCase
{
    use IntegrationTestBehaviour;
    use OrderFixture;

    private const ACCOUNT = 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr';

    public function testTheShopKnowsTheRouteAndItsPrivilege(): void
    {
        $route = $this->getContainer()->get('router')->getRouteCollection()->get('api.action.ledger-direct.order.payments.refresh');

        $this->assertNotNull($route, 'the route is not registered');
        $this->assertSame('/api/_action/ledger-direct/order/{orderId}/payments/refresh', $route->getPath());
        $this->assertSame(['POST'], $route->getMethods());
        $this->assertSame(['order:read'], $route->getDefault('_acl'));
        $this->assertSame(['api'], $route->getDefault('_routeScope'));
    }

    public function testAnUnknownOrderIs404(): void
    {
        $response = $this->getContainer()->get(ReceivedPaymentsController::class)->refresh(Uuid::randomHex(), Context::createDefaultContext());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'order_not_found'], json_decode((string) $response->getContent(), true));
    }

    public function testAUserWhoMayOnlyReadOrdersGetsTheStateTheLedgerBringsAbout(): void
    {
        $tag = random_int(1_000_000, 2_000_000_000);
        $orderId = $this->givenOpenOrder($tag);
        $this->getContainer()->get(DbalXrplTransactionRepository::class)->saveTransactions([$this->payment($tag)]);
        $this->getContainer()->get(SyncThrottle::class)->markSynced('testnet', self::ACCOUNT);

        $reader = new AdminApiSource(Uuid::randomHex());
        $reader->setIsAdmin(false);
        $reader->setPermissions(['order:read']);
        $context = new Context($reader);
        $this->assertFalse($context->isAllowed('order_transaction:update'), 'the user of this test may change no transaction');

        $response = $this->getContainer()->get(ReceivedPaymentsController::class)->refresh($orderId, $context);
        $answer = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('throttled', $answer['sync'], 'no node was asked');
        $this->assertSame(OrderTransactionStates::STATE_PAID, $answer['transactions'][0]['state']);
        $this->assertSame(['HASH-' . $tag], array_column($answer['transactions'][0]['payments'], 'hash'));

        /** @var OrderTransactionService $service */
        $service = $this->getContainer()->get(OrderTransactionService::class);
        $transaction = $service->getOrderWithTransactions($orderId, Context::createDefaultContext())?->getTransactions()?->first();
        $this->assertNotNull($transaction);
        $this->assertSame(OrderTransactionStates::STATE_PAID, $transaction->getStateMachineState()?->getTechnicalName(), 'and the shop has it');
        $this->assertSame('HASH-' . $tag, ($transaction->getCustomFields() ?? [])[OrderTransactionService::CUSTOM_FIELDS_KEY]['hash'] ?? null);

        // Every column: `integration_id` came to this table after Shopware 6.6.5 and 6.7.0, which the plugin also runs on.
        $history = $this->getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT * FROM state_machine_history WHERE referenced_id = :id',
            ['id' => Uuid::fromHexToBytes($transaction->getId())]
        );
        $this->assertCount(1, $history);
        $this->assertNull($history[0]['user_id'], 'the payment set the state, not the user who looked');
        $this->assertNull($history[0]['integration_id'] ?? null, 'nor an integration');
    }

    private function givenOpenOrder(int $tag): string
    {
        $orderId = Uuid::randomHex();
        $context = Context::createDefaultContext();

        $data = $this->getOrderData($orderId, $context);
        $data[0]['transactions'] = [[
            'id' => Uuid::randomHex(),
            'paymentMethodId' => PaymentMethodInstaller::XRP_PAYMENT_ID,
            'stateId' => $this->getContainer()->get(InitialStateIdLoader::class)->get(OrderTransactionStates::STATE_MACHINE),
            'amount' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
            'customFields' => [OrderTransactionService::CUSTOM_FIELDS_KEY => PaymentIntent::quote(
                type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
                pairing: 'XRP/EUR', exchangeRate: 2.5, amountRequested: 4.0,
                destinationAccount: self::ACCOUNT, destinationTag: $tag, expiry: time() + 300,
            )->toArray()],
        ]];
        $this->getContainer()->get('order.repository')->create($data, $context);

        return $orderId;
    }

    /** The requested 4 XRP on the order's tag, as a sync stores it. */
    private function payment(int $tag): XrplTransaction
    {
        $hash = 'HASH-' . $tag;
        $ctid = 'C0000064' . str_pad(strtoupper(dechex($tag % 65536)), 4, '0', \STR_PAD_LEFT) . '0001';

        return new XrplTransaction(
            network: 'testnet', ledgerIndex: '100', hash: $hash, ctid: $ctid,
            account: 'rJdfC6X2L6tTURK7h214Q3MW3a4RrbzCa8', destination: self::ACCOUNT, destinationTag: $tag,
            date: 848499942,
            meta: ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => '4000000'],
            tx: ['TransactionType' => 'Payment', 'Account' => 'rJdfC6X2L6tTURK7h214Q3MW3a4RrbzCa8', 'Destination' => self::ACCOUNT, 'DestinationTag' => $tag, 'hash' => $hash],
        );
    }
}

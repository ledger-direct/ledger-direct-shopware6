const { ApiService } = Shopware.Classes;

/**
 * GET /api/_action/ledger-direct/order/{orderId}/payments
 *
 * Answers { transactions[] }: every order transaction with a LedgerDirect
 * payment record, each with the ledger payments stored for its account, tag
 * and network. An order without one answers an empty list; 404 for an order
 * that does not exist.
 *
 * POST /api/_action/ledger-direct/order/{orderId}/payments/refresh
 *
 * The same list, after the server has asked the ledger for the order's
 * receiving accounts. Its answer adds `sync`: "synced", "throttled" (asked
 * within the last seconds, the list is that recent) or "failed" (a node did
 * not answer, the list is what was stored). A transaction that was open and
 * is settled by what was found comes back with its new state.
 */
class LedgerDirectReceivedPaymentsService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'ledger-direct') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'ledgerDirectReceivedPaymentsService';
    }

    payments(orderId) {
        return this.httpClient
            .get(`/_action/ledger-direct/order/${orderId}/payments`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    refresh(orderId) {
        return this.httpClient
            .post(`/_action/ledger-direct/order/${orderId}/payments/refresh`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}

Shopware.Service().register('ledgerDirectReceivedPaymentsService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new LedgerDirectReceivedPaymentsService(initContainer.httpClient, container.loginService);
});

export default LedgerDirectReceivedPaymentsService;

const { ApiService } = Shopware.Classes;

/**
 * GET /api/_action/ledger-direct/order/{orderId}/payments
 *
 * Answers { transactions[] }: every order transaction with a LedgerDirect
 * payment record, each with the ledger payments stored for its account, tag
 * and network. An order without one answers an empty list; 404 for an order
 * that does not exist.
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
}

Shopware.Service().register('ledgerDirectReceivedPaymentsService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new LedgerDirectReceivedPaymentsService(initContainer.httpClient, container.loginService);
});

export default LedgerDirectReceivedPaymentsService;

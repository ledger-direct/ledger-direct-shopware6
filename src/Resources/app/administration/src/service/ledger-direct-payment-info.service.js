const { ApiService } = Shopware.Classes;

/**
 * GET /api/_action/ledger-direct/payment-info/{orderTransactionId}
 *
 * Answers { status, display } for one of our order transactions; 404 when the
 * transaction is not ours or has no payment record, 422 when the record is
 * unreadable. Callers decide what to show for each.
 */
class LedgerDirectPaymentInfoService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'ledger-direct') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'ledgerDirectPaymentInfoService';
    }

    info(orderTransactionId) {
        return this.httpClient
            .get(`/_action/ledger-direct/payment-info/${orderTransactionId}`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}

Shopware.Service().register('ledgerDirectPaymentInfoService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new LedgerDirectPaymentInfoService(initContainer.httpClient, container.loginService);
});

export default LedgerDirectPaymentInfoService;

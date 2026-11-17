const { ApiService } = Shopware.Classes;

/**
 * GET /api/_action/ledger-direct/receiving-account/{chain}
 *
 * Answers { chain, nativeAsset, network, account, findings[] } for the saved
 * receiving account of one chain: what stands in a customer's way (no trust
 * line, not activated, deposit authorization). account is null when the chain
 * is off or has no account.
 */
class LedgerDirectReceivingAccountService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'ledger-direct') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'ledgerDirectReceivingAccountService';
    }

    check(chain) {
        return this.httpClient
            .get(`/_action/ledger-direct/receiving-account/${chain}`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}

Shopware.Service().register('ledgerDirectReceivingAccountService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new LedgerDirectReceivingAccountService(initContainer.httpClient, container.loginService);
});

export default LedgerDirectReceivingAccountService;

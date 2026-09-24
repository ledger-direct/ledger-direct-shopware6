/**
 * Browser wallets over XRPL Connect (XRPL Commons, MIT).
 *
 * Loaded only when the customer opens the wallet list: the library and its
 * xrpl peer are a megabyte the page must not carry for everyone who pays by
 * QR code. Adapters that need no merchant identifier are always offered but
 * shown only when detected; Xaman and WalletConnect only when the merchant
 * configured their public identifier.
 *
 * The transaction is built from server-rendered values alone: the receiving
 * account, the destination tag, the amount in drops for XRP (converted by the
 * server) or the currency hex code, issuer and value for a token. Nothing is
 * typed in, nothing is converted in the browser. A submitted hash is a hint,
 * not proof: the page asks the server right away, and paid is only what the
 * server finds on the ledger.
 *
 * No sentence a customer reads is in this file: every message is a
 * server-rendered [data-ld-wallet-text] element this module shows.
 */

const AVAILABILITY_TIMEOUT_MS = 4000;

export function startWallets(root) {
    const section = root.querySelector('[data-ld-wallet-section]');
    if (!section) {
        return;
    }

    const network = root.getAttribute('data-ld-network') || 'mainnet';
    const xamanApiKey = root.getAttribute('data-ld-xaman-key') || '';
    const walletConnectProjectId = root.getAttribute('data-ld-wc-project') || '';

    const toggle = section.querySelector('[data-ld-wallet-toggle]');
    const list = section.querySelector('[data-ld-wallets]');
    const listHost = section.querySelector('[data-ld-wallet-list]');
    const appButton = section.querySelector('[data-ld-wallet-app]');

    let library = null;
    let manager = null;
    let amountStale = false;

    root.addEventListener('ld:amount', () => {
        // The XRP amount in drops was rendered by the server for the amount
        // shown at load time; after a partial payment the shortfall is due and
        // the server has to render the drops for it — the page reloads before
        // a wallet payment rather than converting in the browser.
        amountStale = true;
    });

    /* ---- messages ---- */

    function text(key) {
        const el = section.querySelector('[data-ld-wallet-text="' + key + '"]');
        return el ? el.textContent.trim() : '';
    }

    function say(key, replacements = {}) {
        const out = section.querySelector('[data-ld-wallet-message]');
        if (!out) {
            return;
        }
        let message = text(key);
        Object.keys(replacements).forEach((name) => {
            message = message.split('%' + name + '%').join(replacements[name]);
        });
        out.textContent = message;
        out.hidden = message === '';
    }

    function showStatus(key) {
        section.querySelectorAll('[data-ld-wallet-status]').forEach((el) => {
            el.hidden = el.getAttribute('data-ld-wallet-status') !== key;
        });
    }

    /* ---- library ---- */

    async function load() {
        if (!library) {
            library = await import('xrpl-connect');
        }
        return library;
    }

    function buildAdapters(lib) {
        const { Adapters } = lib;
        const adapters = [
            new Adapters.Crossmark(),
            new Adapters.GemWallet(),
            new Adapters.MetaMaskSnap(),
            new Adapters.Ledger(),
            new Adapters.Otsu(),
            new Adapters.Xyra(),
        ];
        if (xamanApiKey) {
            adapters.push(new Adapters.Xaman({ apiKey: xamanApiKey }));
        }
        if (walletConnectProjectId) {
            adapters.push(new Adapters.WalletConnect({ projectId: walletConnectProjectId }));
        }
        return adapters;
    }

    async function ensureManager() {
        if (manager) {
            return manager;
        }
        const lib = await load();
        manager = new lib.WalletManager({
            adapters: buildAdapters(lib),
            network,
            autoConnect: false,
        });
        return manager;
    }

    /* ---- transaction ---- */

    function transactionFor(address) {
        const account = root.querySelector('[data-ld-account]');
        const tag = root.querySelector('[data-ld-tag]');
        const drops = root.getAttribute('data-ld-amount-drops');
        const currency = root.getAttribute('data-ld-currency');
        const issuer = root.getAttribute('data-ld-issuer');
        const amountShown = root.querySelector('[data-ld-amount]');

        const transaction = {
            TransactionType: 'Payment',
            Account: address,
            Destination: account ? (account.getAttribute('data-value') || account.textContent).trim() : '',
            DestinationTag: tag ? Number((tag.getAttribute('data-value') || tag.textContent).trim()) : undefined,
        };

        if (currency && issuer) {
            transaction.Amount = {
                currency,
                issuer,
                value: amountShown ? amountShown.textContent.trim() : '',
            };
        } else {
            transaction.Amount = drops || '';
        }

        return transaction;
    }

    /* ---- payment ---- */

    async function pay(walletId) {
        if (amountStale) {
            window.location.reload();
            return;
        }
        say('confirm');
        try {
            const m = await ensureManager();
            const account = await m.connect(walletId, { network });
            const transaction = transactionFor(account.address);
            await m.signAndSubmit(transaction);
            say('submitted');
            root.dispatchEvent(new CustomEvent('ld:check'));
        } catch (error) {
            report(error);
        }
    }

    function report(error) {
        const code = error && error.code ? String(error.code) : '';
        const category = error && error.category ? String(error.category) : '';

        if (code.indexOf('NETWORK_MISMATCH') !== -1 || code.indexOf('NETWORK_NOT_SUPPORTED') !== -1) {
            say('error-mismatch', { network: text('network-' + network) || network });
            return;
        }
        if (category === 'USER_ACTION') {
            say('');
            return;
        }
        if (category === 'WALLET_UNAVAILABLE') {
            say('error-unavailable');
            return;
        }
        if (category === 'NETWORK') {
            say('error-network');
            return;
        }
        say('error-other');
        if (window.console && console.error) {
            console.error('LedgerDirect wallet payment failed', error);
        }
    }

    /* ---- the list ---- */

    function renderWallet(adapter) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ld-wallet';
        button.setAttribute('data-wallet', adapter.id);

        const dot = document.createElement('span');
        dot.className = 'ld-wallet-dot';
        dot.setAttribute('aria-hidden', 'true');
        dot.textContent = (adapter.name || adapter.id).charAt(0).toUpperCase();

        const name = document.createTextNode(adapter.name || adapter.id);

        const found = document.createElement('small');
        found.textContent = text('found');

        button.append(dot, name, found);
        button.addEventListener('click', () => pay(adapter.id));
        return button;
    }

    async function open() {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        list.hidden = expanded;
        if (expanded || listHost.childElementCount > 0) {
            return;
        }
        showStatus('loading');
        try {
            const m = await ensureManager();
            const available = await withTimeout(m.getAvailableWallets(), AVAILABILITY_TIMEOUT_MS, []);
            available.forEach((adapter) => listHost.appendChild(renderWallet(adapter)));
            showStatus(available.length > 0 ? 'hint' : 'none');
        } catch (error) {
            showStatus('none');
            report(error);
        }
    }

    function withTimeout(promise, ms, fallback) {
        return new Promise((resolve) => {
            const timer = setTimeout(() => resolve(fallback), ms);
            promise.then((value) => { clearTimeout(timer); resolve(value); }, () => { clearTimeout(timer); resolve(fallback); });
        });
    }

    if (toggle && list && listHost) {
        toggle.addEventListener('click', open);
    }
    if (appButton) {
        appButton.addEventListener('click', () => pay(appButton.getAttribute('data-ld-wallet-id') || 'xaman'));
    }
}

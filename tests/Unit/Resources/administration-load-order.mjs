/**
 * Runs the administration's order components against answers that arrive in
 * the wrong order. A component that loads again when its prop changes has two
 * requests under way for a moment, and the first can answer last: its answer
 * is for the order shown before and must change nothing. The "Refresh" button
 * of the received payments is a request of the same kind, and held to the
 * same rule.
 *
 * This is the components' own code, not a copy: each definition is taken from
 * its index.js as the source registers it, and created(), the watcher and
 * load() run on a plain object that holds what they touch (the data, the prop,
 * the service). No Vue, no browser, no package; plain Node.
 *
 *   node administration-load-order.mjs      exit 0 when every case holds
 *
 * AdministrationLoadOrderTest runs it with the PHPUnit suite.
 */
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const SOURCES = join(dirname(fileURLToPath(import.meta.url)), '../../../src/Resources/app/administration/src');
const COMPONENTS = join(SOURCES, 'component');

/**
 * The components that load on a watched prop, and how to drive them. One that
 * gets a watcher and is missing here fails the run: the rule is for all of them.
 */
const WATCHED = {
    'ledger-direct-received-payments': { prop: 'orderId', service: 'ledgerDirectReceivedPaymentsService', method: 'payments' },
    'ledger-direct-payment-card': { prop: 'orderTransactionId', service: 'ledgerDirectPaymentInfoService', method: 'info' },
};

/**
 * The definition a component's source hands to Shopware.Component.register().
 * Template and stylesheet are imports only a bundler resolves, and running the
 * methods needs neither.
 */
function definitionOf(name) {
    const source = readFileSync(join(COMPONENTS, name, 'index.js'), 'utf8')
        .replace(/^import template from '[^']+';$/m, "const template = '';")
        .replace(/^import '[^']+';$/gm, '');
    let definition = null;
    const shopware = {
        Component: {
            register(registered, options) {
                if (registered === name) {
                    definition = options;
                }
            },
        },
        Filter: { getByName: () => null },
    };

    // eslint-disable-next-line no-new-func
    new Function('Shopware', source)(shopware);
    assert.ok(definition, `${name}: the source registered no component of that name`);

    return definition;
}

/** What the plugin adds to Shopware's Details tab of the order, as its source hands it to Shopware.Component.override(). */
function detailsOverride() {
    const source = readFileSync(join(SOURCES, 'view/sw-order-detail-details/index.js'), 'utf8')
        .replace(/^import template from '[^']+';$/m, "const template = '';");
    let definition = null;

    // eslint-disable-next-line no-new-func
    new Function('Shopware', source)({ Component: { override(name, options) { definition = options; } } });
    assert.ok(definition, 'the source overrides no component');

    return definition;
}

function deferred() {
    const answer = {};
    answer.promise = new Promise((resolve, reject) => {
        answer.resolve = resolve;
        answer.reject = reject;
    });

    return answer;
}

/** Lets everything that waits on an answer run before the next step. */
const settled = () => new Promise((resolve) => { setImmediate(resolve); });

const httpError = (status) => ({ response: { status } });

/**
 * The component as it stands after created(), with its first request under way.
 * answers[id] settles the request for that id, whenever the case wants it to.
 */
function start(name, first) {
    const { prop, service, method } = WATCHED[name];
    const definition = definitionOf(name);
    const answers = {};
    const refreshes = {};
    const emitted = [];
    const instance = Object.assign(Object.create(definition.methods), definition.data(), {
        [prop]: first,
        [service]: {
            [method](id) {
                answers[id] = deferred();

                return answers[id].promise;
            },
            /** The button of the received payments; the payment card has none and never calls it. */
            refresh(id) {
                refreshes[id] = deferred();

                return refreshes[id].promise;
            },
        },
        $emit(event) {
            emitted.push(event);
        },
    });
    definition.created.call(instance);

    return {
        instance,
        answers,
        /** refreshes[id] settles the refresh asked for that id. */
        refreshes,
        emitted,
        /** The administration moves on: the prop changes and the watcher loads again. */
        moveOn(next) {
            instance[prop] = next;
            definition.watch[prop].call(instance);
        },
    };
}

const PAYMENTS = 'ledger-direct-received-payments';
const CARD = 'ledger-direct-payment-card';

const rowsOf = (order) => ({ transactions: [{ orderTransactionId: `transaction-of-${order}` }] });
/** What the refresh route answers: the list, and how the sync went. */
const refreshed = (order, sync) => ({
    transactions: [{ orderTransactionId: `transaction-of-${order}` }, { orderTransactionId: `found-on-the-ledger-for-${order}` }],
    sync,
});

/** The card of order A with its list loaded, as it stands when someone presses "Refresh". */
async function loaded(order = 'A') {
    const card = start(PAYMENTS, order);
    card.answers[order].resolve(rowsOf(order));
    await settled();

    return card;
}
const infoOf = (transaction) => ({ status: { state: 'settled' }, display: { hash: `hash-of-${transaction}` } });

const cases = {
    /* One request, nothing overtaking it: exactly what the components did before. */

    [`${PAYMENTS}: one answer fills the card`]: async () => {
        const { instance, answers } = start(PAYMENTS, 'A');
        assert.equal(instance.isLoading, true);

        answers.A.resolve(rowsOf('A'));
        await settled();

        assert.deepEqual(instance.transactions, rowsOf('A').transactions);
        assert.equal(instance.isLoading, false);
        assert.equal(instance.failed, false);
    },

    [`${PAYMENTS}: an order that is gone leaves nothing to show, any other failure is said`]: async () => {
        const gone = start(PAYMENTS, 'A');
        gone.answers.A.reject(httpError(404));
        await settled();
        assert.deepEqual(gone.instance.transactions, []);
        assert.equal(gone.instance.failed, false);
        assert.equal(gone.instance.isLoading, false);

        const broken = start(PAYMENTS, 'A');
        broken.answers.A.reject(httpError(500));
        await settled();
        assert.deepEqual(broken.instance.transactions, []);
        assert.equal(broken.instance.failed, true);
        assert.equal(broken.instance.isLoading, false);
    },

    [`${CARD}: one answer fills the card`]: async () => {
        const { instance, answers } = start(CARD, 'A');
        assert.equal(instance.isLoading, true);

        answers.A.resolve(infoOf('A'));
        await settled();

        assert.deepEqual(instance.info, infoOf('A'));
        assert.equal(instance.isLoading, false);
        assert.equal(instance.problem, null);
        assert.equal(instance.hidden, false);
    },

    [`${CARD}: 404 hides the card, 422 and any other failure name their problem`]: async () => {
        const expected = { 404: [true, null], 422: [false, 'unreadable'], 500: [false, 'unavailable'] };

        for (const [status, [hidden, problem]] of Object.entries(expected)) {
            const { instance, answers } = start(CARD, 'A');
            answers.A.reject(httpError(Number(status)));
            await settled();

            assert.equal(instance.info, null, `${status}`);
            assert.equal(instance.hidden, hidden, `${status}: hidden`);
            assert.equal(instance.problem, problem, `${status}: problem`);
            assert.equal(instance.isLoading, false, `${status}`);
        }
    },

    /* Two requests, the first answering last. */

    [`${PAYMENTS}: a late answer for the order before does not replace the rows`]: async () => {
        const { instance, answers, moveOn } = start(PAYMENTS, 'A');
        moveOn('B');

        answers.B.resolve(rowsOf('B'));
        await settled();
        answers.A.resolve(rowsOf('A'));
        await settled();

        assert.deepEqual(instance.transactions, rowsOf('B').transactions);
        assert.equal(instance.isLoading, false);
        assert.equal(instance.failed, false);
    },

    [`${PAYMENTS}: a late failure for the order before does not clear the rows`]: async () => {
        const { instance, answers, moveOn } = start(PAYMENTS, 'A');
        moveOn('B');

        answers.B.resolve(rowsOf('B'));
        await settled();
        answers.A.reject(httpError(500));
        await settled();

        assert.deepEqual(instance.transactions, rowsOf('B').transactions);
        assert.equal(instance.failed, false);
        assert.equal(instance.isLoading, false);
    },

    [`${PAYMENTS}: the answer for the order before does not end the loading of this one`]: async () => {
        const { instance, answers, moveOn } = start(PAYMENTS, 'A');
        moveOn('B');

        answers.A.resolve(rowsOf('A'));
        await settled();

        assert.equal(instance.isLoading, true, 'B is still under way');
        assert.deepEqual(instance.transactions, []);

        answers.B.resolve(rowsOf('B'));
        await settled();

        assert.deepEqual(instance.transactions, rowsOf('B').transactions);
        assert.equal(instance.isLoading, false);
    },

    [`${PAYMENTS}: a late answer for the order before does not undo this one's failure`]: async () => {
        const { instance, answers, moveOn } = start(PAYMENTS, 'A');
        moveOn('B');

        answers.B.reject(httpError(500));
        await settled();
        answers.A.resolve(rowsOf('A'));
        await settled();

        assert.deepEqual(instance.transactions, []);
        assert.equal(instance.failed, true);
    },

    [`${CARD}: a late answer for the transaction before does not replace the payment`]: async () => {
        const { instance, answers, moveOn } = start(CARD, 'A');
        moveOn('B');

        answers.B.resolve(infoOf('B'));
        await settled();
        answers.A.resolve(infoOf('A'));
        await settled();

        assert.deepEqual(instance.info, infoOf('B'));
        assert.equal(instance.isLoading, false);
    },

    [`${CARD}: a late failure for the transaction before neither hides the card nor names a problem`]: async () => {
        for (const status of [404, 422, 500]) {
            const { instance, answers, moveOn } = start(CARD, 'A');
            moveOn('B');

            answers.B.resolve(infoOf('B'));
            await settled();
            answers.A.reject(httpError(status));
            await settled();

            assert.deepEqual(instance.info, infoOf('B'), `${status}`);
            assert.equal(instance.hidden, false, `${status}: hidden`);
            assert.equal(instance.problem, null, `${status}: problem`);
            assert.equal(instance.isLoading, false, `${status}`);
        }
    },

    [`${CARD}: the answer for the transaction before does not end the loading of this one`]: async () => {
        const { instance, answers, moveOn } = start(CARD, 'A');
        moveOn('B');

        answers.A.resolve(infoOf('A'));
        await settled();

        assert.equal(instance.isLoading, true, 'B is still under way');
        assert.equal(instance.info, null);

        answers.B.resolve(infoOf('B'));
        await settled();

        assert.deepEqual(instance.info, infoOf('B'));
        assert.equal(instance.isLoading, false);
    },

    [`${CARD}: a late answer for the transaction before does not bring a hidden card back`]: async () => {
        const { instance, answers, moveOn } = start(CARD, 'A');
        moveOn('B');

        answers.B.reject(httpError(404));
        await settled();
        answers.A.resolve(infoOf('A'));
        await settled();

        assert.equal(instance.hidden, true);
        assert.equal(instance.info, null);
    },

    /* The "Refresh" button of the received payments. */

    [`${PAYMENTS}: a refresh shows the list it is answered and is busy until then`]: async () => {
        const { instance, refreshes } = await loaded();
        assert.equal(instance.isLoading, false);

        instance.refresh();
        assert.equal(instance.isLoading, true, 'the button is disabled while the request runs');

        refreshes.A.resolve(refreshed('A', 'synced'));
        await settled();

        assert.deepEqual(instance.transactions, refreshed('A', 'synced').transactions);
        assert.equal(instance.isLoading, false);
        assert.equal(instance.ledgerUnreachable, false);
    },

    [`${PAYMENTS}: a node that did not answer is said, a throttled sync is not`]: async () => {
        const failed = await loaded();
        failed.instance.refresh();
        failed.refreshes.A.resolve(refreshed('A', 'failed'));
        await settled();
        assert.equal(failed.instance.ledgerUnreachable, true);
        assert.deepEqual(failed.instance.transactions, refreshed('A', 'failed').transactions, 'the stored rows are shown all the same');
        assert.equal(failed.instance.failed, false, 'the list itself was loaded');

        const throttled = await loaded();
        throttled.instance.refresh();
        throttled.refreshes.A.resolve(refreshed('A', 'throttled'));
        await settled();
        assert.equal(throttled.instance.ledgerUnreachable, false);
        assert.deepEqual(throttled.instance.transactions, refreshed('A', 'throttled').transactions);
    },

    [`${PAYMENTS}: the notice stays through a throttled refresh and goes with one that reached the ledger`]: async () => {
        const { instance, refreshes } = await loaded();
        instance.refresh();
        refreshes.A.resolve(refreshed('A', 'failed'));
        await settled();

        // The click right after a failed one: the server asks no node, so nothing is known that takes the notice back.
        instance.refresh();
        refreshes.A.resolve(refreshed('A', 'throttled'));
        await settled();
        assert.equal(instance.ledgerUnreachable, true);

        instance.refresh();
        refreshes.A.resolve(refreshed('A', 'synced'));
        await settled();
        assert.equal(instance.ledgerUnreachable, false);
    },

    [`${PAYMENTS}: every refresh that is shown tells the page, loading the list does not`]: async () => {
        // Whatever the sync did: the transaction can have been settled since the page was opened, by the
        // scheduled task or the customer's payment page, and then only the list knows.
        for (const sync of ['synced', 'throttled', 'failed']) {
            const { instance, refreshes, emitted } = await loaded();
            assert.deepEqual(emitted, [], 'the first load is the page\'s own; nothing on it is older');

            instance.refresh();
            assert.deepEqual(emitted, [], `${sync}: not before the answer`);
            refreshes.A.resolve(refreshed('A', sync));
            await settled();

            assert.deepEqual(emitted, ['refreshed'], sync);
        }
    },

    [`${PAYMENTS}: a refresh that fails is said like a list that fails to load`]: async () => {
        const { instance, refreshes, emitted } = await loaded();
        instance.refresh();
        refreshes.A.resolve(refreshed('A', 'failed'));
        await settled();

        instance.refresh();
        refreshes.A.reject(httpError(500));
        await settled();

        assert.deepEqual(instance.transactions, []);
        assert.equal(instance.failed, true);
        assert.equal(instance.ledgerUnreachable, false, 'one problem is said, not two');
        assert.equal(instance.isLoading, false, 'and the button can be pressed again');
        assert.deepEqual(emitted, ['refreshed'], 'the first refresh was shown, the failed one shows nothing new');
    },

    [`${PAYMENTS}: a late answer of a refresh for the order before changes nothing`]: async () => {
        const { instance, answers, refreshes, emitted, moveOn } = await loaded();
        instance.refresh();
        moveOn('B');

        answers.B.resolve(rowsOf('B'));
        await settled();
        refreshes.A.resolve(refreshed('A', 'failed'));
        await settled();

        assert.deepEqual(instance.transactions, rowsOf('B').transactions, 'the rows of A are not written into B');
        assert.equal(instance.ledgerUnreachable, false, 'nor is what the refresh found out about A\'s ledger');
        assert.deepEqual(emitted, [], 'and B\'s payment card is not asked to load for a refresh of A');
        assert.equal(instance.isLoading, false);
        assert.equal(instance.failed, false);
    },

    [`${PAYMENTS}: a late failure of a refresh for the order before does not clear the rows`]: async () => {
        const { instance, answers, refreshes, moveOn } = await loaded();
        instance.refresh();
        moveOn('B');

        answers.B.resolve(rowsOf('B'));
        await settled();
        refreshes.A.reject(httpError(500));
        await settled();

        assert.deepEqual(instance.transactions, rowsOf('B').transactions);
        assert.equal(instance.failed, false);
    },

    [`${PAYMENTS}: the answer of a refresh for the order before does not end the loading of this one`]: async () => {
        const { instance, answers, refreshes, moveOn } = await loaded();
        instance.refresh();
        moveOn('B');

        refreshes.A.resolve(refreshed('A', 'synced'));
        await settled();

        assert.equal(instance.isLoading, true, 'B is still under way');
        assert.deepEqual(instance.transactions, rowsOf('A').transactions, 'what was shown, not what the refresh answered');

        answers.B.resolve(rowsOf('B'));
        await settled();

        assert.deepEqual(instance.transactions, rowsOf('B').transactions);
        assert.equal(instance.isLoading, false);
    },

    [`${PAYMENTS}: a refresh that never answers does not keep the next order's button disabled`]: async () => {
        const { instance, answers, moveOn } = await loaded();
        instance.refresh();
        moveOn('B');

        answers.B.resolve(rowsOf('B'));
        await settled();

        assert.equal(instance.isLoading, false, 'one busy flag for both requests, ended by the newest');
    },

    [`${PAYMENTS}: the notice about the ledger does not follow to the next order`]: async () => {
        const { instance, refreshes, moveOn } = await loaded();
        instance.refresh();
        refreshes.A.resolve(refreshed('A', 'failed'));
        await settled();
        assert.equal(instance.ledgerUnreachable, true);

        moveOn('B');

        assert.equal(instance.ledgerUnreachable, false);
    },

    /* The page: after a refresh the payment card above loads again. */

    'the payment card loads again after a refresh, and a page without one does nothing': async () => {
        const { methods } = detailsOverride();
        let loads = 0;

        methods.onLedgerDirectPaymentsRefreshed.call({ $refs: { ledgerDirectPaymentCard: { load() { loads += 1; } } } });
        assert.equal(loads, 1);

        // The transaction Shopware picked is another payment method's: the card is not rendered.
        methods.onLedgerDirectPaymentsRefreshed.call({ $refs: {} });
        assert.equal(loads, 1);
    },

    /* The rule is for every component, also one that is added later. */

    'every component that loads on a watched prop is checked here': async () => {
        const names = readdirSync(COMPONENTS, { withFileTypes: true }).filter((entry) => entry.isDirectory()).map((entry) => entry.name);
        assert.ok(names.length > 0, 'no component found');

        for (const name of names) {
            const watched = Object.keys(definitionOf(name).watch ?? {});

            if (name in WATCHED) {
                assert.deepEqual(watched, [WATCHED[name].prop], `${name}: another watcher than the one checked here`);
            } else {
                assert.deepEqual(watched, [], `${name} watches ${watched.join(', ')} and is not checked here`);
            }
        }
    },
};

let failed = 0;

for (const [name, run] of Object.entries(cases)) {
    try {
        // eslint-disable-next-line no-await-in-loop
        await run();
        console.log(`ok      ${name}`);
    } catch (error) {
        failed += 1;
        console.log(`FAILED  ${name}\n        ${String(error.message).split('\n').join('\n        ')}`);
    }
}

console.log(`${Object.keys(cases).length - failed} of ${Object.keys(cases).length} cases hold`);
process.exit(failed === 0 ? 0 : 1);

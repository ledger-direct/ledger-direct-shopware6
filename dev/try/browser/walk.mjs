// The way a prospect walks the try shop up to LedgerDirect's payment page: home page, add a
// miniature to the cart, guest checkout, a LedgerDirect payment method, place order, payment page.
// Takes a puppeteer page and imports no browser itself, so every caller brings its own: visitor.mjs
// (screenshots per step, the try.yml check) and the end-to-end harness (`ld-e2e record --start shop`,
// which films the walk). The selectors live here, next to the shop they belong to, and try.yml
// exercises them on every pull request.
//
//   import { walkToPaymentPage } from './walk.mjs';
//   const view = await walkToPaymentPage(page, { asset: 'rlusd', base: 'http://localhost:8091' });
//   // view: { url, state, amount, asset } of the payment page
import { readFileSync } from 'node:fs';

// The article to buy: the first miniature of the catalogue, found on the home page by its name —
// never "the first product", which changes with the catalogue.
export const PRODUCT = JSON.parse(readFileSync(new URL('../catalog/catalog.json', import.meta.url))).products[0]['en-GB'].name;
// The plugin's payment method ids (PaymentMethodInstaller).
export const METHODS = { xrp: '7ca60321a9d2dac0fe3622a5110f55bb', rlusd: '7ca60321a9d2dac0fe3622a5110f55bd', usdc: '7ca60321a9d2dac0fe3622a5110f55be', xah: 'd9e4a5f06e6c4a36dfa37a410d88de42' };

/**
 * Walks from the home page to the payment page and returns what the page shows.
 *
 * - asset: xrp, rlusd, usdc or xah (any case).
 * - base: the shop's URL; default the origin of the page's current URL. A page that is already on
 *   the home page is not loaded again, so a recording that starts there shows no reload.
 * - step(name, fn): runs each step; default just runs it. visitor.mjs times it and takes a screenshot.
 * - log(line): what the walk notices on the way (the payment methods on offer).
 */
export async function walkToPaymentPage(page, { asset = 'xrp', base, step = (name, fn) => fn(), log = () => {} } = {}) {
  const ASSET = asset.toLowerCase();
  if (!Object.hasOwn(METHODS, ASSET)) throw new Error(`unknown asset ${asset}: xrp, rlusd, usdc or xah`);
  const origin = new URL(page.url()).origin;
  if (!base && origin === 'null') throw new Error('walkToPaymentPage: pass base, the page is not on the shop yet');
  const BASE = (base ?? origin).replace(/\/$/, '');
  const home = new URL(`${BASE}/`);

  // Shopware's storefront shows a loader while it reloads a part of the page (off-canvas cart, the
  // checkout after a change of payment method); a click while it is up goes nowhere.
  const settled = () => page.waitForFunction(
    () => ![...document.querySelectorAll('.loader, .element-loader-backdrop, .has-element-loader')].some((e) => e.offsetParent),
    { timeout: 30000 },
  );
  const visible = async (selector) => page.$eval(selector, (e) => !!e.offsetParent).catch(() => false);
  // A button below the fold: scroll it to the middle of the window and wait until the page stands
  // still, then click. Clicking straight away lands where the button was before the scroll, on a
  // short window (a 720-pixel recording) that is next to it.
  const clickInView = async (target) => {
    const handle = typeof target === 'string' ? await page.waitForSelector(target, { visible: true, timeout: 30000 }) : target;
    await handle.evaluate((e) => e.scrollIntoView({ block: 'center', behavior: 'smooth' }));
    await page.waitForFunction(() => new Promise((resolve) => {
      const y = window.scrollY;
      setTimeout(() => resolve(window.scrollY === y), 150);
    }), { timeout: 10000, polling: 50 });
    await handle.click();
  };

  await step('1-home', async () => {
    const here = new URL(page.url());
    if (here.origin !== home.origin || here.pathname !== home.pathname) await page.goto(home.href, { waitUntil: 'networkidle0', timeout: 60000 });
    // The cookie notice covers the lower edge of the page; a visitor would dismiss it first.
    if (await visible('.js-cookie-permission-button')) {
      await page.click('.js-cookie-permission-button');
    }
    await page.waitForFunction((name) => [...document.querySelectorAll('.product-box .product-name')].some((e) => e.textContent.trim() === name), { timeout: 20000 }, PRODUCT);
  });
  await step('2-add-to-cart', async () => {
    const boxes = await page.$$('.product-box');
    let target = null;
    for (const box of boxes) {
      if (await box.$eval('.product-name', (e) => e.textContent.trim()).catch(() => '') === PRODUCT) { target = box; break; }
    }
    if (!target) throw new Error(`no product box for "${PRODUCT}" on the home page`);
    await clickInView(await target.$('.btn-buy'));
    // The off-canvas cart opens with the article in it.
    await page.waitForSelector('.offcanvas.show .line-item', { visible: true, timeout: 30000 });
    await settled();
  });
  await step('3-checkout-address', async () => {
    await page.goto(`${BASE}/checkout/register`, { waitUntil: 'networkidle0', timeout: 60000 });
    const form = 'form[action$="/account/register"]';
    await page.waitForSelector(`${form} [name="email"]`, { visible: true, timeout: 30000 });
    // A guest: no customer account (Shopware 6.7 leaves "Create a customer account" unchecked).
    if (await page.$(`${form} [name="createCustomerAccount"]`)) {
      const create = await page.$eval(`${form} [name="createCustomerAccount"]`, (e) => e.checked);
      if (create) await page.click(`${form} [name="createCustomerAccount"]`);
    }
    if (await page.$(`${form} select[name="salutationId"]`)) {
      const salutation = await page.$eval(`${form} select[name="salutationId"] option:not([disabled])`, (o) => o.value).catch(() => null);
      if (salutation) await page.select(`${form} select[name="salutationId"]`, salutation);
    }
    const fill = async (name, value) => page.type(`${form} [name="${name}"]`, value);
    // Not shopware_surname_confirm: that field is the form's honeypot and stays empty.
    await fill('billingAddress[firstName]', 'Try'); await fill('billingAddress[lastName]', 'Visitor');
    await fill('email', 'visitor@example.invalid');
    await fill('billingAddress[street]', '1 Testnet Way');
    await fill('billingAddress[zipcode]', '10115');
    await fill('billingAddress[city]', 'Berlin');
    const germany = await page.$$eval(`${form} select[name="billingAddress[countryId]"] option`, (options) => options.find((o) => /Germany|Deutschland/.test(o.textContent))?.value);
    if (germany) await page.select(`${form} select[name="billingAddress[countryId]"]`, germany);
    await settled();
    if (await page.$(`${form} [name="acceptedDataProtection"]`)) await page.click(`${form} [name="acceptedDataProtection"]`);
    await Promise.all([page.waitForNavigation({ timeout: 60000 }), clickInView(`${form} button[type="submit"]`)]);
    if (!page.url().includes('/checkout/confirm')) {
      const problems = await page.$$eval('.invalid-feedback, .alert-danger', (els) => els.filter((e) => e.offsetParent).map((e) => e.textContent.trim()));
      throw new Error(`not on the confirm page (${page.url()}): ${problems.join(' | ')}`);
    }
  });
  await step('4-payment-method', async () => {
    const radio = `input[name="paymentMethodId"][value="${METHODS[ASSET]}"]`;
    await page.waitForSelector(radio, { timeout: 30000 });
    const titles = await page.$$eval('.payment-method-label strong, .payment-method-label .payment-method-description strong', (els) => els.map((e) => e.textContent.trim()));
    log(`payment methods: ${[...new Set(titles)].join(' | ')}`);
    const checked = await page.$eval(radio, (e) => e.checked);
    if (!checked) {
      // Changing the payment method saves it and reloads the confirm page.
      await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.$eval(radio, (e) => e.click())]);
    }
    await settled();
    if (!(await page.$eval(radio, (e) => e.checked))) throw new Error(`payment method ${ASSET} is not selected`);
  });
  await step('5-place-order', async () => {
    await page.$eval('#tos', (e) => { if (!e.checked) e.click(); });
    await settled();
    await Promise.all([page.waitForNavigation({ timeout: 60000 }), clickInView('#confirmFormSubmit')]);
    await page.waitForFunction(() => location.pathname.includes('/ledger-direct/payment/'), { timeout: 60000 });
    await page.waitForSelector('[data-ld-page]', { timeout: 30000 });
  });
  return page.evaluate(() => ({
    url: location.href,
    state: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-state'),
    amount: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-amount-requested'),
    asset: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-asset'),
  }));
}

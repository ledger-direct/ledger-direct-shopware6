// Walks the try shop in a real browser the way a prospect does: home page, add a miniature to the
// cart, guest checkout, a LedgerDirect payment method, place order, payment page in state "waiting".
// A screenshot of every step goes to the output folder; exits non-zero on the first failure.
//
//   npm install
//   CHROME=/path/to/chrome node visitor.mjs [base url] [screenshot dir] [xrp|rlusd|usdc]
import puppeteer from 'puppeteer-core';
import { readFileSync } from 'node:fs';
// The article to buy: the first miniature of the catalogue, found on the home page by its name —
// never "the first product", which changes with the catalogue.
const PRODUCT = JSON.parse(readFileSync(new URL('../catalog/catalog.json', import.meta.url))).products[0]['en-GB'].name;
const BASE = (process.argv[2] ?? 'http://localhost:8091').replace(/\/$/, '');
const OUT = process.argv[3] ?? '.';
const ASSET = (process.argv[4] ?? 'xrp').toLowerCase();
// The plugin's payment method ids (PaymentMethodInstaller).
const METHODS = { xrp: '7ca60321a9d2dac0fe3622a5110f55bb', rlusd: '7ca60321a9d2dac0fe3622a5110f55bd', usdc: '7ca60321a9d2dac0fe3622a5110f55be' };
if (!METHODS[ASSET]) throw new Error(`unknown asset ${ASSET}: xrp, rlusd or usdc`);

const browser = await puppeteer.launch({
  executablePath: process.env.CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'new', args: ['--no-sandbox', '--window-size=1280,1000'], defaultViewport: { width: 1280, height: 1000 },
});
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
const step = async (name, fn) => {
  const t = Date.now();
  try { await fn(); } catch (e) {
    await page.screenshot({ path: `${OUT}/${ASSET}-fail-${name}.png`, fullPage: true });
    console.log(`FAIL  ${name}: ${e.message}`); console.log('console errors:', errors.slice(-5)); await browser.close(); process.exit(1);
  }
  await page.screenshot({ path: `${OUT}/${ASSET}-${name}.png`, fullPage: true });
  console.log(`ok    ${name} (${((Date.now() - t) / 1000).toFixed(1)} s)`);
};
// Shopware's storefront shows a loader while it reloads a part of the page (off-canvas cart, the
// checkout after a change of payment method); a click while it is up goes nowhere.
const settled = () => page.waitForFunction(
  () => ![...document.querySelectorAll('.loader, .element-loader-backdrop, .has-element-loader')].some((e) => e.offsetParent),
  { timeout: 30000 },
);
const visible = async (selector) => page.$eval(selector, (e) => !!e.offsetParent).catch(() => false);

await step('1-home', async () => {
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle0', timeout: 60000 });
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
  await (await target.$('.btn-buy')).click();
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
  await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.click(`${form} button[type="submit"]`)]);
  if (!page.url().includes('/checkout/confirm')) {
    const problems = await page.$$eval('.invalid-feedback, .alert-danger', (els) => els.filter((e) => e.offsetParent).map((e) => e.textContent.trim()));
    throw new Error(`not on the confirm page (${page.url()}): ${problems.join(' | ')}`);
  }
});
await step('4-payment-method', async () => {
  const radio = `input[name="paymentMethodId"][value="${METHODS[ASSET]}"]`;
  await page.waitForSelector(radio, { timeout: 30000 });
  const titles = await page.$$eval('.payment-method-label strong, .payment-method-label .payment-method-description strong', (els) => els.map((e) => e.textContent.trim()));
  console.log('      payment methods:', [...new Set(titles)].join(' | '));
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
  await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.click('#confirmFormSubmit')]);
  await page.waitForFunction(() => location.pathname.includes('/ledger-direct/payment/'), { timeout: 60000 });
  await page.waitForSelector('[data-ld-page]', { timeout: 30000 });
});
const view = await page.evaluate(() => ({
  url: location.href,
  state: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-state'),
  amount: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-amount-requested'),
  asset: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-asset'),
}));
console.log('      payment page:', JSON.stringify(view));
if (errors.length) console.log('      console errors:', errors.slice(0, 5));
await browser.close();
if (view.state !== 'waiting' || !view.amount || view.asset !== ASSET.toUpperCase() || !view.url.includes('deepLinkCode=')) {
  console.log('FAIL  the payment page is not the expected one');
  process.exit(1);
}

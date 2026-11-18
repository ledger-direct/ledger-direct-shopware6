// Walks the try shop in a real browser the way a prospect does: home page, add a miniature to the
// cart, guest checkout, a LedgerDirect payment method, place order, payment page in state "waiting".
// The steps are in walk.mjs; this script runs them with a screenshot of every step in the output
// folder and exits non-zero on the first failure.
//
//   npm install
//   CHROME=/path/to/chrome node visitor.mjs [base url] [screenshot dir] [xrp|rlusd|usdc|xah]
import puppeteer from 'puppeteer-core';
import { METHODS, walkToPaymentPage } from './walk.mjs';

const BASE = (process.argv[2] ?? 'http://localhost:8091').replace(/\/$/, '');
const OUT = process.argv[3] ?? '.';
const ASSET = (process.argv[4] ?? 'xrp').toLowerCase();
if (!Object.hasOwn(METHODS, ASSET)) throw new Error(`unknown asset ${ASSET}: xrp, rlusd, usdc or xah`);

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

const view = await walkToPaymentPage(page, { asset: ASSET, base: BASE, step, log: (line) => console.log(`      ${line}`) });
console.log('      payment page:', JSON.stringify(view));
if (errors.length) console.log('      console errors:', errors.slice(0, 5));
await browser.close();
if (view.state !== 'waiting' || !view.amount || view.asset !== ASSET.toUpperCase() || !view.url.includes('deepLinkCode=')) {
  console.log('FAIL  the payment page is not the expected one');
  process.exit(1);
}

<?php
/**
 * The try-it shop's setup, through Shopware's Admin API with the demo login. Both parts are safe to
 * run again.
 *
 *   setup.php config    every start: where the shop is reached and what it receives into
 *     - the storefront's domains: English on the base URL, German under /de, both over http and https
 *       (browsers upgrade localhost to https on their own)
 *     - LD_TESTNET_ACCOUNT if set (yours: it needs the RLUSD and USDC trust lines itself)
 *     - otherwise the shop's own testnet wallet: created from the faucet on the first start, with
 *       trust lines to the RLUSD and USDC issuers of the core's StablecoinRegistry, and created anew
 *       when the testnet was reset (see wallet.php)
 *     - XRP always; RLUSD and USDC when the account has the trust lines; every other payment method off
 *     - XAH to the shop's own Xahau testnet account (wallet.php xahau), made and funded on the first
 *       start; while the Xahau testnet does not answer, XAH stays as it was and the next start tries again
 *
 *   setup.php catalog   when the image brings a new catalogue: the category "LedgerDirect demo" with the
 *     demo article for 1 EUR (the smoke test and the harness order it), the category "Miniatures" with
 *     the articles of catalog/catalog.json, and the home page that explains the demo and lists the
 *     miniatures. Ids are derived from fixed names; articles are found again by product number: an
 *     existing one gets its texts and price updated, never a second copy of its pictures. The image's
 *     own demo categories and products are switched off, not deleted.
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry;

const API = 'http://127.0.0.1';
const STATE = '/var/www/ledger-direct-try';
const ACCOUNT_FILE = STATE . '/account';
const WALLET_FILE = STATE . '/wallet.json';
const XAHAU_WALLET_FILE = STATE . '/wallet-xahau.json';
const WALLET = '/usr/local/share/ledger-direct/wallet.php';
const CATALOG = '/usr/local/share/ledger-direct/catalog';
const ADDRESS = '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/';
const SKU = 'ledger-direct-demo';
const STOREFRONT = '8a243080f92e4c719546314b577cf82b';   // Defaults::SALES_CHANNEL_TYPE_STOREFRONT
const CURRENCY = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';     // Defaults::CURRENCY (EUR)
const TAX_RATE = 19.0;
// The plugin's payment method ids (PaymentMethodInstaller): fixed since the first release.
const XRP = '7ca60321a9d2dac0fe3622a5110f55bb';
const RLUSD = '7ca60321a9d2dac0fe3622a5110f55bd';
const USDC = '7ca60321a9d2dac0fe3622a5110f55be';
const XAH = 'd9e4a5f06e6c4a36dfa37a410d88de42';    // md5('xahau-xah-payment')
const LOCALES = ['en-GB', 'de-DE'];

function out(string $message): void
{
    fwrite(STDOUT, '[ledger-direct-try] ' . $message . "\n");
}

/** A fixed id from a name, as Shopware's Uuid::fromStringToHex() makes it. */
function id(string $name): string
{
    return md5('ledger-direct-try-' . $name);
}

/** Shopware's Admin API, logged in with the demo admin. */
final class Api
{
    private ?string $token = null;

    public function __construct(private readonly string $user, private readonly string $password)
    {
    }

    public function call(string $method, string $path, mixed $body = null, ?string $contentType = null): mixed
    {
        $this->token ??= $this->login();
        $raw = $contentType !== null;
        $curl = curl_init(API . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'Content-Type: ' . ($contentType ?? 'application/json'),
            ],
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $raw ? $body : json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = (string) curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status >= 400 || $status === 0) {
            throw new RuntimeException("Admin API {$method} {$path} answered {$status}: " . substr($response, 0, 600));
        }

        return $response === '' ? null : json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $entity, array $criteria = []): array
    {
        return $this->call('POST', '/api/search/' . $entity, $criteria + ['limit' => 500])['data'] ?? [];
    }

    /** Writes through the sync API: one request, indexed before it answers. */
    public function sync(string $entity, array $payload, string $action = 'upsert'): void
    {
        if ($payload !== []) {
            $this->call('POST', '/api/_action/sync', [['action' => $action, 'entity' => $entity, 'payload' => array_values($payload)]]);
        }
    }

    private function login(): string
    {
        $curl = curl_init(API . '/api/oauth/token');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'grant_type' => 'password', 'client_id' => 'administration', 'scopes' => 'write',
                'username' => $this->user, 'password' => $this->password,
            ]),
        ]);
        $answer = json_decode((string) curl_exec($curl), true);
        curl_close($curl);
        if (!isset($answer['access_token'])) {
            throw new RuntimeException('the demo admin login was refused by the Admin API');
        }

        return $answer['access_token'];
    }
}

/** Runs the wallet tool; returns its exit code, passes its own log lines through. */
function wallet(string ...$args): int
{
    $command = 'php ' . escapeshellarg(WALLET) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    passthru($command, $code);

    return $code;
}

/**
 * The shop's own testnet account with its trust lines; [address, whether RLUSD and USDC can be paid].
 * The trust-line limits come from the core's StablecoinRegistry, the one source of issuer addresses.
 */
function ownWallet(): array
{
    if (is_file(WALLET_FILE) && wallet('exists', WALLET_FILE) === 3) {
        out('the testnet was reset since the last start; creating a new receiving account');
        unlink(WALLET_FILE);
    }
    if (!is_file(WALLET_FILE)) {
        ob_start();
        $code = wallet('create', WALLET_FILE);
        ob_end_clean();
        if ($code !== 0) {
            throw new RuntimeException('could not create a testnet account; set LD_TESTNET_ACCOUNT to an address of your own');
        }
    }
    $address = json_decode((string) file_get_contents(WALLET_FILE), true)['address'];

    $registry = new StablecoinRegistry();
    $lines = [$registry->getRLUSDAmount('testnet', '1000000'), $registry->getUSDCAmount('testnet', '1000000')];
    $trusted = wallet('trust', WALLET_FILE, json_encode($lines)) === 0;
    if (!$trusted) {
        out('the trust lines could not be set; RLUSD and USDC stay off for now, the next start tries again');
    }

    return [$address, $trusted];
}

/**
 * The shop's own Xahau testnet account, separate from the XRP Ledger one: the same r-address is a
 * different account on each chain (one account per chain, the core's INVARIANTS.md "Xahau"). null while
 * it is not on the Xahau testnet; the next start tries again.
 */
function xahauAccount(): ?string
{
    if (wallet('xahau', XAHAU_WALLET_FILE) !== 0) {
        return null;
    }

    return json_decode((string) file_get_contents(XAHAU_WALLET_FILE), true)['address'];
}

/** The storefront sales channel (the oldest one), with what the setup needs of it. */
function storefront(Api $api): array
{
    $found = $api->search('sales-channel', [
        'filter' => [['type' => 'equals', 'field' => 'typeId', 'value' => STOREFRONT]],
        'associations' => ['domains' => []],
        'sort' => [['field' => 'createdAt', 'order' => 'ASC']],
        'limit' => 1,
    ]);
    if ($found === []) {
        throw new RuntimeException('no storefront sales channel in this shop');
    }

    return $found[0];
}

/** @return array<string, string> locale code => language id */
function languages(Api $api): array
{
    $ids = [];
    foreach ($api->search('language', ['associations' => ['locale' => []]]) as $language) {
        $code = $language['locale']['code'] ?? null;
        if (in_array($code, LOCALES, true)) {
            $ids[$code] = $language['id'];
        }
    }
    foreach (LOCALES as $locale) {
        if (!isset($ids[$locale])) {
            throw new RuntimeException("the shop has no {$locale} language");
        }
    }

    return $ids;
}

function configure(Api $api): void
{
    $own = trim((string) getenv('LD_TESTNET_ACCOUNT'));
    if ($own !== '' && !preg_match(ADDRESS, $own)) {
        throw new RuntimeException("LD_TESTNET_ACCOUNT is not an XRPL address: {$own}");
    }
    if ($own !== '') {
        [$account, $stablecoins] = [$own, getenv('LD_STABLECOINS') !== '0'];
    } else {
        [$account, $stablecoins] = ownWallet();
    }
    file_put_contents(ACCOUNT_FILE, $account);

    // XAH out of the box, so a visitor pays with their wallet without configuring anything. While the
    // Xahau testnet account is not ready, the XAH settings stay as they were.
    $xahau = xahauAccount();
    if ($xahau === null) {
        out('the Xahau testnet account is not ready; XAH stays as it was, the next start tries again');
    }

    $channel = storefront($api);
    $languages = languages($api);
    $snippetSets = [];
    foreach ($api->search('snippet-set', ['filter' => [['type' => 'equalsAny', 'field' => 'iso', 'value' => LOCALES]]]) as $set) {
        $snippetSets[$set['iso']] ??= $set['id'];
    }

    // The sales channel knows both languages before a domain uses one.
    $methods = array_merge($stablecoins ? [XRP, RLUSD, USDC] : [XRP], $xahau === null ? [] : [XAH]);
    $api->sync('sales_channel', [[
        'id' => $channel['id'],
        'name' => 'LedgerDirect demo shop',
        'accessKey' => getenv('LD_ACCESS_KEY'),
        'languages' => array_map(static fn (string $id): array => ['id' => $id], array_values($languages)),
        'languageId' => $languages['en-GB'],
        'paymentMethods' => array_map(static fn (string $id): array => ['id' => $id], $methods),
        'paymentMethodId' => XRP,
    ]]);

    $domains = [];
    foreach (['http' => getenv('LD_BASE_URL'), 'https' => getenv('LD_TLS_URL')] as $scheme => $base) {
        $base = rtrim((string) $base, '/');
        if ($base === '') {
            continue;
        }
        foreach (['en-GB' => '', 'de-DE' => '/de'] as $locale => $path) {
            $domains[] = [
                'id' => id("domain-{$scheme}-{$locale}"),
                'salesChannelId' => $channel['id'],
                'url' => $base . $path,
                'languageId' => $languages[$locale],
                'snippetSetId' => $snippetSets[$locale],
                'currencyId' => $channel['currencyId'],
            ];
        }
    }
    // Other domains of the storefront go first (the image's http://localhost, or the ones of an earlier
    // port): a URL can belong to one domain only.
    $ours = array_column($domains, 'id');
    $stale = array_values(array_filter($channel['domains'] ?? [], static fn (array $d): bool => !in_array($d['id'], $ours, true)));
    $api->sync('sales_channel_domain', array_map(static fn (array $d): array => ['id' => $d['id']], $stale), 'delete');
    $api->sync('sales_channel_domain', $domains);

    $api->sync('payment_method', [
        ['id' => XRP, 'active' => true],
        ['id' => RLUSD, 'active' => $stablecoins],
        ['id' => USDC, 'active' => $stablecoins],
        ...($xahau === null ? [] : [['id' => XAH, 'active' => true]]),
    ]);
    // Only LedgerDirect at the checkout: Shopware's cash on delivery, invoice, prepayment … go off.
    $others = $api->search('payment-method', [
        'filter' => [
            ['type' => 'equals', 'field' => 'active', 'value' => true],
            ['type' => 'not', 'operator' => 'and', 'queries' => [['type' => 'equalsAny', 'field' => 'id', 'value' => [XRP, RLUSD, USDC, XAH]]]],
        ],
    ]);
    foreach ($others as $method) {
        try {
            $api->sync('payment_method', [['id' => $method['id'], 'active' => false]]);
        } catch (RuntimeException $refused) {
            out("payment method {$method['technicalName']} stays active: " . $refused->getMessage());
        }
    }

    $api->call('POST', '/api/_action/system-config/batch', ['null' => [
        'LedgerDirect.config.xrplEnabled' => true,
        'LedgerDirect.config.useXrplTestnet' => true,
        'LedgerDirect.config.xrplTestnetDestinationAccount' => $account,
        'LedgerDirect.config.xrplIsRlusdEnabled' => $stablecoins,
        'LedgerDirect.config.xrplIsUsdcEnabled' => $stablecoins,
        'LedgerDirect.config.xrplQuoteExpiry' => 300,
        'LedgerDirect.config.stellarEnabled' => false,
        // Xahau on its testnet on every start, also while the Xahau account is not ready: the try shop
        // never takes real XAH, whatever an earlier start or the administration set.
        'LedgerDirect.config.useXahauTestnet' => true,
        'core.basicInformation.shopName' => 'LedgerDirect demo shop',
    ] + ($xahau === null ? [] : [
        'LedgerDirect.config.xahauEnabled' => true,
        'LedgerDirect.config.xahauTestnetDestinationAccount' => $xahau,
    ])]);

    out('storefront ' . implode(', ', array_column($domains, 'url')));
    out("receiving account {$account} (" . ($own !== '' ? 'yours' : "the shop's own testnet wallet") . '); XRP'
        . ($stablecoins ? ', RLUSD and USDC' : ' only')
        . ($xahau !== null ? "; XAH to the shop's Xahau testnet account {$xahau}" : ''));
}

function catalog(Api $api): void
{
    $channel = storefront($api);
    $rootId = $channel['navigationCategoryId'];
    $catalog = json_decode((string) file_get_contents(CATALOG . '/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    $taxId = $api->search('tax', ['filter' => [['type' => 'equals', 'field' => 'taxRate', 'value' => TAX_RATE]], 'limit' => 1])[0]['id']
        ?? throw new RuntimeException('no 19 % tax rate in this shop');
    $folderId = $api->search('media-folder', ['filter' => [['type' => 'equals', 'field' => 'defaultFolder.entity', 'value' => 'product']], 'limit' => 1])[0]['id'] ?? null;

    // Categories: the miniatures first, the demo article's own category after them.
    $miniatures = id('category-' . $catalog['category']['url_key']);
    $demo = id('category-ledger-direct-demo');
    $api->sync('category', [
        [
            'id' => $miniatures, 'parentId' => $rootId, 'afterCategoryId' => null, 'active' => true, 'visible' => true, 'type' => 'page',
            'translations' => array_map(static fn (string $l): array => ['name' => $catalog['category'][$l]['name']], array_combine(LOCALES, LOCALES)),
        ],
        [
            'id' => $demo, 'parentId' => $rootId, 'afterCategoryId' => $miniatures, 'active' => true, 'visible' => true, 'type' => 'page',
            'translations' => ['en-GB' => ['name' => 'LedgerDirect demo'], 'de-DE' => ['name' => 'LedgerDirect-Demo']],
        ],
    ]);
    seoUrl($api, $channel['id'], $miniatures, $catalog['category']['url_key']);
    seoUrl($api, $channel['id'], $demo, 'ledger-direct-demo');

    $visible = static fn (string $key): array => [[
        'id' => id('visibility-' . $key), 'salesChannelId' => $channel['id'], 'visibility' => 30,
    ]];
    $price = static fn (float $gross): array => [[
        'currencyId' => CURRENCY, 'gross' => $gross, 'net' => round($gross / (1 + TAX_RATE / 100), 4), 'linked' => true,
    ]];

    // The demo article for 1 EUR, in its own category, not on the home page.
    $demoId = productId($api, SKU);
    $images = [];
    if (!hasPicture($api, id('media-demo-article'))) {
        upload($api, id('media-demo-article'), $folderId, articlePicture(), 'png', 'ledger-direct-demo-article',
            ['en-GB' => 'LedgerDirect demo article', 'de-DE' => 'LedgerDirect-Demo-Artikel']);
    }
    $images[] = ['id' => id('product-media-demo-article'), 'mediaId' => id('media-demo-article'), 'position' => 1];
    $api->sync('product', [[
        'id' => $demoId,
        'productNumber' => SKU,
        'stock' => 100000,
        'active' => true,
        'shippingFree' => true,
        'taxId' => $taxId,
        'price' => $price(1.00),
        'translations' => [
            'en-GB' => ['name' => 'LedgerDirect demo article', 'description' => '<p>A demo article for 1 EUR. Pay it with XRP, RLUSD or USDC on the XRP Ledger testnet.</p>'],
            'de-DE' => ['name' => 'LedgerDirect-Demo-Artikel', 'description' => '<p>Ein Demo-Artikel für 1 EUR. Bezahlt wird mit XRP, RLUSD oder USDC im XRP-Ledger-Testnet.</p>'],
        ],
        'categories' => [['id' => $demo]],
        'visibilities' => $visible(SKU),
        'media' => $images,
        'coverId' => $images[0]['id'],
    ]]);
    out('category "LedgerDirect demo" with the demo article (product number ' . SKU . ', 1 EUR)');

    // The miniatures, in their category; the home page lists that category through a product stream.
    $numbers = [SKU];
    foreach ($catalog['products'] as $item) {
        $numbers[] = $item['number'];
        $media = [];
        foreach ($item['images'] as $position => $image) {
            $mediaId = id('media-' . $image['file']);
            if (!hasPicture($api, $mediaId)) {
                upload($api, $mediaId, $folderId, (string) file_get_contents(CATALOG . '/images/' . $image['file']), 'jpg',
                    pathinfo($image['file'], PATHINFO_FILENAME), array_fill_keys(LOCALES, $image['alt'] ?? $item['en-GB']['name']));
            }
            $media[] = ['id' => id('product-media-' . $image['file']), 'mediaId' => $mediaId, 'position' => $position + 1];
        }
        $translations = [];
        foreach (LOCALES as $locale) {
            $text = $item[$locale] ?? $item['en-GB'];
            $translations[$locale] = [
                'name' => $text['name'],
                'metaDescription' => $text['tagline'],
                'description' => description($text, $item['facts'] ?? [], $catalog['closing'][$locale] ?? $catalog['closing']['en-GB'], $locale),
            ];
        }
        $existing = productId($api, $item['number']);
        $api->sync('product', [[
            'id' => $existing,
            'productNumber' => $item['number'],
            'stock' => 100000,
            'active' => true,
            'shippingFree' => true,
            'taxId' => $taxId,
            'price' => $price((float) $item['price']),
            'translations' => $translations,
            'categories' => [['id' => $miniatures]],
            'visibilities' => $visible($item['number']),
            'media' => $media,
            'coverId' => $media[0]['id'] ?? null,
        ]]);
        out(sprintf('%s "%s", %.2f EUR', $item['number'], $item['en-GB']['name'], $item['price']));
    }

    homePage($api, $rootId, $miniatures);
    retireForeign($api, $rootId, [$miniatures, $demo], $numbers);
}

/** The id of the product with this number, or the fixed id a new one gets. */
function productId(Api $api, string $number): string
{
    $found = $api->search('product', ['filter' => [['type' => 'equals', 'field' => 'productNumber', 'value' => $number]], 'limit' => 1]);

    return $found[0]['id'] ?? id('product-' . $number);
}

/**
 * The category's storefront URL is its URL key, in both languages (/miniatures/, /de/miniatures/): the
 * canonical SEO URL Shopware generated from the name is changed the way the administration changes it.
 */
function seoUrl(Api $api, string $salesChannelId, string $categoryId, string $urlKey): void
{
    foreach (languages($api) as $locale => $languageId) {
        $existing = $api->search('seo-url', ['filter' => [
            ['type' => 'equals', 'field' => 'foreignKey', 'value' => $categoryId],
            ['type' => 'equals', 'field' => 'routeName', 'value' => 'frontend.navigation.page'],
            ['type' => 'equals', 'field' => 'salesChannelId', 'value' => $salesChannelId],
            ['type' => 'equals', 'field' => 'languageId', 'value' => $languageId],
            ['type' => 'equals', 'field' => 'isCanonical', 'value' => true],
        ], 'limit' => 1]);
        $api->sync('seo_url', [[
            'id' => $existing[0]['id'] ?? id("seo-url-{$urlKey}-{$locale}"),
            'salesChannelId' => $salesChannelId,
            'languageId' => $languageId,
            'foreignKey' => $categoryId,
            'routeName' => 'frontend.navigation.page',
            'pathInfo' => '/navigation/' . $categoryId,
            'seoPathInfo' => $urlKey . '/',
            'isCanonical' => true,
            'isModified' => true,
        ]]);
    }
}

/** Whether the media exists with its file on disk (the file can be gone while the row is not). */
function hasPicture(Api $api, string $mediaId): bool
{
    $media = $api->search('media', ['ids' => [$mediaId]])[0] ?? null;

    return $media !== null && ($media['path'] ?? '') !== '' && is_file('/var/www/html/public/' . $media['path']);
}

/** Creates the media once and uploads its file. */
function upload(Api $api, string $mediaId, ?string $folderId, string $bytes, string $extension, string $name, array $alt): void
{
    $api->sync('media', [[
        'id' => $mediaId,
        'mediaFolderId' => $folderId,
        'private' => false,
        'translations' => array_map(static fn (string $text): array => ['alt' => $text, 'title' => $text], $alt),
    ]]);
    $type = $extension === 'png' ? 'image/png' : 'image/jpeg';
    $api->call('POST', "/api/_action/media/{$mediaId}/upload?extension={$extension}&fileName=" . rawurlencode($name), $bytes, $type);
}

function description(array $text, array $facts, string $closing, string $locale): string
{
    $paragraphs = array_map(
        static fn (string $line): string => '<p>' . htmlspecialchars($line) . '</p>',
        array_values(array_filter(array_map('trim', explode("\n", $text['description']))))
    );
    $labels = $locale === 'de-DE' ? ['scale' => 'Maßstab', 'material' => 'Material', 'finish' => 'Bemalung'] : [];
    $list = array_map(
        static fn (string $key, string $value): string => '<li>' . htmlspecialchars($labels[$key] ?? ucfirst($key)) . ': ' . htmlspecialchars($value) . '</li>',
        array_keys($facts),
        array_values($facts)
    );

    return implode("\n", $paragraphs)
        . ($list ? "\n<ul>" . implode('', $list) . '</ul>' : '')
        . "\n<p><em>" . htmlspecialchars($closing) . '</em></p>';
}

/** A picture for the demo article, drawn here: no image file with a licence of its own in the image. */
function articlePicture(): string
{
    $size = 800;
    $image = imagecreatetruecolor($size, $size);
    $accent = imagecolorallocate($image, 0x1f, 0x5e, 0xff);
    $white = imagecolorallocate($image, 0xff, 0xff, 0xff);
    imagefilledrectangle($image, 0, 0, $size, $size, $accent);
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    foreach ([['LedgerDirect', 64, 330], ['demo article', 44, 420], ['1 EUR · XRP Ledger testnet', 26, 500]] as [$text, $points, $y]) {
        if (is_file($font) && function_exists('imagettftext')) {
            $box = imagettfbbox($points, 0, $font, $text);
            imagettftext($image, $points, 0, (int) (($size - ($box[2] - $box[0])) / 2), $y, $white, $font, $text);
        } else {
            imagestring($image, 5, (int) (($size - 9 * strlen($text)) / 2), $y - 15, $text, $white);
        }
    }
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}

/**
 * The home page: what this shop is and how to pay, above the listing of the root category. A category
 * listing always takes the products of the categories below it (the demo article too), so the root
 * category takes its products from a stream instead: the articles of the Miniatures category.
 */
function homePage(Api $api, string $rootId, string $miniatures): void
{
    $repository = '<a href="https://github.com/ledger-direct/ledger-direct-shopware6">LedgerDirect for Shopware 6</a>';
    $wallets = '<a href="https://xaman.app/">Xaman</a>';
    $text = [
        'en-GB' => <<<HTML
<h2>Pay with LedgerDirect</h2>
<p><strong>A demo shop on the XRP Ledger and Xahau testnets.</strong> It runs {$repository}:
customers pay with XRP, RLUSD, USDC or XAH straight into the shop's own wallet — no payment provider in between.
Nothing here costs real money, and no order is shipped.</p>
<ol>
<li>Put a miniature in the cart and check out as a guest — any name and address will do.</li>
<li>Choose <em>XRP on XRPL</em> (or RLUSD, USDC or XAH) and place the order.</li>
<li>The payment page shows the amount, the shop's account and a destination tag. Pay it from a testnet wallet,
with the destination tag, or connect the wallet with the buttons on the page. The page notices the payment within seconds.</li>
</ol>
<p>A testnet wallet: {$wallets} in testnet mode or <a href="https://crossmark.io/">Crossmark</a>,
with XRP from the <a href="https://xrpl.org/resources/dev-tools/xrp-faucets">XRP testnet faucet</a>. RLUSD and USDC come from the
<a href="https://tryrlusd.com/">RLUSD faucet</a> and the <a href="https://faucet.circle.com/">Circle faucet</a>,
XAH from the <a href="https://xahau-test.net/">Xahau testnet faucet</a>, with the wallet on the Xahau testnet.</p>
HTML,
        'de-DE' => <<<HTML
<h2>Bezahlen mit LedgerDirect</h2>
<p><strong>Ein Demo-Shop in den Testnets von XRP Ledger und Xahau.</strong> Hier läuft {$repository}:
Kundinnen und Kunden bezahlen mit XRP, RLUSD, USDC oder XAH direkt in die eigene Wallet des Shops — ohne Zahlungsdienstleister dazwischen.
Nichts hier kostet echtes Geld, und keine Bestellung wird versendet.</p>
<ol>
<li>Eine Miniatur in den Warenkorb legen und als Gast bestellen — Name und Adresse sind beliebig.</li>
<li><em>XRP (XRPL)</em> (oder RLUSD, USDC oder XAH) wählen und die Bestellung abschicken.</li>
<li>Die Zahlungsseite zeigt Betrag, Konto des Shops und einen Destination Tag. Aus einer Testnet-Wallet bezahlen,
mit dem Destination Tag, oder die Wallet über die Knöpfe auf der Seite verbinden. Die Seite bemerkt die Zahlung nach wenigen Sekunden.</li>
</ol>
<p>Eine Testnet-Wallet: {$wallets} im Testnet-Modus oder <a href="https://crossmark.io/">Crossmark</a>,
mit XRP aus dem <a href="https://xrpl.org/resources/dev-tools/xrp-faucets">XRP-Testnet-Faucet</a>. RLUSD und USDC gibt es beim
<a href="https://tryrlusd.com/">RLUSD-Faucet</a> und beim <a href="https://faucet.circle.com/">Circle-Faucet</a>,
XAH beim <a href="https://xahau-test.net/">Xahau-Testnet-Faucet</a>, mit der Wallet im Xahau-Testnet.</p>
HTML,
    ];
    $static = static fn (mixed $value): array => ['source' => 'static', 'value' => $value];
    $page = id('cms-home');
    $api->sync('cms_page', [[
        'id' => $page,
        'type' => 'product_list',
        'translations' => ['en-GB' => ['name' => 'LedgerDirect demo shop: home'], 'de-DE' => ['name' => 'LedgerDirect-Demo-Shop: Startseite']],
        'sections' => [[
            'id' => id('cms-home-section'),
            'position' => 0,
            'type' => 'default',
            'sizingMode' => 'boxed',
            'blocks' => [
                [
                    'id' => id('cms-home-text'),
                    'position' => 0,
                    'type' => 'text',
                    'sectionPosition' => 'main',
                    'marginTop' => '20px',
                    'marginBottom' => '20px',
                    'slots' => [[
                        'id' => id('cms-home-text-slot'),
                        'type' => 'text',
                        'slot' => 'content',
                        'translations' => array_map(
                            static fn (string $html): array => ['config' => ['content' => $static($html), 'verticalAlign' => $static(null)]],
                            $text
                        ),
                    ]],
                ],
                [
                    'id' => id('cms-home-listing'),
                    'position' => 1,
                    'type' => 'product-listing',
                    'sectionPosition' => 'main',
                    'slots' => [[
                        'id' => id('cms-home-listing-slot'),
                        'type' => 'product-listing',
                        'slot' => 'content',
                        'config' => [
                            'boxLayout' => $static('standard'),
                            'showSorting' => $static(false),
                            'useCustomSorting' => $static(false),
                            'availableSortings' => $static([]),
                            'defaultSorting' => $static(''),
                            'filters' => $static(''),
                            'propertyWhitelist' => $static([]),
                        ],
                    ]],
                ],
            ],
        ]],
    ]]);
    $stream = id('stream-miniatures');
    $api->sync('product_stream', [[
        'id' => $stream,
        'name' => 'LedgerDirect demo shop: miniatures',
        'filters' => [[
            'id' => id('stream-miniatures-filter'),
            'type' => 'equalsAny',
            'field' => 'categoriesRo.id',
            'value' => $miniatures,
            'position' => 0,
        ]],
    ]]);
    $api->sync('category', [['id' => $rootId, 'cmsPageId' => $page, 'productAssignmentType' => 'product_stream', 'productStreamId' => $stream]]);
    out('home page: the demo explained, the miniatures listed');
}

/**
 * The image's demo data (clothing, food …) stays in the database but leaves the shop: other categories
 * below the root and every other product go inactive.
 */
function retireForeign(Api $api, string $rootId, array $categories, array $numbers): void
{
    $foreign = $api->search('category', ['filter' => [
        ['type' => 'equals', 'field' => 'parentId', 'value' => $rootId],
        ['type' => 'equals', 'field' => 'active', 'value' => true],
        ['type' => 'not', 'operator' => 'and', 'queries' => [['type' => 'equalsAny', 'field' => 'id', 'value' => $categories]]],
    ]]);
    $api->sync('category', array_map(static fn (array $c): array => ['id' => $c['id'], 'active' => false], $foreign));

    $products = $api->search('product', ['filter' => [
        ['type' => 'equals', 'field' => 'active', 'value' => true],
        ['type' => 'not', 'operator' => 'and', 'queries' => [['type' => 'equalsAny', 'field' => 'productNumber', 'value' => $numbers]]],
    ], 'limit' => 500]);
    foreach (array_chunk($products, 100) as $chunk) {
        $api->sync('product', array_map(static fn (array $p): array => ['id' => $p['id'], 'active' => false], $chunk));
    }
    out(sprintf("the image's demo data switched off: %d categories, %d products", count($foreign), count($products)));
}

$api = new Api((string) getenv('LD_ADMIN_USER'), (string) getenv('LD_ADMIN_PASSWORD'));
match ($argv[1] ?? '') {
    'config' => configure($api),
    'catalog' => catalog($api),
    default => throw new InvalidArgumentException('usage: setup.php config|catalog'),
};

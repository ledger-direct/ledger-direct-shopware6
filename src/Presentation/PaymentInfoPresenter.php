<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;

/**
 * What the administration's payment card shows, as the server states it.
 *
 * The contract (INVARIANTS.md, "Payment status") forbids adapters to derive
 * the verdict themselves, and that holds for JavaScript in the merchant's
 * browser as much as for PHP: the card receives the core's status payload
 * untouched under `status`, and under `display` the strings it prints,
 * already formatted by the same AmountFormatter the payment page uses.
 * The component formats nothing but a date.
 *
 * The rows are the Magento plugin's Payment Information, so the four
 * plugins tell the merchant the same thing: asset and network, requested
 * amount, rate, receiving account, destination tag, issuer for a token,
 * quote validity while nothing has arrived, what arrived - naming the other
 * token and its issuer when it is the wrong one -, what is still due, the
 * transaction with its explorer link, the CTID.
 */
final class PaymentInfoPresenter
{
    /**
     * @return array{status: array<string, mixed>, display: array<string, mixed>}
     */
    public static function present(PaymentIntent $intent, SettlementPolicy $policy): array
    {
        $status = PaymentStatus::fromIntent($intent, $policy);
        $wrongAsset = $policy->isWrongAsset($intent);
        $paidAsset = null;

        if ($wrongAsset) {
            // The delivered asset, so the merchant can name what the customer sent: a token
            // with its issuer, or the native asset when a token order was paid with XRP.
            $paidAsset = is_array($intent->amountPaid)
                ? ['currency' => self::currencyName((string) ($intent->amountPaid['currency'] ?? '')), 'issuer' => (string) ($intent->amountPaid['issuer'] ?? '')]
                : ['currency' => 'XRP', 'issuer' => null];
        }

        return [
            'status' => $status->toArray(),
            'display' => [
                'asset' => $intent->baseAsset,
                'chain' => $intent->chain,
                'network' => $intent->network,
                'amountRequested' => AmountFormatter::amountRequested($intent),
                'exchangeRate' => AmountFormatter::rate($intent->exchangeRate),
                'pairing' => $intent->pairing,
                'destinationAccount' => $intent->destinationAccount,
                'destinationTag' => $intent->destinationTag,
                'issuer' => is_array($intent->amountRequested) ? (string) ($intent->amountRequested['issuer'] ?? '') : null,
                // The quote is spent once something arrived; its validity only matters before.
                'quoteValidUntil' => $intent->amountPaid === null && $intent->expiry !== null ? gmdate('c', $intent->expiry) : null,
                'amountPaid' => AmountFormatter::amountPaid($intent),
                'shortfall' => AmountFormatter::shortfall($intent, $policy),
                'wrongAsset' => $wrongAsset,
                'paidAsset' => $paidAsset,
                'hash' => $intent->hash,
                'ctid' => $intent->ctid,
                'explorerUrl' => Explorer::transactionUrl($intent->network, $intent->hash),
            ],
        ];
    }

    /**
     * An XRPL currency code as a name: the 40-hex form decodes to its ASCII letters
     * (RLUSD, USDC), anything else stays as it is.
     */
    public static function currencyName(string $currency): string
    {
        if (preg_match('/^[0-9A-F]{40}$/i', $currency) === 1) {
            $decoded = rtrim((string) hex2bin($currency), "\0");
            if ($decoded !== '' && preg_match('/^[A-Za-z0-9]+$/', $decoded) === 1) {
                return $decoded;
            }
        }

        return $currency;
    }
}

<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Stellar\StellarAmount;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * What the administration lists as received payments for one payment
 * intent: the quote in short, and one row per stored ledger payment on its
 * receiving account and tag.
 *
 * A row is one payment as the ledger delivered it, not a verdict on the
 * order. Two rows in the quoted asset add up, and what they settle is the
 * payment card's statement (PaymentInfoPresenter). Whether a row is in the
 * quoted asset is asked of the core, with the row as the intent's only
 * payment: the rule stays SettlementPolicy::isWrongAsset() and gets no
 * second definition here. A payment in another asset is listed and marked,
 * never left out; the merchant has to see what a customer sent by mistake.
 *
 * Left out is what delivered nothing measurable: a transaction that is no
 * payment (an EscrowCreate carries a destination and a tag too), one that
 * failed, and one whose delivered amount cannot be read. The core skips the
 * same ones when it matches.
 */
final class ReceivedPaymentsPresenter
{
    /** rippled counts a transaction's `date` in seconds since 2000-01-01T00:00:00Z, not since 1970. */
    private const RIPPLE_EPOCH = 946684800;

    /**
     * @param list<XrplTransaction|StellarPayment> $payments newest first, on the intent's network
     * @return array{chain: string, network: string, asset: string, amountRequested: string, issuer: ?string, payments: list<array<string, mixed>>}
     */
    public static function present(PaymentIntent $intent, array $payments, SettlementPolicy $policy): array
    {
        $rows = [];

        foreach ($payments as $payment) {
            $row = self::row($intent, $payment, $policy);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return [
            'chain' => $intent->chain,
            'network' => $intent->network,
            'asset' => $intent->baseAsset,
            'amountRequested' => AmountFormatter::amountRequested($intent),
            'issuer' => is_array($intent->amountRequested) ? (string) ($intent->amountRequested['issuer'] ?? '') : null,
            'payments' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>|null null for a row that delivered nothing measurable
     */
    private static function row(PaymentIntent $intent, XrplTransaction|StellarPayment $payment, SettlementPolicy $policy): ?array
    {
        try {
            if ($payment instanceof StellarPayment) {
                // An operation, not a transaction: two payments of one transaction share its hash.
                $id = $payment->key();
                $delivered = StellarAmount::decode($payment);
                $ledgerIndex = $payment->ledger();
                $timestamp = $payment->createdAt;
            } else {
                $id = $payment->hash;
                $delivered = $payment->getDeliveredAmount();
                $ledgerIndex = (int) $payment->ledgerIndex;
                $timestamp = $payment->date + self::RIPPLE_EPOCH;
            }
        } catch (UnexpectedValueException|InvalidArgumentException) {
            return null;
        }

        if ($delivered === null) {
            return null;
        }

        $asOnlyPayment = $intent->withFulfillment($payment->hash, $delivered);

        return [
            'id' => $id,
            'sender' => $payment->account,
            'amount' => [
                // The core's plain decimal, as on the payment card: no exponent, no trailing zeros.
                'value' => $asOnlyPayment->amountPaidValue(),
                'asset' => is_array($delivered)
                    ? PaymentInfoPresenter::currencyName($delivered['currency'])
                    : (ChainCatalog::has($intent->chain) ? ChainCatalog::chain($intent->chain)->nativeAsset : $intent->chain),
                'issuer' => is_array($delivered) ? $delivered['issuer'] : null,
            ],
            'quotedAsset' => !$policy->isWrongAsset($asOnlyPayment),
            'hash' => $payment->hash,
            'ledgerIndex' => $ledgerIndex,
            'date' => gmdate('c', $timestamp),
            'explorerUrl' => Explorer::transactionUrl($intent->chain, $intent->network, $payment->hash),
        ];
    }
}

<?php

namespace Omnibank\Bridge;

use Omnibank\Model\Account;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\Consent;
use Omnibank\Model\Money;

/** Bridge's accounts, transactions and item statuses, read as Omnibank's. */
final class Mapper
{
    /**
     * Item statuses the user must act on, through a connect session again:
     * wrong credentials (402), an action on the bank's website (429), a
     * password to change (430), the consent's strong authentication to renew
     * (1010), a professional account to validate (1100).
     */
    public const RENEWAL_STATUSES = [402, 429, 430, 1010, 1100];

    public static function account(array $data): Account
    {
        return new Account(
            id: (string) $data['id'],
            iban: isset($data['iban']) && '' !== $data['iban'] ? (string) $data['iban'] : null,
            bic: isset($data['bic']) && '' !== $data['bic'] ? (string) $data['bic'] : null,
            name: (string) ($data['name'] ?? $data['id']),
            currency: strtoupper((string) ($data['currency_code'] ?? 'EUR')),
            type: isset($data['type']) ? (string) $data['type'] : null,
            raw: $data,
        );
    }

    /** @return list<Balance> booked (balance), and instant (instant_balance, with what is pending) when Bridge gives it */
    public static function balances(array $data): array
    {
        $currency = strtoupper((string) ($data['currency_code'] ?? 'EUR'));
        $at = isset($data['updated_at']) ? new \DateTimeImmutable((string) $data['updated_at']) : new \DateTimeImmutable();
        $balances = [];
        if (isset($data['balance'])) {
            $balances[] = new Balance(Money::fromDecimal((float) $data['balance'], $currency), $at, 'booked');
        }
        if (isset($data['instant_balance'])) {
            $balances[] = new Balance(Money::fromDecimal((float) $data['instant_balance'], $currency), $at, 'instant');
        }

        return $balances;
    }

    /** A booked transaction; null for a future or deleted one. */
    public static function transaction(array $data, string $currency): ?BankTransaction
    {
        if (!empty($data['future']) || !empty($data['deleted']) || !isset($data['id'], $data['amount'])) {
            return null;
        }
        $date = $data['booking_date'] ?? $data['date'] ?? $data['transaction_date'] ?? null;
        if (!\is_string($date) || '' === $date) {
            return null;
        }
        $description = (string) ($data['provider_description'] ?? $data['clean_description'] ?? '');

        return new BankTransaction(
            id: (string) $data['id'],
            bookedOn: new \DateTimeImmutable($date),
            valueOn: !empty($data['value_date']) ? new \DateTimeImmutable((string) $data['value_date']) : null,
            amount: Money::fromDecimal((float) $data['amount'], (string) ($data['currency_code'] ?? $currency)),
            label: $description,
            counterpartyName: null,
            counterpartyIban: null,
            reference: null,
            raw: $data,
        );
    }

    /** What an item's status means for the consent; with no status code, its words (status_code_info). */
    public static function consent(mixed $status, ?string $info = null): Consent
    {
        if (\is_numeric($status)) {
            return \in_array((int) $status, self::RENEWAL_STATUSES, true) ? Consent::needsRenewal() : Consent::active();
        }
        if (null !== $info && preg_match('/sca|credential|password|action|renew|validat|expired/i', $info)) {
            return Consent::needsRenewal();
        }

        return Consent::active();
    }
}

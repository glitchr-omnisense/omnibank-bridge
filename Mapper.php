<?php

namespace Omnibank\Bridge;

use Omnibank\Model\Account;
use Omnibank\Model\AccountKind;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\ConnectionState;
use Omnibank\Model\Consent;
use Omnibank\Model\Money;
use Omnibank\Model\TransactionCategory;

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

    /**
     * Bridge's account types (docs.bridgeapi.io, the account schema of List
     * accounts), as Omnibank's kinds; shared_saving_plan and special are
     * earlier versions' words.
     */
    public const ACCOUNT_KINDS = [
        'checking' => AccountKind::CHECKING,
        'savings' => AccountKind::SAVINGS,
        'brokerage' => AccountKind::MARKET,
        'card' => AccountKind::CARD,
        'loan' => AccountKind::LOAN,
        'pea' => AccountKind::SAVINGS_PLAN,
        'shared_saving_plan' => AccountKind::SAVINGS_PLAN,
        'life_insurance' => AccountKind::LIFE_INSURANCE,
        'unknown' => AccountKind::UNKNOWN,
    ];

    /**
     * Bridge's item statuses (docs.bridgeapi.io, Items statuses; 430 from
     * its earlier versions), as Omnibank's connection states. 1003 (could
     * not refresh, try again) and 1007 (refresh disabled for a while) are
     * the bank's side; 1005 (account not supported) an error.
     */
    public const CONNECTION_STATES = [
        0 => ConnectionState::OK,
        1009 => ConnectionState::OK,
        -2 => ConnectionState::PENDING,
        -3 => ConnectionState::PENDING,
        1099 => ConnectionState::PENDING,
        402 => ConnectionState::WRONG_CREDENTIALS,
        429 => ConnectionState::ACTION_NEEDED,
        430 => ConnectionState::ACTION_NEEDED,
        1010 => ConnectionState::ACTION_NEEDED,
        1100 => ConnectionState::ACTION_NEEDED,
        1003 => ConnectionState::BANK_UNAVAILABLE,
        1007 => ConnectionState::BANK_UNAVAILABLE,
        1005 => ConnectionState::BUG,
    ];

    /**
     * Bridge's categories (docs.bridgeapi.io, Transactions categories: the
     * French tree, by id), as Omnibank's: the parents, then the children
     * that leave their parent's family. 1 ("A catégoriser") is none.
     */
    public const CATEGORIES = [
        2 => TransactionCategory::INCOME,
        159 => TransactionCategory::TAXES,
        160 => TransactionCategory::OTHER,
        161 => TransactionCategory::HOUSING,
        162 => TransactionCategory::SHOPPING,
        163 => TransactionCategory::HEALTH,
        164 => TransactionCategory::BANK_FEES,
        165 => TransactionCategory::TRANSPORT,
        166 => TransactionCategory::BUSINESS,
        167 => TransactionCategory::HOUSEHOLD,
        168 => TransactionCategory::FOOD,
        170 => TransactionCategory::LEISURE,
        171 => TransactionCategory::SUBSCRIPTIONS,
        303 => TransactionCategory::TRANSFER,
        315 => TransactionCategory::HEALTH,
        // children
        271 => TransactionCategory::CASH,
        282 => TransactionCategory::TRANSFER,
        289 => TransactionCategory::SAVINGS,
        278 => TransactionCategory::INSURANCE,
        294 => TransactionCategory::LEISURE,
        246 => TransactionCategory::INSURANCE,
        247 => TransactionCategory::INSURANCE,
        89 => TransactionCategory::LOAN,
        194 => TransactionCategory::LOAN,
        259 => TransactionCategory::LOAN,
        191 => TransactionCategory::TRANSFER,
        192 => TransactionCategory::SAVINGS,
        441886 => TransactionCategory::TAXES,
        85 => TransactionCategory::CASH,
        88 => TransactionCategory::CASH,
    ];

    /** The children of each parent (the same page), so that a child not listed above takes its parent's category. */
    public const PARENTS = [
        2 => [3, 80, 230, 231, 232, 233, 271, 279, 282, 283, 289, 314, 327, 441893, 441894],
        159 => [206, 207, 208, 209, 302, 441988],
        160 => [276, 278, 294, 308, 324],
        161 => [216, 217, 218, 220, 221, 222, 246, 293, 323, 328],
        162 => [183, 184, 186, 243, 262, 272, 318, 319, 441888],
        163 => [236, 245, 261, 268, 322, 325],
        164 => [79, 89, 191, 192, 194, 195, 306, 756587],
        165 => [84, 87, 196, 197, 198, 247, 251, 264, 288, 309],
        166 => [90, 202, 203, 204, 205, 265, 270, 274, 441886, 441889, 441890, 441891, 441892, 441895, 441896, 441897, 441898, 441899, 441900],
        167 => [237, 238, 239, 240, 241, 259, 266, 267],
        168 => [83, 188, 260, 273, 313],
        170 => [223, 224, 226, 227, 242, 244, 249, 263, 269, 310, 320],
        171 => [180, 219, 258, 277, 280],
        303 => [78, 85, 88, 326],
        315 => [235, 248, 316, 317, 321],
    ];

    /** When a transaction has no category, what its operation type says on its own (docs.bridgeapi.io, Operation types). */
    public const OPERATION_CATEGORIES = [
        'transfer' => TransactionCategory::TRANSFER,
        'withdrawal' => TransactionCategory::CASH,
        'check' => TransactionCategory::CASH,
        'deposit' => TransactionCategory::CASH,
    ];

    /** Omnibank's kind for a Bridge account type; UNKNOWN for a type this list does not know. */
    public static function kind(?string $type): AccountKind
    {
        return self::ACCOUNT_KINDS[strtolower((string) $type)] ?? AccountKind::UNKNOWN;
    }

    /** Omnibank's state for a Bridge item status; UNKNOWN for a status this list does not know, null for none. */
    public static function connectionState(mixed $status): ?ConnectionState
    {
        if (!\is_numeric($status)) {
            return null;
        }

        return self::CONNECTION_STATES[(int) $status] ?? ConnectionState::UNKNOWN;
    }

    /** A transaction's category: its category_id, else its operation_type; null when neither says (or "A catégoriser"). */
    public static function category(array $data): ?TransactionCategory
    {
        $id = $data['category_id'] ?? null;
        if (\is_numeric($id) && 1 !== (int) $id) {
            $id = (int) $id;
            if (isset(self::CATEGORIES[$id])) {
                return self::CATEGORIES[$id];
            }
            foreach (self::PARENTS as $parent => $children) {
                if (\in_array($id, $children, true)) {
                    return self::CATEGORIES[$parent];
                }
            }

            return TransactionCategory::UNKNOWN;
        }

        return self::OPERATION_CATEGORIES[strtolower((string) ($data['operation_type'] ?? ''))] ?? null;
    }

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
            kind: self::kind(isset($data['type']) ? (string) $data['type'] : null),
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
            category: self::category($data),
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

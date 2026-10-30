<?php

namespace Omnibank\Bridge\Tests;

use Omnibank\Bridge\Api;
use Omnibank\Bridge\BridgeGatewayFactory;
use Omnibank\Bridge\Mapper;
use Omnibank\GatewayInterface;
use Omnibank\Model\AccountKind;
use Omnibank\Model\ConnectionState;
use Omnibank\Model\Connection;
use Omnibank\Model\Consent;
use Omnibank\Model\TransactionCategory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Bridge's own words - account types, category ids and operation types,
 * item statuses - read as Omnibank's kinds, categories and states: on the
 * recorded answers, then on every word docs.bridgeapi.io publishes.
 */
final class MappingTest extends TestCase
{
    private const SECRET = 'brdg_whsec';

    private function gateway(): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $after = isset($query['after']);

            return match ((string) parse_url($url, \PHP_URL_PATH)) {
                '/v3/aggregation/accounts' => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/accounts-page-'.($after ? 2 : 1).'.json')),
                '/v3/aggregation/transactions' => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/transactions-page-'.($after ? 2 : 1).'.json')),
                default => new MockResponse('{"type":"not_found"}', ['http_code' => 404]),
            };
        });

        return (new BridgeGatewayFactory($http))->create(['client_id' => 'brdg_client', 'client_secret' => 'brdg_secret', 'webhook_secret' => self::SECRET]);
    }

    private static function connected(): Connection
    {
        return new Connection(['user_uuid' => 'u', 'access_token' => 'brdg_test_not_a_real_token', 'expires_at' => '2099-01-01T00:00:00Z'], Consent::active());
    }

    public function testTheRecordedAccountsAndTransactions(): void
    {
        $gateway = $this->gateway();
        $accounts = $gateway->accounts(self::connected());
        self::assertSame([AccountKind::CHECKING, AccountKind::SAVINGS], array_map(static fn ($a) => $a->kind, $accounts));

        $categories = [];
        foreach ($gateway->transactions(self::connected(), $accounts[0]) as $transaction) {
            $categories[$transaction->label] = $transaction->category;
        }
        self::assertSame(TransactionCategory::INCOME, $categories['VIR SEPA RECU /DE CAMILLE DURAND /MOTIF FACTURE F-2026-042'], 'category 230, Salaires, under Entrées d\'argent');
        self::assertNull($categories['PRLV SEPA EDF CLIENT 12345'], 'a direct debit with no category says nothing');
        self::assertNull($categories['CB CARREFOUR CITY 06/09']);
    }

    public function testCategoryIdsChildrenThenParentsThenOperationTypes(): void
    {
        self::assertSame(TransactionCategory::FOOD, Mapper::category(['category_id' => 273]), 'Supermarché, under Alimentation');
        self::assertSame(TransactionCategory::INSURANCE, Mapper::category(['category_id' => 246]), 'Assurance habitation leaves Logement');
        self::assertSame(TransactionCategory::LOAN, Mapper::category(['category_id' => 89]), 'Remboursement emprunt leaves Banque');
        self::assertSame(TransactionCategory::CASH, Mapper::category(['category_id' => 85]));
        self::assertSame(TransactionCategory::SUBSCRIPTIONS, Mapper::category(['category_id' => 277]));
        self::assertSame(TransactionCategory::UNKNOWN, Mapper::category(['category_id' => 999999]), 'an id nobody published');
        self::assertNull(Mapper::category(['category_id' => 1, 'operation_type' => 'card']), '"A catégoriser" is none');
        self::assertSame(TransactionCategory::TRANSFER, Mapper::category(['category_id' => 1, 'operation_type' => 'transfer']));
        self::assertSame(TransactionCategory::CASH, Mapper::category(['operation_type' => 'withdrawal']));
        foreach (Mapper::PARENTS as $children) {
            foreach ($children as $child) {
                self::assertNotSame(TransactionCategory::UNKNOWN, Mapper::category(['category_id' => $child]), (string) $child);
            }
        }
    }

    public function testEveryPublishedAccountType(): void
    {
        foreach (['checking' => AccountKind::CHECKING, 'savings' => AccountKind::SAVINGS, 'brokerage' => AccountKind::MARKET, 'card' => AccountKind::CARD, 'loan' => AccountKind::LOAN, 'pea' => AccountKind::SAVINGS_PLAN, 'life_insurance' => AccountKind::LIFE_INSURANCE, 'unknown' => AccountKind::UNKNOWN, 'starship' => AccountKind::UNKNOWN] as $type => $kind) {
            self::assertSame($kind, Mapper::kind($type), $type);
        }
    }

    public function testItemStatusesAsConnectionStates(): void
    {
        foreach ([0 => ConnectionState::OK, -2 => ConnectionState::PENDING, 402 => ConnectionState::WRONG_CREDENTIALS, 429 => ConnectionState::ACTION_NEEDED, 1010 => ConnectionState::ACTION_NEEDED, 1003 => ConnectionState::BANK_UNAVAILABLE, 1005 => ConnectionState::BUG, 77777 => ConnectionState::UNKNOWN] as $status => $state) {
            $body = (string) json_encode(['type' => 'item.refreshed', 'content' => ['item_id' => 1, 'status' => $status], 'timestamp' => time()]);
            self::assertSame($state, $this->gateway()->notify($body, ['BridgeApi-Signature' => 'v1='.Api::sign($body, self::SECRET)])->state, (string) $status);
        }

        $body = (string) json_encode(['type' => 'TEST_EVENT', 'content' => [], 'timestamp' => time()]);
        self::assertNull($this->gateway()->notify($body, ['BridgeApi-Signature' => 'v1='.Api::sign($body, self::SECRET)])->state, 'an event about no item');
    }
}

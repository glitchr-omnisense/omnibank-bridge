<?php

namespace Omnibank\Bridge\Tests;

use Omnibank\Bridge\Api;
use Omnibank\Bridge\BridgeGatewayFactory;
use Omnibank\Exception\InvalidNotificationException;
use Omnibank\Exception\ProviderException;
use Omnibank\Exception\RequestNotSupportedException;
use Omnibank\Exception\UnavailableException;
use Omnibank\GatewayInterface;
use Omnibank\Model\Connection;
use Omnibank\Model\Consent;
use Omnibank\Model\ConsentStatus;
use Omnibank\Model\Money;
use Omnibank\Model\Transfer as TransferModel;
use Omnibank\Request\Transfer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BridgeGatewayTest extends TestCase
{
    private const SECRET = 'brdg_whsec';
    private const USER = 'c2a4d0de-9a35-4e4f-8a5a-1f3e2b7c9d10';

    /** @var list<array{string, string, array}> method, url, options */
    private array $calls = [];

    private function gateway(?\Closure $answer = null, array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->calls[] = [$method, $url, $options];
            if ($answer) {
                return $answer($method, $url);
            }
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $after = str_contains($url, 'after=');

            return match (true) {
                'POST' === $method && '/v3/aggregation/users' === $path => new MockResponse(self::fixture('user.json'), ['http_code' => 201]),
                'POST' === $method && '/v3/aggregation/authorization/token' === $path => new MockResponse(self::fixture('token.json')),
                'POST' === $method && '/v3/aggregation/connect-sessions' === $path => new MockResponse(self::fixture('connect-session.json'), ['http_code' => 201]),
                'GET' === $method && '/v3/aggregation/accounts' === $path => new MockResponse(self::fixture('accounts-page-'.($after ? 2 : 1).'.json')),
                'GET' === $method && '/v3/aggregation/accounts/31415926' === $path => new MockResponse(self::fixture('account-31415926.json')),
                'GET' === $method && '/v3/aggregation/transactions' === $path => new MockResponse(self::fixture('transactions-page-'.($after ? 2 : 1).'.json')),
                default => new MockResponse('{"type":"not_found","message":"Not found"}', ['http_code' => 404]),
            };
        });

        return (new BridgeGatewayFactory($http))->create($options + ['client_id' => 'brdg_client', 'client_secret' => 'brdg_secret', 'webhook_secret' => self::SECRET]);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
    }

    private static function connected(?Consent $consent = null): Connection
    {
        return new Connection(['user_uuid' => self::USER, 'external_user_id' => 'nakaya-user-42', 'access_token' => 'brdg_tok_1a2b3c4d5e6f', 'expires_at' => '2099-01-01T12:00:00.000Z'], $consent ?? Consent::active());
    }

    private function bodyOf(int $call): array
    {
        return json_decode((string) $this->calls[$call][2]['body'], true);
    }

    public function testTheFirstConnectCreatesTheUserAndOpensASession(): void
    {
        $connection = new Connection(['external_user_id' => 'nakaya-user-42', 'user_email' => 'camille@example.org']);
        $result = $this->gateway()->connect($connection, 'https://app.example/bank/back');

        self::assertSame('https://connect.bridgeapi.io/session/f81d4fae-7dec-11d0-a765-00a0c91e6bf6', $result->url);
        self::assertSame(self::USER, $result->connection->get('user_uuid'));
        self::assertSame('brdg_tok_1a2b3c4d5e6f', $result->connection->get('access_token'), 'the token kept');
        self::assertSame('2099-01-01T12:00:00.000Z', $result->connection->get('expires_at'));

        self::assertSame(['POST', 'https://api.bridgeapi.io/v3/aggregation/users'], [$this->calls[0][0], $this->calls[0][1]]);
        self::assertSame(['external_user_id' => 'nakaya-user-42'], $this->bodyOf(0));
        self::assertContains('Client-Id: brdg_client', $this->calls[0][2]['headers']);
        self::assertContains('Client-Secret: brdg_secret', $this->calls[0][2]['headers']);
        self::assertContains('Bridge-Version: 2025-01-15', $this->calls[0][2]['headers']);
        self::assertSame(['user_uuid' => self::USER], $this->bodyOf(1));
        self::assertSame(['callback_url' => 'https://app.example/bank/back', 'user_email' => 'camille@example.org', 'country_code' => 'FR'], $this->bodyOf(2));
        self::assertContains('Authorization: Bearer brdg_tok_1a2b3c4d5e6f', $this->calls[2][2]['headers']);
    }

    public function testAConsentToRenewOpensASessionOnItsItem(): void
    {
        $connection = self::connected(Consent::needsRenewal());
        $connection = $connection->withState(['item_id' => '8675309'] + $connection->state);
        $this->gateway(options: ['version' => '2025-06-01'])->connect($connection, 'https://app.example/bank/back');

        self::assertCount(2, $this->calls, 'the user exists: a token, a session');
        self::assertSame(8675309, $this->bodyOf(1)['item_id']);
        self::assertContains('Bridge-Version: 2025-06-01', $this->calls[0][2]['headers']);
    }

    public function testAccountsEveryPageAndBalances(): void
    {
        $gateway = $this->gateway();
        $accounts = $gateway->accounts(self::connected());

        self::assertCount(2, $accounts, 'pagination.next_uri followed');
        self::assertSame('31415926', $accounts[0]->id);
        self::assertSame('FR7630006000011234567890189', $accounts[0]->iban);
        self::assertSame('Compte Courant Pro', $accounts[0]->name);
        self::assertSame('checking', $accounts[0]->type);
        self::assertSame(8675309, $accounts[0]->raw['item_id']);
        self::assertSame('savings', $accounts[1]->type);
        self::assertCount(2, $this->calls, 'the token in the state still holds: no new one');

        $balances = $gateway->balances(self::connected(), $accounts[0]);
        self::assertSame(['booked', 'instant'], array_map(static fn ($b) => $b->type, $balances));
        self::assertSame([1843217, 1810217], array_map(static fn ($b) => $b->amount->amount, $balances));
    }

    public function testAnExpiredTokenIsRenewedForTheCall(): void
    {
        $connection = self::connected()->withState(['user_uuid' => self::USER, 'access_token' => 'old', 'expires_at' => '2020-01-01T00:00:00Z']);
        $this->gateway()->accounts($connection);

        self::assertSame('https://api.bridgeapi.io/v3/aggregation/authorization/token', $this->calls[0][1]);
        self::assertContains('Authorization: Bearer brdg_tok_1a2b3c4d5e6f', $this->calls[1][2]['headers']);
    }

    public function testTransactionsEveryPageBookedOnly(): void
    {
        $gateway = $this->gateway();
        $account = $gateway->accounts(self::connected())[0];
        $this->calls = [];
        $transactions = $gateway->transactions(self::connected(), $account, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));

        self::assertSame(['9000000000001', '9000000000002', '9000000000003'], array_map(static fn ($t) => $t->id, $transactions), 'future and deleted left out');
        [$credit, $edf, $card] = $transactions;
        self::assertTrue($credit->amount->equals(Money::of(125000, 'EUR')));
        self::assertSame('VIR SEPA RECU /DE CAMILLE DURAND /MOTIF FACTURE F-2026-042', $credit->label, 'the bank\'s own description');
        self::assertSame(-4590, $edf->amount->amount);
        self::assertSame('2026-09-08', $card->bookedOn->format('Y-m-d'));
        self::assertSame('2026-09-06', $card->valueOn?->format('Y-m-d'));

        parse_str((string) parse_url($this->calls[0][1], \PHP_URL_QUERY), $query);
        self::assertSame(['account_id' => '31415926', 'since' => '2026-09-01', 'until' => '2026-09-30', 'limit' => '500'], $query);
        self::assertStringContainsString('after=OTAwMDAwMDAwMDAwMg', $this->calls[1][1]);
    }

    public function testNotConnectedSaysSo(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('connect() first');
        $this->gateway()->accounts(new Connection());
    }

    public function testAnItemWebhookCheckedThenRead(): void
    {
        $body = self::fixture('webhook-item-refreshed.json');
        $notification = $this->gateway()->notify($body, ['bridgeapi-signature' => 'v1='.Api::sign($body, 'old-secret').',v1='.Api::sign($body, self::SECRET)]);

        self::assertSame('item.refreshed', $notification->type);
        self::assertSame('8675309', $notification->connectionId);
        self::assertSame(ConsentStatus::NEEDS_RENEWAL, $notification->consent->status, '1010: the strong authentication to renew');
        self::assertSame(self::USER, $notification->raw['content']['user_uuid']);

        foreach ([0 => ConsentStatus::ACTIVE, 402 => ConsentStatus::NEEDS_RENEWAL, 429 => ConsentStatus::NEEDS_RENEWAL, 1003 => ConsentStatus::ACTIVE] as $status => $consent) {
            $body = json_encode(['type' => 'item.refreshed', 'content' => ['item_id' => 1, 'status' => $status]]);
            self::assertSame($consent, $this->gateway()->notify($body, ['BridgeApi-Signature' => 'v1='.Api::sign($body, self::SECRET)])->consent->status, (string) $status);
        }
        $body = json_encode(['type' => 'item.deleted', 'content' => ['item_id' => 1]]);
        self::assertSame(ConsentStatus::REVOKED, $this->gateway()->notify($body, ['BridgeApi-Signature' => 'v1='.strtolower(Api::sign($body, self::SECRET))])->consent->status);
        $body = json_encode(['type' => 'TEST_EVENT', 'content' => []]);
        self::assertSame(ConsentStatus::NONE, $this->gateway()->notify($body, ['BridgeApi-Signature' => 'v1='.Api::sign($body, self::SECRET)])->consent->status);
    }

    public function testAWebhookNotSignedWithTheSecretIsRefused(): void
    {
        $body = self::fixture('webhook-item-refreshed.json');
        $this->expectException(InvalidNotificationException::class);
        $this->gateway()->notify($body, ['BridgeApi-Signature' => 'v1='.Api::sign($body, 'another')]);
    }

    public function testAnOutageIsNeverNoData(): void
    {
        $gateway = $this->gateway(static fn () => new MockResponse('{"type":"internal_error"}', ['http_code' => 500]));
        $this->expectException(UnavailableException::class);
        $gateway->accounts(self::connected());
    }

    public function testNoTransfersInThisVersion(): void
    {
        $gateway = $this->gateway();
        self::assertFalse($gateway->supports(Transfer::class));
        $this->expectException(RequestNotSupportedException::class);
        $gateway->transfer(self::connected(), new TransferModel('FR7630006000011234567890189', 'X', 'FR6010278060410002051020134', null, Money::of(100, 'EUR'), 'x'));
    }
}

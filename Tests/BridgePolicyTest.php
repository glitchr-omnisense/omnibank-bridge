<?php

namespace Omnibank\Bridge\Tests;

use Omnibank\Bridge\BridgeGatewayFactory;
use Omnibank\Exception\RateLimitedException;
use Omnibank\Exception\UsageLimitReachedException;
use Omnibank\Model\Connection;
use Omnibank\Registry;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\Scope;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Bridge's usage policy (3,000 requests per application every 5 minutes, 60
 * per user a minute), its per-user limit counted per Bridge user, and a 429
 * to a connect session: sent once, surfaced, the next calls held unsent.
 */
final class BridgePolicyTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared);
        self::assertSame(['omnibank/bridge', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0]);
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('bridge', $policy->provider);
        $limits = $policy->limitsFor('accounts');
        self::assertSame([3000, 60], array_map(static fn ($l) => $l->max, $limits));
        self::assertSame([300, 60], array_map(static fn ($l) => $l->seconds(), $limits));
        self::assertSame([Scope::Application, Scope::User], array_map(static fn ($l) => $l->scope, $limits));
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
    }

    public function testThePerUserLimitCountsEachBridgeUserApart(): void
    {
        $registry = $this->registry(fn () => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/accounts-page-2.json')), ['rateLimits' => [['max' => 1, 'window' => 'minute', 'scope' => 'user', 'operations' => ['accounts']]]]);
        $gateway = $registry->guarded('bank');

        $gateway->accounts(self::connected('user-a'));
        try {
            $gateway->accounts(self::connected('user-a'));
            self::fail('user-a: one a minute');
        } catch (UsageLimitReachedException) {
        }
        $gateway->accounts(self::connected('user-b'));
        self::assertCount(2, $this->calls, 'user-a once, user-b once');
    }

    public function testA429ToAConnectSessionIsSentOnceAndHoldsTheNextCalls(): void
    {
        $answers = [new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/token.json')), new MockResponse('{"type":"too_many_requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']])];
        $gateway = $this->registry(function () use (&$answers): MockResponse { return array_shift($answers) ?? new MockResponse('{}', ['http_code' => 500]); })->get('bank');

        try {
            $gateway->connect(self::connected('user-a'), 'https://app.example/bank/back');
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(60, $e->retryAfter);
        }
        self::assertSame(['POST /v3/aggregation/authorization/token', 'POST /v3/aggregation/connect-sessions'], $this->calls, 'the session asked for once, never again');

        $this->expectException(UsageLimitReachedException::class);
        try {
            $gateway->accounts(self::connected('user-a'));
        } finally {
            self::assertCount(2, $this->calls, 'held, not sent');
        }
    }

    /**
     * @param \Closure(): MockResponse $answer
     * @param array<string, mixed>     $policy
     */
    private function registry(\Closure $answer, array $policy = []): Registry
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($answer): MockResponse {
            $this->calls[] = $method.' '.parse_url($url, \PHP_URL_PATH);

            return $answer();
        });

        return new Registry([new BridgeGatewayFactory($http)], ['bank' => ['factory' => 'bridge', 'options' => ['client_id' => 'brdg_client', 'client_secret' => 'brdg_secret'], 'policy' => $policy]]);
    }

    private static function connected(string $user): Connection
    {
        return new Connection(['user_uuid' => $user, 'access_token' => 'brdg_test_not_a_real_token', 'expires_at' => '2099-01-01T12:00:00.000Z']);
    }
}

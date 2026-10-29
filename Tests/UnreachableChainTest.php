<?php

namespace Omnibank\Bridge\Tests;

use Omnibank\Bridge\Api;
use Omnibank\Exception\ProviderException;
use Omnibank\Model\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Nothing of Bridge's credentials anywhere in what a log of a failed call writes: the messages,
 * the previous exception's (a copy of the HTTP client's, the secrets masked) and the traces'
 * arguments, which PHP keeps unless zend.exception_ignore_args is on.
 */
final class UnreachableChainTest extends TestCase
{
    private const SECRETS = ['bridge-client-secret-5a1e72', 'bridge-access-token-9c4d08', 'bridge-webhook-3f6b21'];

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', false !== $this->ignoreArgs ? $this->ignoreArgs : '1');
    }

    /** @return iterable<string, array{\Closure(Api): mixed}> */
    public static function calls(): iterable
    {
        yield 'accounts' => [static fn (Api $api): mixed => $api->call('GET', '/v3/aggregation/accounts', 'bridge-access-token-9c4d08')];
        yield 'user' => [static fn (Api $api): mixed => $api->createUser('customer-1')];
        yield 'token' => [static fn (Api $api): mixed => $api->token(new Connection(['user_uuid' => 'u-1', 'access_token' => 'bridge-access-token-9c4d08', 'expires_at' => '2000-01-01T00:00:00Z']))];
    }

    #[DataProvider('calls')]
    public function testNoPartOfAnUnreachableChainHoldsTheSecrets(\Closure $call): void
    {
        self::assertClean($call, static function (string $method, string $url): never {
            throw new TransportException(\sprintf('Could not resolve host: x for "%s".', $url), 6);
        });
    }

    #[DataProvider('calls')]
    public function testAnErrorAnswerKeepsNoSecretInTheTraces(\Closure $call): void
    {
        self::assertClean($call, static fn (string $method, string $url): MockResponse => new MockResponse('{"message":"down"}', ['http_code' => 500]));
    }

    private static function assertClean(\Closure $call, \Closure $responses): void
    {
        $api = new Api(new MockHttpClient($responses), 'bridge-client-id', 'bridge-client-secret-5a1e72', Api::VERSION, 'bridge-webhook-3f6b21');
        try {
            $call($api);
            self::fail('no exception');
        } catch (ProviderException $e) {
            self::assertNotEmpty($e->getTrace());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                // A boolean, not assertStringNotContainsString(): a trace with its arguments is too large to export.
                $trace = print_r($one->getTrace(), true).$one->getTraceAsString();
                foreach (self::SECRETS as $secret) {
                    self::assertStringNotContainsString($secret, $one->getMessage());
                    self::assertFalse(str_contains($trace, $secret), \sprintf('The trace of %s holds "%s".', $one::class, $secret));
                }
            }
        }
    }
}

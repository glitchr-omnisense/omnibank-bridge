<?php

namespace Omnibank\Bridge;

use Omnibank\Exception\InvalidNotificationException;
use Omnibank\Http\Redaction;
use Omnibank\Exception\ProviderException;
use Omnibank\Exception\UnavailableException;
use Omnibank\Model\Connection;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bridge API v3 (bridgeapi.io): every call with the application's Client-Id,
 * Client-Secret and Bridge-Version; a user's with the user's access token
 * too (Bearer, about two hours: a new one is asked when it is past).
 *
 * Webhooks: Bridge signs them in BridgeApi-Signature, "v1=<HMAC-SHA256 of
 * the body with the webhook secret, hex>", several comma-separated while a
 * secret is rotated. That reading is an assumption until checked against a
 * real sandbox webhook.
 */
final class Api
{
    public const HOST = 'https://api.bridgeapi.io';
    public const VERSION = '2025-01-15';
    public const SIGNATURE_HEADER = 'BridgeApi-Signature';

    /** How far a webhook's timestamp may be from now, in seconds (the webhook_tolerance option). */
    public const WEBHOOK_TOLERANCE = 300;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $version = self::VERSION,
        private readonly ?string $webhookSecret = null,
        private readonly string $host = self::HOST,
        private readonly int $timeout = 30,
        private readonly int $webhookTolerance = self::WEBHOOK_TOLERANCE,
    ) {
    }

    /** @return array{uuid: string, external_user_id: ?string} */
    public function createUser(string $externalUserId): array
    {
        $data = $this->call('POST', '/v3/aggregation/users', null, ['external_user_id' => $externalUserId]);
        if (!\is_string($data['uuid'] ?? null) || '' === $data['uuid']) {
            throw Redaction::withoutArguments(new ProviderException('bridge', 'Bridge gave no user.'));
        }

        return ['uuid' => $data['uuid'], 'external_user_id' => $data['external_user_id'] ?? $externalUserId];
    }

    /** @return array{access_token: string, expires_at: ?string} */
    public function authorize(string $userUuid): array
    {
        $data = $this->call('POST', '/v3/aggregation/authorization/token', null, ['user_uuid' => $userUuid]);
        if (!\is_string($data['access_token'] ?? null) || '' === $data['access_token']) {
            throw Redaction::withoutArguments(new ProviderException('bridge', 'Bridge gave no access token.'));
        }

        return ['access_token' => $data['access_token'], 'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null];
    }

    /**
     * The user's access token: the one in the connection's state while it
     * holds (a minute to spare), a new one for its user otherwise.
     */
    public function token(Connection $connection): string
    {
        $token = $connection->get('access_token');
        $expires = $connection->get('expires_at');
        if (\is_string($token) && '' !== $token && \is_string($expires) && new \DateTimeImmutable($expires) > new \DateTimeImmutable('+1 minute')) {
            return $token;
        }
        $user = $connection->get('user_uuid');
        if (!\is_string($user) || '' === $user) {
            throw Redaction::withoutArguments(new ProviderException('bridge', 'The connection has no Bridge user yet: connect() first.'));
        }

        return $this->authorize($user)['access_token'];
    }

    /**
     * @param array<string, mixed>|null $body JSON
     *
     * @return array<mixed>
     *
     * @throws ProviderException   when Bridge refuses (4xx)
     * @throws UnavailableException when Bridge cannot be reached or fails (network, 5xx, 429)
     */
    public function call(string $method, string $path, ?string $token = null, ?array $body = null, array $query = []): array
    {
        $url = str_starts_with($path, 'https://') ? $path : rtrim($this->host, '/').'/'.ltrim($path, '/');
        if (parse_url($url, \PHP_URL_HOST) !== parse_url($this->host, \PHP_URL_HOST)) {
            throw Redaction::withoutArguments(new ProviderException('bridge', \sprintf('A link out of Bridge (%s) is not followed: the credentials stay home.', Redaction::text($url))));
        }
        $options = array_filter([
            'headers' => array_filter([
                'Accept' => 'application/json',
                'Client-Id' => $this->clientId,
                'Client-Secret' => $this->clientSecret,
                'Bridge-Version' => $this->version,
                'Authorization' => null !== $token ? 'Bearer '.$token : null,
            ]),
            'query' => $query,
            'json' => $body,
            'timeout' => $this->timeout,
        ], static fn ($v) => null !== $v && [] !== $v);
        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            $secrets = [...Redaction::secrets($url, $options), $this->clientSecret];

            throw Redaction::withoutArguments(new UnavailableException('bridge', 'Bridge cannot be reached: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if ($status >= 500 || 429 === $status) {
            throw Redaction::withoutArguments(new UnavailableException('bridge', \sprintf('Bridge answered HTTP %d: try again later.', $status), (string) $status));
        }
        $data = '' === $content ? [] : json_decode($content, true);
        if (!\is_array($data)) {
            throw Redaction::withoutArguments(new UnavailableException('bridge', \sprintf('Bridge answered HTTP %d with a body that is not JSON.', $status), (string) $status));
        }
        if ($status >= 400) {
            $error = $data['errors'][0] ?? $data;
            throw Redaction::withoutArguments(new ProviderException('bridge', (string) ($error['message'] ?? \sprintf('HTTP %d', $status)), isset($error['code']) ? (string) $error['code'] : (isset($data['type']) ? (string) $data['type'] : (string) $status)));
        }

        return $data;
    }

    /** @throws InvalidNotificationException */
    public function verify(string $body, ?string $header): void
    {
        if (null === $this->webhookSecret || '' === $this->webhookSecret) {
            throw new InvalidNotificationException('bridge', 'No webhook secret: the notification cannot be checked.');
        }
        $expected = self::sign($body, $this->webhookSecret);
        foreach (explode(',', (string) $header) as $part) {
            $part = trim($part);
            $signature = str_contains($part, '=') ? explode('=', $part, 2)[1] : $part;
            if ('' !== $signature && hash_equals($expected, strtoupper(trim($signature)))) {
                $this->checkAge($body);

                return;
            }
        }

        throw new InvalidNotificationException('bridge', 'The notification is unsigned, or its signature does not match.');
    }

    /**
     * The event's "timestamp" (Unix seconds, inside the signed body) no further from now than the
     * tolerance: a webhook captured and sent again is refused.
     *
     * @throws InvalidNotificationException
     */
    private function checkAge(string $body): void
    {
        $event = json_decode($body, true);
        $timestamp = \is_array($event) ? ($event['timestamp'] ?? null) : null;
        if (!\is_int($timestamp) && !(\is_string($timestamp) && ctype_digit($timestamp))) {
            throw new InvalidNotificationException('bridge', 'The notification has no timestamp: its age cannot be checked.');
        }
        if (abs(time() - (int) $timestamp) > $this->webhookTolerance) {
            throw new InvalidNotificationException('bridge', \sprintf('The notification is too old (event at %s, more than %d seconds from now): a webhook sent again is refused.', gmdate('Y-m-d H:i:s', (int) $timestamp).' UTC', $this->webhookTolerance));
        }
    }

    /** The signature Bridge would send for this body (upper-case hex), without its "v1=". */
    public static function sign(string $body, string $secret): string
    {
        return strtoupper(hash_hmac('sha256', $body, $secret));
    }
}

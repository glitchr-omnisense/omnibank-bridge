<?php

namespace Omnibank\Bridge\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Bridge\Api;
use Omnibank\Exception\InvalidConfigException;
use Omnibank\Exception\ProviderException;
use Omnibank\Model\ConnectResult;
use Omnibank\Model\ConsentStatus;
use Omnibank\Request\Connect;
use Omnibank\Request\Request;

/**
 * A Bridge Connect session's page. The first time, a Bridge user is created
 * (POST /v3/aggregation/users, its external_user_id the state's, or one made
 * up) and kept in the state ("user_uuid", "external_user_id"); each time, a
 * token (POST /v3/aggregation/authorization/token, kept: "access_token",
 * "expires_at") opens a session (POST /v3/aggregation/connect-sessions) with
 * the return URL as callback, the state's "user_email" when it has one, and -
 * when the consent needs renewal and the state names the item ("item_id") -
 * that item, for the user to authenticate again.
 */
final class ConnectAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct(private readonly ?string $callbackUrl = null, private readonly ?string $countryCode = null)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Connect;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Connect);
        $callback = '' !== $request->returnUrl ? $request->returnUrl : ($this->callbackUrl ?? throw new InvalidConfigException('The "bridge" gateway needs a return URL: connect()\'s, or its "callback_url" option.'));

        $state = $request->connection->state;
        if (!\is_string($state['user_uuid'] ?? null) || '' === $state['user_uuid']) {
            $external = \is_string($state['external_user_id'] ?? null) && '' !== $state['external_user_id'] ? $state['external_user_id'] : 'omnibank-'.bin2hex(random_bytes(12));
            $user = $this->api->createUser($external);
            $state['user_uuid'] = $user['uuid'];
            $state['external_user_id'] = $user['external_user_id'];
            unset($state['access_token'], $state['expires_at']);
        }
        $token = $this->api->authorize($state['user_uuid']);
        $state['access_token'] = $token['access_token'];
        $state['expires_at'] = $token['expires_at'];

        $renew = ConsentStatus::NEEDS_RENEWAL === $request->connection->consent->status && isset($state['item_id']) && '' !== (string) $state['item_id'];
        $session = $this->api->call('POST', '/v3/aggregation/connect-sessions', $token['access_token'], array_filter([
            'callback_url' => $callback,
            'user_email' => $state['user_email'] ?? null,
            'country_code' => $renew ? null : $this->countryCode,
            'item_id' => $renew ? (int) $state['item_id'] : null,
        ], static fn ($v) => null !== $v && '' !== $v));
        $url = $session['url'] ?? null;
        if (!\is_string($url) || '' === $url) {
            throw new ProviderException('bridge', 'Bridge gave no connect session URL.');
        }

        $request->setResult(new ConnectResult($url, $request->connection->withState($state)));
    }
}

<?php

namespace Omnibank\Bridge;

use Omnibank\Bridge\Action\AccountsAction;
use Omnibank\Bridge\Action\BalancesAction;
use Omnibank\Bridge\Action\ConnectAction;
use Omnibank\Bridge\Action\NotifyAction;
use Omnibank\Bridge\Action\TransactionsAction;
use Omnibank\Config;
use Omnibank\Exception\InvalidConfigException;
use Omnibank\GatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Bridge (bridgeapi.io, by Perspecteev), its API v3: the user connects their
 * banks in a Bridge Connect session; accounts, balances and transactions are
 * read with the user's access token; item.* webhooks say when the data is
 * fresh or the consent must be renewed. The connection's state keeps the
 * Bridge user (user_uuid, external_user_id) and its last token. No
 * transfers in this version.
 *
 *   options:
 *     client_id: '%env(BRIDGE_CLIENT_ID)%'
 *     client_secret: '%env(BRIDGE_CLIENT_SECRET)%'
 *     version: '2025-01-15'                           # Bridge-Version
 *     webhook_secret: '%env(BRIDGE_WEBHOOK_SECRET)%'  # for notify()
 *     callback_url: null                              # when connect() is given no return URL
 *     country_code: FR                                # the banks the connect session lists
 */
final class BridgeGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibank.factory_name' => 'bridge',
            'omnibank.factory_title' => 'Bridge',
            'omnibank.required_options' => ['client_id', 'client_secret'],
            'version' => Api::VERSION,
            'webhook_secret' => null,
            'callback_url' => null,
            'country_code' => 'FR',
            'host' => Api::HOST,
            'omnibank.api' => fn (Config $c) => new Api(
                $this->http ?? (class_exists(HttpClient::class) ? HttpClient::create() : throw new InvalidConfigException('The "bridge" gateway needs an HTTP client: symfony/http-client.')),
                (string) $c['client_id'],
                (string) $c['client_secret'],
                (string) ($c['version'] ?: Api::VERSION),
                $c['webhook_secret'] ?: null,
                (string) ($c['host'] ?: Api::HOST),
            ),
            'omnibank.action.connect' => static fn (Config $c) => new ConnectAction($c['callback_url'] ?: null, $c['country_code'] ?: null),
            'omnibank.action.accounts' => new AccountsAction(),
            'omnibank.action.balances' => new BalancesAction(),
            'omnibank.action.transactions' => new TransactionsAction(),
            'omnibank.action.notify' => new NotifyAction(),
        ]);
    }
}

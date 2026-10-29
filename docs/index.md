# omnibank/bridge

[Bridge](https://bridgeapi.io) (by Perspecteev) for
[omnisense/omnibank](https://github.com/glitchr-omnisense/omnibank), its API v3: the user connects
their banks in a Bridge Connect session; their accounts, balances and transactions are read with
the user's access token; `item.*` webhooks say when the data is fresh or the consent must be
renewed. The gateway calls, on `https://api.bridgeapi.io`:

| Operation | Path |
|---|---|
| a new user (first `connect()`) | `POST /v3/aggregation/users` |
| the user's token | `POST /v3/aggregation/authorization/token` |
| the Connect session (each `connect()`) | `POST /v3/aggregation/connect-sessions` |
| accounts | `GET /v3/aggregation/accounts` |
| balances | `GET /v3/aggregation/accounts/{id}` |
| transactions | `GET /v3/aggregation/transactions` |

Every call sends `Client-Id`, `Client-Secret` and `Bridge-Version` (`2025-01-15` by default); a
user's call `Authorization: Bearer <access token>` too; 30-second timeout.

> [!WARNING]
> Unverified: written from Bridge's published v3 documentation and recorded answers (`Tests/Fixtures`); not yet run against a real Bridge sandbox - the webhook signature above all.

## Installation

```sh
composer require omnisense/omnibank omnibank/bridge
```

Requires PHP 8.2, `omnisense/omnibank` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnibank\Bridge\Symfony\OmnibankBundle`, the factory is registered on the
application's `http_client` as soon as the package is installed. (`Omnibank\Bridge\` is this
package's namespace; the core's Symfony bundle lives in `Omnibank\Bridge\Symfony\`, a different
directory of another package.)

## Credentials

In Bridge's dashboard (dashboard.bridgeapi.io): a sandbox application's **client_id** and
**client_secret**, its callback URL registered, and a webhook on the `item.*` events with its
**secret**.

## Options

`new BridgeGatewayFactory(?HttpClientInterface $http = null)` (none given: `HttpClient::create()`);
options of `create()`:

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | |
| `client_secret` | string | - | yes | |
| `version` | string | `2025-01-15` | no | the `Bridge-Version` header |
| `webhook_secret` | ?string | `null` | for `notify()` | |
| `webhook_tolerance` | int | `300` | no | the seconds a webhook's `timestamp` may be from now |
| `callback_url` | ?string | `null` | no | the return URL when `connect()` is given an empty one |
| `country_code` | ?string | `FR` | no | the banks a new Connect session lists (not sent for a renewal) |
| `host` | string | `https://api.bridgeapi.io` | no | |

The connection's state (kept by the application): `user_uuid`, `external_user_id`, `access_token`,
`expires_at` (set by `connect()`); `external_user_id` (the application's id for the user, given
before the first `connect()`), `user_email` (sent to the session) and `item_id` (to renew one bank
connection) put there by the application.

## Capabilities

| Request | Supported |
|---|---|
| `Connect` | yes: a Connect session's page |
| `FetchAccounts` | yes |
| `FetchBalances` | yes |
| `FetchTransactions` | yes |
| `Transfer` | no, in this version |
| `Notify` | yes: `item.*` events |

## Behaviour

**Connect.** Without a `user_uuid` in the state, `POST /v3/aggregation/users` creates the Bridge
user, its `external_user_id` the state's or one made up (`omnibank-<24 hex>`); `user_uuid` and
`external_user_id` go in the state. Each time, a token is asked
(`POST /v3/aggregation/authorization/token`, kept as `access_token` and `expires_at`), then
`POST /v3/aggregation/connect-sessions` with `callback_url` (the return URL, else the option),
`user_email` when the state has one, and `country_code` - or, when the consent is NEEDS_RENEWAL and
the state has an `item_id`, that `item_id` instead, for the user to authenticate that bank again.
The result's URL is the session's `url`; a session without one is a `ProviderException`. The consent
is not changed by `connect()`.

**The token.** A user's call uses the state's `access_token` while `expires_at` is more than a minute
away; otherwise a new token is asked for the call. The new token is not written back into the
connection (the gateway returns none from a read), so after its expiry every call asks one; call
`connect()` again, or keep a token of the application's, to avoid it.

**Accounts.** Every page (`pagination.next_uri`, up to 100 pages): `id`, `iban`, `bic`, `name`,
`currency_code` (EUR by default), `type`; the account whole in `raw`.

**Balances.** `GET /v3/aggregation/accounts/{id}`: `balance` as `booked`, `instant_balance` as
`instant`, at `updated_at`.

**Transactions.** `account_id`, `since` / `until` the days asked, `limit=500`, every page
(`pagination.next_uri`, up to 1000). A link to another host than Bridge's is refused
(`ProviderException`), so the credentials are never sent elsewhere. Lines of another account,
`future` or `deleted` ones, or without `id`, `amount` or a date are left out; the others kept
between the days asked, each once by id, oldest first: `bookedOn` `booking_date` (else `date`,
`transaction_date`), `valueOn` `value_date`, `amount` signed by Bridge in the line's
`currency_code`, `label` `provider_description` (else `clean_description`); no counterparty, no
reference; `raw` the line.

**Webhooks.** [notify()](https://github.com/glitchr-omnisense/omnibank/blob/1.x/docs/usage-webhooks.md):
the signature checked first (`BridgeApi-Signature`, `v1=<hex HMAC-SHA256 of the body>`, several
comma-separated, the hex in either case), then the event read: `type` the event's (an event without
a `type` is a `ProviderException`), `connectionId` `content.item_id`. The consent, for `item.*`
events: a `content.status` 402 (credentials), 429 (an action on the bank's site), 430 (a password
to change), 1010 (SCA to renew) or 1100 (a professional account to validate) is NEEDS_RENEWAL;
without a numeric status, `status_code_info` mentioning SCA, credential, password, action, renew,
validat(ion) or expired is NEEDS_RENEWAL; an `item.*.deleted` event (not an account's) is REVOKED;
anything else ACTIVE. Other events: NONE.

A webhook whose `timestamp` (the event's time in Unix seconds, inside the signed body) is more
than `webhook_tolerance` seconds from now, or that has none, is refused (`InvalidNotificationException`,
"too old"): one captured and sent again is not read twice.

## Example

On the answers the package's tests keep, each request printed (with its JSON body):

```php
<?php // bridge.php

require __DIR__.'/vendor/autoload.php';

use Omnibank\Bridge\BridgeGatewayFactory;
use Omnibank\Model\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// The answers omnibank/bridge's tests keep, written from Bridge's v3 documentation; each request printed
$fixtures = '/ws/bridge/Tests/Fixtures';   // the package's Tests/Fixtures, here in the family's workspace
$http = new MockHttpClient(function (string $method, string $url, array $options) use ($fixtures) {
    echo '  ', $method, ' ', urldecode($url), isset($options['body']) ? ' '.$options['body'] : '', "\n";
    $path = parse_url($url, PHP_URL_PATH);
    $next = str_contains($url, 'after=');

    return new MockResponse(file_get_contents($fixtures.'/'.match ($path) {
        '/v3/aggregation/users' => 'user.json',
        '/v3/aggregation/authorization/token' => 'token.json',
        '/v3/aggregation/connect-sessions' => 'connect-session.json',
        '/v3/aggregation/accounts' => $next ? 'accounts-page-2.json' : 'accounts-page-1.json',
        '/v3/aggregation/transactions' => $next ? 'transactions-page-2.json' : 'transactions-page-1.json',
    }));
});
$bridge = (new BridgeGatewayFactory($http))->create(['client_id' => 'id', 'client_secret' => 'secret']);

echo "connect:\n";
$result = $bridge->connect(new Connection(['external_user_id' => 'nakaya-user-42']), 'https://app.example/bank/back');
echo '  redirect to ', $result->url, "\n";
$connection = $result->connection;
echo '  keep ', implode(', ', array_keys($connection->state)), "\n";

echo "accounts:\n";
foreach ($bridge->accounts($connection) as $account) {
    echo '  ', $account->id, ' ', $account->name, ' ', $account->type, "\n";
}
echo "transactions:\n";
foreach ($bridge->transactions($connection, $account = $bridge->accounts($connection)[0]) as $t) {
    printf("  %s %9s  %s\n", $t->bookedOn->format('Y-m-d'), $t->amount->decimal(), $t->label);
}
```

```
$ php bridge.php
connect:
  POST https://api.bridgeapi.io/v3/aggregation/users {"external_user_id":"nakaya-user-42"}
  POST https://api.bridgeapi.io/v3/aggregation/authorization/token {"user_uuid":"c2a4d0de-9a35-4e4f-8a5a-1f3e2b7c9d10"}
  POST https://api.bridgeapi.io/v3/aggregation/connect-sessions {"callback_url":"https:\/\/app.example\/bank\/back","country_code":"FR"}
  redirect to https://connect.bridgeapi.io/session/f81d4fae-7dec-11d0-a765-00a0c91e6bf6
  keep external_user_id, user_uuid, access_token, expires_at
accounts:
  GET https://api.bridgeapi.io/v3/aggregation/accounts
  GET https://api.bridgeapi.io/v3/aggregation/accounts?after=MzE0MTU5MjY&limit=1
  31415926 Compte Courant Pro checking
  27182818 Livret A savings
transactions:
  GET https://api.bridgeapi.io/v3/aggregation/accounts
  GET https://api.bridgeapi.io/v3/aggregation/accounts?after=MzE0MTU5MjY&limit=1
  GET https://api.bridgeapi.io/v3/aggregation/transactions?account_id=31415926&limit=500
  GET https://api.bridgeapi.io/v3/aggregation/transactions?account_id=31415926&after=OTAwMDAwMDAwMDAwMg&limit=2
  2026-09-02   1250.00  VIR SEPA RECU /DE CAMILLE DURAND /MOTIF FACTURE F-2026-042
  2026-09-05    -45.90  PRLV SEPA EDF CLIENT 12345
  2026-09-08    -23.40  CB CARREFOUR CITY 06/09
```

(run on 2026-10-26, PHP 8.4.27, no network; the user, the accounts and the amounts are made up)

In Symfony:

```yaml
omnibank:
    gateways:
        banks:
            factory: bridge
            options:
                client_id: '%env(BRIDGE_CLIENT_ID)%'
                client_secret: '%env(BRIDGE_CLIENT_SECRET)%'
                webhook_secret: '%env(BRIDGE_WEBHOOK_SECRET)%'
                callback_url: 'https://app.example/bank/back'
```

## Errors

| Case | Exception |
|---|---|
| a transport failure, HTTP 5xx, 429, a body that is not JSON | `UnavailableException` (`[bridge] Bridge answered HTTP 503: try again later.`) |
| another 4xx | `ProviderException`, the message `errors[0].message` (else the body's `message`), `providerCode` its `code`, else the body's `type`, else the status |
| no `user_uuid` in the state for a user's call | `ProviderException` (`The connection has no Bridge user yet: connect() first.`) |
| Bridge gives no user, no token, no session URL | `ProviderException` |
| a pagination link out of Bridge's host | `ProviderException` |
| a webhook without a secret configured, unsigned or with a wrong signature | `InvalidNotificationException` |
| missing `client_id` or `client_secret`; no return URL | `InvalidConfigException` |
| `transfer()` | `RequestNotSupportedException` |

No failure carries the client secret and the user's access token: a transport failure's message has
them masked as `***` (the key parameters of a URL too), its previous is a copy of the HTTP client's
exception (the same class and code), and no trace of the chain keeps its arguments, where the
request's options and the token stood ([No secret in a
failure](https://github.com/glitchr-omnisense/omnibank/blob/1.x/docs/errors.md#no-secret-in-a-failure)).

## Verified, and not

- Verified (the package's tests, 10, green on 2026-10-26 in the family's workspace): the first
  connect creates the user and opens a session; a consent to renew opens a session on its item;
  accounts every page and balances; an expired token renewed for the call; transactions every page,
  booked only; not connected says so; an item webhook checked then read, one not signed with the
  secret refused; an outage never "no data"; no transfers.
- Not verified: against a real Bridge sandbox; the webhook signature as Bridge really sends it.

## Responsibility

This package is an independent, unofficial client, not affiliated with, endorsed by or sponsored by
Perspecteev SAS (Bridge). Whoever runs it is responsible for complying with Bridge's terms
([Bridge API terms of use](https://dashboard.bridgeapi.io/assets/pdf/cgu_EN.pdf)) and for what is
done with what it returns; see the README's Responsibility section.

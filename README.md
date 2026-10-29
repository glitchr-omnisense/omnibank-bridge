# omnibank/bridge

Bridge (bridgeapi.io, by Perspecteev) for [omnisense/omnibank](https://github.com/glitchr-omnisense/omnibank),
its API v3: the user connects their banks in a Bridge Connect session; their accounts, balances
and transactions are read with the user's access token; `item.*` webhooks say when the data is
fresh or the consent must be renewed.

> **Unverified.** Written from Bridge's published v3 documentation and recorded answers
> (`Tests/Fixtures`); it has not yet been run against a real Bridge sandbox. Try it with real keys
> (`docker compose run --rm omnibank connect bridge` in `omnisense/omnibank`'s `docker/`) before
> relying on it, and drop this notice once it holds - the webhook signature above all.

```php
$gateway = (new BridgeGatewayFactory($http))->create(['client_id' => '...', 'client_secret' => '...']);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `omnisense/omnibank` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibank:
    gateways:
        banks:
            factory: bridge
            options:
                client_id: '%env(BRIDGE_CLIENT_ID)%'
                client_secret: '%env(BRIDGE_CLIENT_SECRET)%'
                version: '2025-01-15'                            # Bridge-Version
                webhook_secret: '%env(BRIDGE_WEBHOOK_SECRET)%'   # for notify()
                callback_url: 'https://app.example/bank/back'    # when connect() is given no return URL
                country_code: FR
```

Every call carries `Client-Id`, `Client-Secret` and `Bridge-Version`; a user's, the user's token.

- `connect()` - the first time, `POST /v3/aggregation/users` creates the Bridge user (its
  `external_user_id` the state's, or one made up); `POST /v3/aggregation/authorization/token`
  gives its token; `POST /v3/aggregation/connect-sessions` (the return URL as `callback_url`, the
  state's `user_email` when there is one) gives the page. The state keeps `user_uuid`,
  `external_user_id`, `access_token`, `expires_at`: **keep the connection**. When the consent is
  NEEDS_RENEWAL and the state has `item_id`, the session is for that item.
- `accounts()` - `GET /v3/aggregation/accounts`, every page; `balances()` -
  `GET /v3/aggregation/accounts/{id}`: `balance` (`booked`), `instant_balance` (`instant`).
- `transactions()` - `GET /v3/aggregation/transactions?account_id=&since=&until=&limit=`, every
  page (`pagination.next_uri`); future and deleted transactions left out; `provider_description`
  is the label. A token past its `expires_at` is renewed for the call.
- `notify()` - `item.*` events: the item (`content.item_id`) as `connectionId`; a status the user
  must act on - 402 (credentials), 429 (action on the bank's site), 430 (password), 1010 (SCA to
  renew), 1100 (pro account to validate) - is NEEDS_RENEWAL, a deleted item REVOKED, any other
  status ACTIVE. Other events come back with consent NONE.
- No `transfer()` in this version.

**Webhook signature - an assumption.** This package reads `BridgeApi-Signature` as
`v1=<HMAC-SHA256 of the raw body with the webhook secret, hex>`, several comma-separated while a
secret is rotated, the hex in either case, and refuses an event whose `timestamp` is more than
`webhook_tolerance` seconds (300) from now, or that has none. Check it against a real sandbox webhook.

A 5xx, a 429 or a network failure is an `UnavailableException`, never an empty list.

Credentials: in Bridge's dashboard (dashboard.bridgeapi.io), a sandbox application's
**client_id** and **client_secret**, its callback URL registered, and a webhook on the `item.*`
events with its **secret**.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Perspecteev SAS (Bridge). It reaches their service on behalf of whoever runs it, and it
does not redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT
licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Bridge's terms ([Bridge API
terms of use](https://dashboard.bridgeapi.io/assets/pdf/cgu_EN.pdf)), for obtaining whatever they
require (an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.

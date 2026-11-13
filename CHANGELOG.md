# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Release
- Requires `omnisense/contracts` `^1.0` and `omnisense/omnibank` `^1.0`, stable versions, where it
  required `@dev` ones; `extra.branch-alias` `dev-1.x` → `1.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml: the package installed alone,
  PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml` (that CI on the tag,
  then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the package's own tests.
- README: the install line and a first call on a recorded answer (no account, nothing sent).

### Changed

- On omnisense/contracts: its usage policy as data and Bridge's waits honoured: `policy.json` (3,000 requests per application every 5 minutes, 60 per user a minute; read 2026-11-10), named in `composer.json`'s `extra.omnisense.policy`; `Api` takes the gateway's `Backoff`: no call while Bridge's wait lasts, a 429 a `RateLimitedException` with its `Retry-After`, never retried - a user or a Connect session is asked for once. The limits per user count per the connection's `user_uuid`. Requires omnisense/contracts. `phpstan.neon` at level max, `phpstan-baseline.neon` what was there (76 errors). `Tests/BridgePolicyTest`.

### Documentation

- `docs/index.md`: the webhook is optional and set in the dashboard: Bridge documents webhooks in its dashboard only, so the
  gateway answers `supports(RegisterWebhook::class)` false (omnisense/omnibank's new optional
  capability); a test says so.

### Added

- Bridge's words mapped onto omnisense/omnibank's (docs.bridgeapi.io): its account types onto
  `AccountKind` (`Account::$kind`), its item statuses onto `ConnectionState`
  (`Notification::$state`), its category ids - a child, else its parent - and else its operation
  types onto `TransactionCategory` (`BankTransaction::$category`). `Mapper::ACCOUNT_KINDS`,
  `CONNECTION_STATES`, `CATEGORIES`, `PARENTS`, `OPERATION_CATEGORIES`, `kind()`,
  `connectionState()`, `category()`. A word not published is `UNKNOWN`.

### Fixed

- Back from the Connect session, `connect()` finishes the connection instead of opening a new
  session: the state's `return` (the callback's query, put there by the application) with
  `success=true` keeps its `item_id`, makes the consent active and gives no URL; `success=false` is a
  `ProviderException` (`providerCode` the `step`). omnibase/ledger's return route called `connect()`
  with that query and was sent to a new session, every time.
- An `UnavailableException` no longer carries the client secret and the user's access token. The
  message quoted the HTTP client's (the whole URL), the previous was the client's own exception, and
  every trace of the chain kept the arguments of the calls under it (the request's options, the
  headers with the credentials, the token given to `call()`) when `zend.exception_ignore_args` is
  off: a log of the whole chain wrote them. The message masks them as `***`, the previous is a copy
  of the client's exception (the same class and code), and no exception `Api` throws keeps its
  trace's arguments (`Omnibank\Http\Redaction`).
- `notify()` refuses an event whose `timestamp` (inside the signed body) is more than
  `webhook_tolerance` seconds (new option, 300 by default) from now, or that has none: a captured
  webhook could be sent again. Unverified live: written from Bridge's documentation.

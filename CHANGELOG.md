# Changelog

## [Unreleased]

### Fixed

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

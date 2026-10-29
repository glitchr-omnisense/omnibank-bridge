# Changelog

## [Unreleased]

### Fixed

- `notify()` refuses an event whose `timestamp` (inside the signed body) is more than
  `webhook_tolerance` seconds (new option, 300 by default) from now, or that has none: a captured
  webhook could be sent again. Unverified live: written from Bridge's documentation.

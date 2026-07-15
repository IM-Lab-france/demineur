# Changelog

## [Unreleased] - 2026-07-15

### Added

- Persistent `active_games` snapshots and recovery after a WebSocket restart.
- Daily backups and weekly automated restore verification.
- Guided MySQL secret-rotation script.
- Isolated systemd template for each AI with CPU and memory limits.
- Runtime supervision for players, games, reconnects, uptime and backup timers.
- Weekly, monthly and all-time ranking filters.
- Playwright UI tests and an optional two-player WebSocket test.
- Mobile board scrolling, connection status and additional accessibility labels.
- Persistent hashed WebSocket sessions with expiry and revocation.
- Batched post-response move history writes and SQL latency metrics.
- Per-administrator TOTP MFA with a locally generated Authenticator QR Code and activation check.
- Secure CLI-only installer and one-command upgrade finalizer.
- Automated two-player invitation and first-move integration test.
- Encrypted off-site backup support through `age`.
- One-time MFA recovery codes and encrypted per-administrator TOTP secrets.
- Persistent database-backed throttling for administration logins.
- Hourly systemd health checks surfaced in the administration dashboard.
- Verified e-mail registration, verification resend and secure password recovery.
- Hashed, expiring and single-use account tokens with session revocation.

### Changed

- Moved administration and AI-management JavaScript into local static files.
- Tightened CSP by removing `unsafe-inline` from scripts and styles.
- Replaced inline presentation with CSS classes and native progress elements.
- Extended CI with npm auditing and browser tests.
- Updated Playwright and `ws` to audited versions.
- Updated Composer dependencies, including patched Symfony and Guzzle releases.
- Replaced IA pickle memory with bounded, atomically written JSON.
- Differentiated easy, medium and hard AI behavior with a decision timeout.
- Unified Apache/PHP and systemd database configuration after secret rotation.
- Extracted administration login throttling into a dedicated repository component.
- Added SMTP health visibility and a guided secure mail configuration command.

### Security

- Added a controlled database-secret rotation workflow.
- Added per-AI systemd sandboxing and resource limits.
- Kept all front-end dependencies local and restricted CSP to same-origin assets.
- Blocked every dot-prefixed path at Apache level, including repository and backup directories.
- Removed duplicate HTTP Basic Auth in favor of the rate-limited application session and optional MFA.

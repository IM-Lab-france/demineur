# Changelog

## [Unreleased] - 2026-07-15

### Added

- Elo ranking (initial rating 1200, K-factor 32) with per-game rating history.
- Score resets now exclude archived matches from period rankings and reset Elo to 1200.
- Private games by default, with opt-in public games and a read-only spectator mode.
- End-of-game flag scoring: +1 for a correct flag and -1 for an incorrect flag.
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
- Administration workflow to create, verify and restore database backups with MFA re-authentication.
- Mutual friend requests with persistent notifications, online/offline friend lists and per-user request preferences.
- Bidirectional blocking for invitations, player discovery and public-game observation, including an automatic forfeit when blocking an active opponent.
- Social actions on the home page, rankings, active games and spectator view, plus configurable AI friend-request policies.
- A 90-day blocking audit visible to administrators.

### Changed

- Safe-board completion is now decided by flag score; equal scores remain a draw.
- Players can only remove flags they placed themselves.
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
- Added automatic safety snapshots, checksum validation and controlled service restarts before database restoration.

### Security

- Added a controlled database-secret rotation workflow.
- Added per-AI systemd sandboxing and resource limits.
- Kept all front-end dependencies local and restricted CSP to same-origin assets.
- Blocked every dot-prefixed path at Apache level, including repository and backup directories.
- Removed duplicate HTTP Basic Auth in favor of the rate-limited application session and optional MFA.
- Restricted browser-initiated backup operations to a fixed root systemd unit and validated backup identifiers.

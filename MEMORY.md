# Mailchimp Hub Driver Memory
## Scope
- Package role: Normalization (Drivers)
- Purpose: This package operates within the Normalization (Drivers) layer of the APIs Hub SaaS hierarchy, providing data normalization for the Mailchimp ecosystem.
- Dependency stance: Consumes `anibalealvarezs/api-client-skeleton`, `anibalealvarezs/api-driver-core`, and `anibalealvarezs/mailchimp-api`; serves the Orchestrator (`apis-hub`).
## Local working rules
- Consult `AGENTS.md` first for package-specific instructions.
- Use this `MEMORY.md` for repository-specific decisions, learnings, and follow-up notes.
- Use `D:\laragon\www\_shared\AGENTS.md` and `D:\laragon\www\_shared\MEMORY.md` for cross-repository protocols and workspace-wide learnings.
- Keep secrets, credentials, tokens, and private endpoints out of this file.
## Current notes
- Mailchimp driver connects `apis-hub` orchestrator with `mailchimp-api-anibal` SDK.
- Auth Provider implements `MultiAccountAuthProviderInterface` using file-based token storage (`storage/tokens/mailchimp_tokens.json`) keyed by account ID.
- Pre-aggregation provider implements `PreAggregationProviderInterface` declaring 30-day attribution windows and metric rollup rules for `sends`, `opens_standard`, `opens_proxy`, `clicks_total`, `clicks_unique`, `bounces_hard`, `bounces_soft`, `unsubscribes`, `orders_count`, and `revenue`.
- `storeCredentials()` (2026-09-27) accepts a multi-account map (`accounts: {id: {...}}`) as sent by the Facade's `importCredentials` call, and unpacks it into one token-file entry per account. Previously the whole map was stored under `default`, leaving `api_key` one level too deep: `getAccounts()` reported a single invalid entry, `getActiveAccountId()` returned `default`, and `fetchAvailableAssets()` skipped every account — which surfaced as an empty audience list in the Facade Data Explorer. The fix also purges a legacy nested `default` entry, since `getActiveAccountId()` uses `array_key_first()` and a stale `default` would otherwise stay active. The single-account `account_id` path is unchanged.
- `accounts` in `getConfigSchema()` is intentional: `ConfigSyncService` builds its payload from schema keys, so the Facade pushes accounts into `config/channels/mailchimp.yaml` by design. Channel config is not an auth source — every runtime path resolves credentials through `MailchimpAuthProvider`.
- `getChanneledAccounts()` (2026-09-27) is the actual root cause of the empty Mailchimp asset list in the Facade Data Explorer, and it was independent of the credentials bug above. `ConfigManagerController::syncAssetsToDatabase()` (apis-hub) iterates the configured `audiences` and calls `$driver::getChanneledAccounts($asset)` to persist `channeled_accounts` rows; the `SyncDriverTrait` default returns `[]`, so Mailchimp never produced a single row and `GET mailchimp/channeled_account` was always empty. `MailchimpDriver` now implements `ChanneledAccountableInterface` (same as the Google/Facebook drivers) and maps one `audience`-typed channeled account per discovered list. Two traps: return `null` (not `''`) for `platformCreatedAt`, because the worker evaluates `is_string(...) ? new DateTime(...) : null` and `new DateTime('')` silently becomes "now"; and read `platform_id` or `id`, since discovery assets use the former and the Facade's `sync_config` uses the latter. `data` must be an array (`addData(?array)`).

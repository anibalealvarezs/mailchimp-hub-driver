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
- `UniversalEntityConverter` contract (2026-10-01): `platform_id_field` and `date_field` in `UniversalEntityConverter::convert()` must be plain string property names or array fallbacks (passing a Closure crashes with TypeError in `getNestedValue()`). `MailchimpConvert::events()` previously passed `fn ($r) => md5(...)` as `platform_id_field`, causing worker crashes during campaign event processing. The fix precomputes `'event_id'` into the raw flattened event array and passes `'event_id'` as a string, and injects `'_created_at'` into links/folders instead of closure-based date fields.
- `dataProcessor` entity callback contract (2026-10-01): The second parameter to `($this->dataProcessor)($collection, $type)` in `apis-hub` expects a string entity type (e.g. `'event'`, `'order'`). Passing `$this->logger` caused `$type` to evaluate to `null` in `SyncService`, silently skipping entity persistence. Additionally, campaign events link to audience list IDs (`recipients.list_id`) as their `channeled_account_id` and action as `name` so `EventProcessor` can successfully resolve and upsert `channeled_events`.
- `MailchimpConvert::orders()` (2026-10-01): Added structured mappings for `customer` (object with `id` and `email`), `discountCodes` (array from `promos`), and `lineItems` (array of `product_id` and `variant_id` from `lines`) to match `OrderProcessor` expectations.

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

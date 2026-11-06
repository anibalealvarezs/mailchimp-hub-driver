# Mailchimp commerce surface manifest

Updated 2026-11-06. This describes cached source snapshots and existing daily store
metrics. It does not establish completeness for an authenticated account.

| Source resource | SDK traversal | Driver callback | Worker cache | Catalog |
|---|---|---|---|---|
| Audiences | list pages | account | channeled_accounts | accounts |
| Stores | store pages | store | stores, channeled_stores | stores |
| Store customers | customer pages | customer | customers, channeled_customers | customers |
| Products | product pages | product | products, channeled_products | products |
| Variants | separate variant pages | product variants | product_variants, channeled_product_variants | variants |
| Orders | order pages, all history | order | orders, channeled_orders | orders |
| Order lines | embedded source lines | order relationships | product/variant pivots plus raw quantities/amounts | order attributes |
| Promo rules | rule pages | price_rule | price_rules, channeled_price_rules | promotions |
| Promo codes | separate code pages | price_rule codes | discounts, channeled_discounts | promo-codes |
| Carts | cart pages, opt-in | cart | channeled_carts | carts |
| Cart lines | embedded source lines | raw cart snapshot | cart JSON | scalar line summary |

Ecommerce pagination bounds batch size to 1–1000, checks cancellation before each
page, rejects repeated pages and malformed collections, and honors collector loop
limits before fetching another page. Provider access or traversal failures escape
commerce sync and permit job retry. Folders/templates have explicit callback types
but are not advertised as cached commerce capabilities.

Account + store namespaces prevent overlapping provider IDs from colliding.
Products and order lines share the same normalized IDs. Variant IDs additionally
include the product. Promo code IDs include the rule. Raw source IDs, audience ID,
store identity, source fields, line quantities and source amounts remain available
in cached data. A store must resolve to an audience under the provider account.
Enabled-audience selection applies before syncing store children. Carts default
to disabled through `sync_carts`.

Normal scheduling declares `entities_sync: entities`. The driver routes those jobs
through its entity importer, while ordinary metric jobs import orders and events.
The instance generator gives metric jobs their existing entity-job dependency.
This dispatch must be present in the released driver used by new projects.

| Field | Source | Null/identity policy |
|---|---|---|
| Store name/domain/platform | store response | preserve supplied metadata |
| Audience | store list_id | verify persisted parent ownership |
| Order date | processed_at_foreign, then supplied created_at | unknown excludes daily rollup |
| Total | order_total | numeric source value, unknown stays null |
| Currency | order currency_code, then store currency_code | uppercase valid ISO-shaped code required for rollup, no default |
| Status | financial_status, fulfillment_status | unknown remains unknown |
| Customer | source customer ID/email | store-scoped source ID, canonical customer reuse |
| Lines | lines | source product/variant references scoped consistently |
| Promotions | promos code text | link only a unique code within the verified store |
| Source updates/cancellation | updated_at_foreign/cancelled_at_foreign | preserve snapshot fields |

Supported measures are daily eligible distinct orders and sum of their source
totals, grouped by store and currency, owned by the store's persisted audience.
Orders with unknown total, date, currency or identity are excluded from both.
All statuses remain in this population. These are not paid-order or net-revenue
measures. Mixed currency cannot produce a scalar revenue total.

Full order refresh and full cached-date materialization handle late edits. Source
absence is not interpreted as deletion. Incremental watermarks, verified deletion,
net revenue, refund adjustments, cart performance, product performance and dynamic
entity measures remain unimplemented. Live permissions, account completeness,
provider rate-limit responses and historical availability require a canary.

Primary source references used for the endpoint model are the
[ecommerce concepts](https://mailchimp.com/developer/marketing/concepts/e-commerce/),
[order resource](https://mailchimp.com/developer/marketing/api/e-commerce/orders/get-store-order),
[variant endpoint](https://mailchimp.com/developer/marketing/api/ecommerce/list-store-product-variants)
and [promo rule resource](https://mailchimp.com/developer/marketing/api/e-commerce/promo-rules/get-store-promo-rule).

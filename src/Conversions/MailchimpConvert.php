<?php

declare(strict_types=1);

namespace Anibalealvarezs\MailchimpHubDriver\Conversions;

use Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity;
use Anibalealvarezs\ApiDriverCore\Conversions\UniversalEntityConverter;
use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
use Doctrine\Common\Collections\ArrayCollection;

class MailchimpConvert
{
    /**
     * Converts Mailchimp lists/audiences into UniversalEntity objects.
     */
    public static function audiences(array $audiences, string $accountId): ArrayCollection
    {
        return UniversalEntityConverter::convert($audiences, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'id',
            'date_field' => 'date_created',
            'mapping' => [
                'title' => 'name',
                'category' => fn () => AssetCategory::IDENTITY->value,
                'account_id' => fn () => $accountId,
                'member_count' => fn ($r) => $r['stats']['member_count'] ?? 0,
                'unsubscribe_count' => fn ($r) => $r['stats']['unsubscribe_count'] ?? 0,
                'campaign_count' => fn ($r) => $r['stats']['campaign_count'] ?? 0,
            ],
        ]);
    }

    /**
     * Converts Mailchimp connected eCommerce stores into ChanneledStore entities.
     */
    public static function stores(array $stores, string $accountId): ArrayCollection
    {
        foreach ($stores as &$store) {
            $store['source_store_id'] = (string) $store['id'];
            $store['store_platform_id'] = self::commerceId($accountId, (string)$store['id'], 'store');
            $store['account_id'] = $accountId;
            $store['audience_id'] = $store['list_id'] ?? null;
        }
        unset($store);
        return UniversalEntityConverter::convert($stores, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'store_platform_id',
            'date_field' => 'created_at',
            'mapping' => [
                'title' => 'name',
                'category' => fn () => AssetCategory::RESOURCE->value,
                'account_id' => fn () => $accountId,
                'domain' => fn ($r) => $r['domain'] ?? '',
                'platform' => fn ($r) => $r['platform'] ?? 'custom',
                'currency_code' => fn ($r) => $r['currency_code'] ?? null,
                'money_format' => fn ($r) => $r['money_format'] ?? null,
            ],
        ]);
    }

    /**
     * Converts Mailchimp campaigns into ChanneledCampaign entities.
     */
    public static function campaigns(array $campaigns, string $accountId): ArrayCollection
    {
        foreach ($campaigns as &$item) {
            $item['_date'] = !empty($item['send_time']) ? $item['send_time'] : ($item['create_time'] ?? ($item['start_time'] ?? null));
        }
        unset($item);

        return UniversalEntityConverter::convert($campaigns, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'id',
            'date_field' => '_date',
            'mapping' => [
                'name' => fn ($r) => $r['settings']['title'] ?? ($r['settings']['subject_line'] ?? ($r['title'] ?? ($r['id'] ?? ''))),
                'channeledAccountId' => fn ($r) => $r['recipients']['list_id'] ?? $accountId,
                'category' => fn () => AssetCategory::CAMPAIGN->value,
                'account_id' => fn () => $accountId,
                'title' => fn ($r) => $r['settings']['title'] ?? ($r['settings']['subject_line'] ?? ($r['title'] ?? ($r['id'] ?? ''))),
                'subject' => fn ($r) => $r['settings']['subject_line'] ?? '',
                'type' => fn ($r) => $r['type'] ?? 'regular',
                'status' => fn ($r) => $r['status'] ?? 'save',
                'list_id' => fn ($r) => $r['recipients']['list_id'] ?? null,
                'folder_id' => fn ($r) => $r['settings']['folder_id'] ?? null,
                'template_id' => fn ($r) => $r['settings']['template_id'] ?? null,
                'emails_sent' => fn ($r) => $r['emails_sent'] ?? 0,
                'data' => fn ($r) => array_merge($r, [
                    'name' => $r['settings']['title'] ?? ($r['settings']['subject_line'] ?? ($r['title'] ?? ($r['id'] ?? ''))),
                    'type' => $r['type'] ?? 'regular',
                ]),
            ],
        ]);
    }

    /**
     * Converts tracked CTA links into ChanneledLink (AssetCategory::UNIT) entities.
     */
    public static function links(array $urlsClicked, string $campaignId, string $accountId): ArrayCollection
    {
        $now = date('Y-m-d H:i:s');
        foreach ($urlsClicked as &$item) {
            $item['_created_at'] = $now;
        }
        unset($item);

        return UniversalEntityConverter::convert($urlsClicked, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'id',
            'date_field' => '_created_at',
            'mapping' => [
                'category' => fn () => AssetCategory::UNIT->value,
                'account_id' => fn () => $accountId,
                'campaign_id' => fn () => $campaignId,
                'url' => fn ($r) => $r['url'] ?? '',
                'total_clicks' => fn ($r) => $r['total_clicks'] ?? 0,
                'unique_clicks' => fn ($r) => $r['unique_clicks'] ?? 0,
                'click_percentage' => fn ($r) => $r['click_percentage'] ?? 0.0,
            ],
        ]);
    }

    /**
     * Converts campaign folders into ChanneledFolder entities.
     */
    public static function folders(array $folders, string $accountId): ArrayCollection
    {
        $now = date('Y-m-d H:i:s');
        foreach ($folders as &$item) {
            $item['_created_at'] = $now;
        }
        unset($item);

        return UniversalEntityConverter::convert($folders, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'id',
            'date_field' => '_created_at',
            'mapping' => [
                'category' => fn () => AssetCategory::GROUPING->value,
                'account_id' => fn () => $accountId,
                'title' => fn ($r) => $r['name'] ?? '',
                'count' => fn ($r) => $r['count'] ?? 0,
            ],
        ]);
    }

    /**
     * Converts templates into ChanneledTemplate entities.
     */
    public static function templates(array $templates, string $accountId): ArrayCollection
    {
        return UniversalEntityConverter::convert($templates, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'id',
            'date_field' => 'date_created',
            'mapping' => [
                'category' => fn () => AssetCategory::RESOURCE->value,
                'account_id' => fn () => $accountId,
                'title' => fn ($r) => $r['name'] ?? '',
                'type' => fn ($r) => $r['type'] ?? 'user',
            ],
        ]);
    }

    /**
     * Converts raw recipient email activity events (opens, clicks, bounces) into ChanneledEvent entities.
     */
    public static function events(array $emailsActivity, string $campaignId, string $listId, string $accountId): ArrayCollection
    {
        $flattened = [];
        foreach ($emailsActivity as $item) {
            $emailId = $item['email_id'] ?? '';
            $activities = $item['activity'] ?? [];
            foreach ($activities as $act) {
                $action = $act['action'] ?? 'open';
                $timestamp = $act['timestamp'] ?? date('Y-m-d H:i:s');
                $isProxy = !empty($act['is_proxy']) || !empty($act['proxy_open']);
                $flattened[] = [
                    'event_id' => md5($campaignId . ':' . $emailId . ':' . $action . ':' . $timestamp),
                    'campaign_id' => $campaignId,
                    'channeled_account_id' => $listId,
                    'account_id' => $accountId,
                    'email_id' => $emailId,
                    'identity_hash' => $emailId,
                    'action' => $action,
                    'name' => $action,
                    'timestamp' => $timestamp,
                    'ip' => $act['ip'] ?? null,
                    'url' => $act['url'] ?? null,
                    'type' => $act['type'] ?? null,
                    'bounce_type' => $act['type'] ?? null,
                    'is_proxy' => $isProxy,
                ];
            }
        }

        return UniversalEntityConverter::convert($flattened, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'event_id',
            'date_field' => 'timestamp',
            'mapping' => [
                'name' => 'name',
                'channeledAccountId' => 'channeled_account_id',
                'campaign_id' => 'campaign_id',
                'account_id' => 'account_id',
                'action' => 'action',
                'identity_hash' => 'email_id',
                'url' => 'url',
                'bounce_type' => 'type',
                'is_proxy' => 'is_proxy',
            ],
        ]);
    }

    /**
     * Converts campaign sent-to recipients into ChanneledEvent entities with action='send'.
     */
    public static function sentToEvents(array $sentToMembers, string $campaignId, string $listId, string $accountId, ?string $fallbackTimestamp = null): ArrayCollection
    {
        $flattened = [];
        $fallback = $fallbackTimestamp ?: date('Y-m-d H:i:s');

        foreach ($sentToMembers as $member) {
            $emailId = (string)($member['email_id'] ?? '');
            if (empty($emailId) && !empty($member['email_address'])) {
                $emailId = md5(strtolower(trim($member['email_address'])));
            }

            $timestamp = $member['last_changed'] ?? $fallback;

            $flattened[] = [
                'event_id' => md5($campaignId . ':' . $emailId . ':send:' . $timestamp),
                'campaign_id' => $campaignId,
                'channeled_account_id' => $listId,
                'account_id' => $accountId,
                'email_id' => $emailId,
                'action' => 'send',
                'name' => 'send',
                'timestamp' => $timestamp,
                'status' => $member['status'] ?? 'sent',
            ];
        }

        return UniversalEntityConverter::convert($flattened, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'event_id',
            'date_field' => 'timestamp',
            'mapping' => [
                'name' => 'name',
                'channeledAccountId' => 'channeled_account_id',
                'campaign_id' => 'campaign_id',
                'account_id' => 'account_id',
                'action' => 'action',
                'identity_hash' => 'email_id',
            ],
        ]);
    }

    /**
     * Converts campaign unsubscribed members into ChanneledEvent entities with action='unsubscribe'.
     */
    public static function unsubscribeEvents(array $unsubscribedMembers, string $campaignId, string $listId, string $accountId, ?string $fallbackTimestamp = null): ArrayCollection
    {
        $flattened = [];
        $fallback = $fallbackTimestamp ?: date('Y-m-d H:i:s');

        foreach ($unsubscribedMembers as $member) {
            $emailId = (string)($member['email_id'] ?? '');
            if (empty($emailId) && !empty($member['email_address'])) {
                $emailId = md5(strtolower(trim($member['email_address'])));
            }

            $timestamp = $member['timestamp'] ?? $fallback;

            $flattened[] = [
                'event_id' => md5($campaignId . ':' . $emailId . ':unsubscribe:' . $timestamp),
                'campaign_id' => $campaignId,
                'channeled_account_id' => $listId,
                'account_id' => $accountId,
                'email_id' => $emailId,
                'action' => 'unsubscribe',
                'name' => 'unsubscribe',
                'timestamp' => $timestamp,
                'reason' => $member['reason'] ?? null,
            ];
        }

        return UniversalEntityConverter::convert($flattened, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'event_id',
            'date_field' => 'timestamp',
            'mapping' => [
                'name' => 'name',
                'channeledAccountId' => 'channeled_account_id',
                'campaign_id' => 'campaign_id',
                'account_id' => 'account_id',
                'action' => 'action',
                'identity_hash' => 'email_id',
            ],
        ]);
    }

    /**
     * Converts connected store orders into ChanneledOrder entities.
     */
    public static function orders(array $orders, string $storeId, string $accountId, ?string $audienceId = null, ?string $storeCurrency = null): ArrayCollection
    {
        $orders = array_values(array_filter($orders, static fn (array $order): bool =>
            isset($order['id']) && (string) $order['id'] !== ''));
        foreach ($orders as &$order) {
            $order['store_order_key'] = hash('sha256', json_encode([$accountId, $storeId, (string) $order['id']], JSON_THROW_ON_ERROR));
            $order['store_id'] = $storeId;
            $order['audience_id'] = $audienceId;
            $order['source_order_id'] = (string) $order['id'];
            $order['account_id'] = $accountId;
            $order['currency'] = $order['currency_code'] ?? $storeCurrency;
            $order['source_customer_id'] = $order['customer']['id'] ?? null;
            $order['store_platform_id'] = self::commerceId($accountId, $storeId, 'store');
            $order['_order_date'] = $order['processed_at_foreign'] ?? $order['created_at'] ?? null;
            $order['scoped_customer_id'] = isset($order['customer']['id'])
                ? self::commerceId($accountId, $storeId, (string)$order['customer']['id']) : null;
            $order['scoped_lines'] = array_map(static fn (array $line): array => array_merge($line, [
                'product_id' => isset($line['product_id']) ? self::commerceId($accountId, $storeId, (string)$line['product_id']) : null,
                'variant_id' => isset($line['product_variant_id'], $line['product_id'])
                    ? self::commerceId($accountId, $storeId, json_encode([$line['product_id'], $line['product_variant_id']])) : null,
            ]), $order['lines'] ?? []);
            $order['total_amount'] = isset($order['order_total']) && is_numeric($order['order_total'])
                ? (float)$order['order_total'] : null;
        }
        unset($order);

        return UniversalEntityConverter::convert($orders, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'store_order_key',
            'date_field' => '_order_date',
            'mapping' => [
                'customer' => fn ($r) => !empty($r['customer']) ? (object) [
                    'id' => $r['scoped_customer_id'] ?? null,
                    'email' => isset($r['customer']['email_address']) ? strtolower(trim($r['customer']['email_address'])) : null,
                ] : null,
                'discountCodes' => fn ($r) => array_map(
                    fn ($promo) => self::commerceId($accountId, $storeId, (string)($promo['code'] ?? '')),
                    array_filter($r['promos'] ?? [], fn ($promo) => !empty($promo['code']))
                ),
                'lineItems' => 'scoped_lines',
                'storePlatformId' => 'store_platform_id',
                'store_id' => fn () => $storeId,
                'audience_id' => fn () => $audienceId,
                'source_order_id' => fn ($r) => (string) $r['id'],
                'account_id' => fn () => $accountId,
                'campaign_id' => fn ($r) => $r['campaign_id'] ?? null,
                'total_amount' => 'total_amount',
                'tax_total' => fn ($r) => isset($r['tax_total']) && is_numeric($r['tax_total']) ? (float)$r['tax_total'] : null,
                'shipping_total' => fn ($r) => isset($r['shipping_total']) && is_numeric($r['shipping_total']) ? (float)$r['shipping_total'] : null,
                'currency' => fn ($r) => $r['currency'] ?? null,
                'financial_status' => fn ($r) => $r['financial_status'] ?? null,
                'identity_hash' => fn ($r) => !empty($r['customer']['email_address'])
                    ? md5(strtolower(trim($r['customer']['email_address'])))
                    : ($r['customer']['id'] ?? ''),
            ],
        ]);
    }

    /** Stable provider-account/store namespace, also used by order relationships. */
    public static function commerceId(string $accountId, string $storeId, string $sourceId): string
    {
        return hash('sha256', json_encode([$accountId, $storeId, $sourceId], JSON_THROW_ON_ERROR));
    }

    private static function commerceRows(array $rows, string $storeId, string $accountId, ?string $audienceId): array
    {
        $rows = array_values(array_filter($rows, static fn (array $row): bool => isset($row['id']) && (string)$row['id'] !== ''));
        foreach ($rows as &$row) {
            $row['source_id'] = (string)$row['id'];
            $row['commerce_id'] = self::commerceId($accountId, $storeId, (string)$row['id']);
            $row['store_id'] = $storeId;
            $row['store_platform_id'] = self::commerceId($accountId, $storeId, 'store');
            $row['account_id'] = $accountId;
            $row['audience_id'] = $audienceId;
            $row['_source_date'] = $row['created_at_foreign'] ?? $row['created_at'] ?? null;
        }
        unset($row);
        return $rows;
    }

    public static function customers(array $customers, string $storeId, string $accountId, ?string $audienceId): ArrayCollection
    {
        $rows = self::commerceRows($customers, $storeId, $accountId, $audienceId);
        foreach ($rows as &$row) {
            $row['email'] = strtolower(trim((string)($row['email_address'] ?? '')));
        }
        unset($row);
        return UniversalEntityConverter::convert($rows, [
            'channel' => 'mailchimp', 'platform_id_field' => 'commerce_id', 'date_field' => '_source_date',
            'mapping' => ['email' => 'email', 'storePlatformId' => 'store_platform_id'],
        ]);
    }

    public static function products(array $products, string $storeId, string $accountId, ?string $audienceId): ArrayCollection
    {
        $rows = self::commerceRows($products, $storeId, $accountId, $audienceId);
        foreach ($rows as &$row) {
            $row['normalized_variants'] = array_map(static function (array $variant) use ($storeId, $accountId, $audienceId, $row): object {
                return (object)[
                    'platformId' => self::commerceId($accountId, $storeId, json_encode([$row['source_id'], $variant['id']])),
                    'sku' => $variant['sku'] ?? null,
                    'platformCreatedAt' => isset($variant['created_at']) ? new \DateTime($variant['created_at']) : null,
                    'data' => array_merge($variant, [
                        'store_id' => $storeId, 'account_id' => $accountId, 'audience_id' => $audienceId,
                        'source_product_id' => $row['source_id'], 'source_id' => (string)$variant['id'],
                    ]),
                ];
            }, $row['variants'] ?? []);
        }
        unset($row);
        return UniversalEntityConverter::convert($rows, [
            'channel' => 'mailchimp', 'platform_id_field' => 'commerce_id', 'date_field' => '_source_date',
            'mapping' => ['title' => 'title', 'variants' => 'normalized_variants', 'storePlatformId' => 'store_platform_id'],
        ]);
    }

    public static function carts(array $carts, string $storeId, string $accountId, ?string $audienceId): ArrayCollection
    {
        return UniversalEntityConverter::convert(self::commerceRows($carts, $storeId, $accountId, $audienceId), [
            'channel' => 'mailchimp', 'platform_id_field' => 'commerce_id', 'date_field' => '_source_date',
            'mapping' => ['storePlatformId' => 'store_platform_id'],
        ]);
    }

    public static function promotions(array $rules, string $storeId, string $accountId, ?string $audienceId): ArrayCollection
    {
        foreach ($rules as &$rule) {
            foreach ($rule['codes'] ?? [] as $i => $code) {
                $rule['codes'][$i]['identity'] = self::commerceId(
                    $accountId, $storeId, json_encode(['promo-code', (string)$rule['id'], (string)$code['id']], JSON_THROW_ON_ERROR)
                );
            }
        }
        unset($rule);
        return UniversalEntityConverter::convert(self::commerceRows($rules, $storeId, $accountId, $audienceId), [
            'channel' => 'mailchimp', 'platform_id_field' => 'commerce_id', 'date_field' => '_source_date',
            'mapping' => ['storePlatformId' => 'store_platform_id'],
        ]);
    }
}

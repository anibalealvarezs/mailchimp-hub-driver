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
        return UniversalEntityConverter::convert($stores, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'title' => 'name',
                'category' => fn () => AssetCategory::RESOURCE->value,
                'account_id' => fn () => $accountId,
                'domain' => fn ($r) => $r['domain'] ?? '',
                'platform' => fn ($r) => $r['platform'] ?? 'custom',
                'currency_code' => fn ($r) => $r['currency_code'] ?? 'USD',
                'money_format' => fn ($r) => $r['money_format'] ?? '$',
            ],
        ]);
    }

    /**
     * Converts Mailchimp campaigns into ChanneledCampaign entities.
     */
    public static function campaigns(array $campaigns, string $accountId): ArrayCollection
    {
        return UniversalEntityConverter::convert($campaigns, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'id',
            'date_field' => fn ($r) => !empty($r['send_time']) ? $r['send_time'] : ($r['create_time'] ?? ($r['start_time'] ?? null)),
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
                    'action' => $action,
                    'name' => $action,
                    'timestamp' => $timestamp,
                    'ip' => $act['ip'] ?? null,
                    'url' => $act['url'] ?? null,
                    'type' => $act['type'] ?? null,
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
    public static function orders(array $orders, string $storeId, string $accountId): ArrayCollection
    {
        return UniversalEntityConverter::convert($orders, [
            'channel' => 'mailchimp',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'customer' => fn ($r) => !empty($r['customer']) ? (object) [
                    'id' => $r['customer']['id'] ?? null,
                    'email' => $r['customer']['email_address'] ?? null,
                ] : null,
                'discountCodes' => fn ($r) => !empty($r['promos']) ? array_map(fn ($p) => $p['code'] ?? '', $r['promos']) : [],
                'lineItems' => fn ($r) => !empty($r['lines']) ? array_map(fn ($l) => [
                    'product_id' => $l['product_id'] ?? null,
                    'variant_id' => $l['product_variant_id'] ?? null,
                ], $r['lines']) : [],
                'store_id' => fn () => $storeId,
                'account_id' => fn () => $accountId,
                'campaign_id' => fn ($r) => $r['campaign_id'] ?? null,
                'total_amount' => fn ($r) => (float) ($r['order_total'] ?? 0.0),
                'tax_total' => fn ($r) => (float) ($r['tax_total'] ?? 0.0),
                'shipping_total' => fn ($r) => (float) ($r['shipping_total'] ?? 0.0),
                'currency' => fn ($r) => $r['currency_code'] ?? 'USD',
                'financial_status' => fn ($r) => $r['financial_status'] ?? 'paid',
                'identity_hash' => fn ($r) => !empty($r['customer']['email_address'])
                    ? md5(strtolower(trim($r['customer']['email_address'])))
                    : ($r['customer']['id'] ?? ''),
            ],
        ]);
    }
}

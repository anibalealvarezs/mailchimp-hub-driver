<?php

declare(strict_types=1);

namespace Anibalealvarezs\MailchimpHubDriver\Drivers;

use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\ChanneledAccountableInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\MultiAccountAuthProviderInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\PreAggregationProviderInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;
use Anibalealvarezs\MailchimpApi\Services\Marketing\MarketingApi;
use Anibalealvarezs\MailchimpHubDriver\Auth\MailchimpAuthProvider;
use Anibalealvarezs\MailchimpHubDriver\Conversions\MailchimpConvert;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class MailchimpDriver implements SyncDriverInterface, PreAggregationProviderInterface, CanonicalMetricDictionaryProviderInterface, ChanneledAccountableInterface
{
    use SyncDriverTrait;

    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;
    /** @var callable|null */
    private $dataProcessor = null;

    public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
    {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
    }

    public static function getCommonConfigKey(): ?string
    {
        return 'mailchimp';
    }

    public static function storeCredentials(array $credentials): void
    {
        $auth = new MailchimpAuthProvider();

        $accounts = $credentials['accounts'] ?? null;
        if (!empty($accounts) && is_array($accounts)) {
            // Un-nest if accounts are inside accounts.default.accounts
            if (isset($accounts['default']['accounts']) && is_array($accounts['default']['accounts'])) {
                $accounts = array_merge($accounts, $accounts['default']['accounts']);
                unset($accounts['default']);
            }

            foreach ($accounts as $accountId => $account) {
                if (is_array($account)) {
                    $auth->storeAccountCredentials((string) $accountId, $account);
                }
            }

            $legacy = $auth->getCredentialsForAccount('default');
            if (is_array($legacy) && (empty($legacy['api_key']) || !empty($legacy['accounts']))) {
                $auth->removeAccountCredentials('default');
            }

            return;
        }

        $accountId = $credentials['account_id'] ?? 'default';
        $auth->storeAccountCredentials($accountId, $credentials);
    }

    public static function getPublicResources(): array
    {
        return ['metrics' => 'mailchimp_metrics'];
    }

    public static function getChannelLabel(): string
    {
        return 'Mailchimp';
    }

    public static function getProviderLabel(): string
    {
        return 'Mailchimp';
    }

    public static function getProviderName(): string
    {
        return 'mailchimp';
    }

    public static function getDefaultMaxWorkers(): int
    {
        return 2;
    }

    public static function getChannelIcon(): string
    {
        return 'M';
    }

    public static function getPlatformEntityIdField(): string
    {
        return 'platform_id';
    }

    public static function getEnvMapping(): array
    {
        return [
            'mailchimp' => [
                'MAILCHIMP_TOKEN_PATH' => 'token_path',
            ],
        ];
    }

    public function getUpdatableCredentials(): array
    {
        return ['accounts'];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'token_path' => ['type' => 'string'],
                'accounts' => ['type' => 'array'],
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        return $config;
    }

    public function updateConfiguration(array $newData, array $currentConfig): array
    {
        $selectedAudiences = $newData['assets']['audiences'] ?? [];

        if (empty($selectedAudiences) && isset($newData['type']) && $newData['type'] !== 'global') {
            $this->logger?->warning('Received empty audiences payload for Mailchimp, skipping update to prevent wipe.');

            return $currentConfig;
        }

        // Check if config uses the new nested structure (channels.mailchimp)
        $isNested = isset($currentConfig['channels']['mailchimp']);
        $targetConfig = $isNested ? $currentConfig['channels']['mailchimp'] : $currentConfig;

        // Merge fields
        $merged = array_merge($targetConfig, $newData);

        // Unpack audiences
        $merged['audiences'] = $selectedAudiences;
        unset($merged['assets']);

        if ($isNested) {
            $currentConfig['channels']['mailchimp'] = $merged;
            return $currentConfig;
        }

        return $merged;
    }

    public function validateAuthentication(): array
    {
        $auth = $this->authProvider instanceof MailchimpAuthProvider
            ? $this->authProvider
            : new MailchimpAuthProvider();

        $accounts = $auth->getAccounts();
        if (empty($accounts)) {
            return [
                'success' => false,
                'message' => 'No Mailchimp accounts configured.',
                'details' => [],
            ];
        }

        $report = $auth->validateAuthentication();
        $allValid = !empty($report) && !in_array(false, array_column($report, 'valid'), true);

        return [
            'success' => $allValid,
            'message' => $allValid ? 'All Mailchimp accounts authenticated successfully.' : 'One or more Mailchimp accounts failed authentication.',
            'details' => $report,
        ];
    }

    public function fetchAvailableAssets(bool $throwOnError = false): array
    {
        $auth = $this->authProvider instanceof MailchimpAuthProvider
            ? $this->authProvider
            : new MailchimpAuthProvider();

        $accounts = $auth->getAccounts();
        $assets = [];

        foreach ($accounts as $accountId => $acc) {
            if (!$acc['is_valid']) {
                continue;
            }
            try {
                $api = $this->getApi(['account_id' => $accountId]);
                $lists = $api->getListsInfo(count: 100);
                foreach ($lists['lists'] ?? [] as $list) {
                    $assets[] = [
                        'account_id' => $accountId,
                        'platform_id' => $list['id'],
                        'name' => $list['name'],
                        'type' => 'audience',
                    ];
                }
            } catch (Exception $e) {
                if ($throwOnError) {
                    throw $e;
                }
            }
        }

        return $assets;
    }

    public static function getChanneledAccounts(array $asset): array
    {
        $platformId = self::getChanneledAccountPlatformId($asset);

        if ($platformId === '') {
            return [];
        }

        $platformCreatedAt = self::getChanneledAccountPlatformCreatedAt($asset);

        return [
            [
                'platformId'        => $platformId,
                'platformCreatedAt' => $platformCreatedAt !== '' ? $platformCreatedAt : null,
                'name'              => self::getChanneledAccountName($asset),
                'type'              => self::getChanneledAccountType(),
                'enabled'           => filter_var($asset['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'data'              => self::getChanneledAccountData($asset),
            ]
        ];
    }

    public static function getChanneledAccountPlatformId(array $asset, ?string $key = null): string
    {
        return (string)($asset['platform_id'] ?? $asset['id'] ?? '');
    }

    public static function getChanneledAccountPlatformCreatedAt(array $asset, ?string $key = null): string
    {
        return (string)($asset['date_created'] ?? $asset['platformCreatedAt'] ?? '');
    }

    public static function getChanneledAccountName(array $asset, ?string $key = null): string
    {
        return (string)($asset['name'] ?? '');
    }

    public static function getChanneledAccountType(): string
    {
        return 'audience';
    }

    public static function getChanneledAccountData(array $asset, ?string $key = null): array
    {
        return is_array($asset['data'] ?? null) ? $asset['data'] : [];
    }

    public function getChannel(): string
    {
        return 'mailchimp';
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function getAuthProvider(): ?AuthProviderInterface
    {
        return $this->authProvider;
    }

    public function setDataProcessor(callable $processor): void
    {
        $this->dataProcessor = $processor;
    }

    public function getApi(array $config = []): MarketingApi
    {
        $auth = $this->authProvider instanceof MultiAccountAuthProviderInterface
            ? $this->authProvider
            : new MailchimpAuthProvider();

        $accountId = $config['account_id'] ?? ($auth instanceof MailchimpAuthProvider ? $auth->getActiveAccountId() : '');
        $credentials = $auth instanceof MultiAccountAuthProviderInterface ? $auth->getCredentialsForAccount($accountId) : null;

        $apiKey = $credentials['api_key'] ?? null;
        $accessToken = $credentials['access_token'] ?? null;
        $serverPrefix = $credentials['server_prefix'] ?? 'us1';

        return new MarketingApi(
            apiKey: $apiKey,
            serverPrefix: $serverPrefix,
            accessToken: $accessToken,
        );
    }

    public static function getCanonicalMetricDictionary(): array
    {
        return [
            'sends' => ['sends'],
            'opens' => ['opens_total'],
            'opens_standard' => ['opens_standard'],
            'opens_proxy' => ['opens_proxy'],
            'clicks' => ['clicks_total'],
            'clicks_unique' => ['clicks_unique'],
            'bounces' => ['bounces_total'],
            'bounces_hard' => ['bounces_hard'],
            'bounces_soft' => ['bounces_soft'],
            'unsubscribes' => ['unsubscribes'],
            'orders' => ['orders_count'],
            'revenue' => ['revenue'],
        ];
    }

    public static function getPreAggregationRules(): array
    {
        return [
            'campaign_engagement' => [
                'source_entity' => 'channeled_events',
                'scope_field' => 'campaign_id',
                'metrics' => [
                    'sends' => ['condition' => ['action' => 'send'], 'reducer' => 'count'],
                    'opens_total' => ['condition' => ['action' => 'open'], 'reducer' => 'count'],
                    'opens_standard' => ['condition' => ['action' => 'open', 'is_proxy' => false], 'reducer' => 'count'],
                    'opens_proxy' => ['condition' => ['action' => 'open', 'is_proxy' => true], 'reducer' => 'count'],
                    'clicks_total' => ['condition' => ['action' => 'click'], 'reducer' => 'count', 'dimension_fields' => ['page' => 'url']],
                    'clicks_unique' => ['condition' => ['action' => 'click'], 'field' => 'identity_hash', 'reducer' => 'count_distinct', 'dimension_fields' => ['page' => 'url']],
                    'bounces_total' => ['condition' => ['action' => 'bounce'], 'reducer' => 'count'],
                    'bounces_hard' => ['condition' => ['action' => 'bounce', 'bounce_type' => 'hard'], 'reducer' => 'count'],
                    'bounces_soft' => ['condition' => ['action' => 'bounce', 'bounce_type' => 'soft'], 'reducer' => 'count'],
                    'unsubscribes' => ['condition' => ['action' => 'unsubscribe'], 'reducer' => 'count'],
                ],
                'attribution' => [
                    'identity_field' => 'identity_hash',
                    'timestamp_field' => 'timestamp',
                ],
            ],
            'store_conversions' => [
                'source_entity' => 'channeled_orders',
                'scope_field' => 'store_id',
                'metrics' => [
                    'orders_count' => ['field' => 'platform_id', 'reducer' => 'count_distinct'],
                    'revenue' => ['field' => 'total_amount', 'reducer' => 'sum'],
                ],
                'attribution' => [
                    'identity_field' => 'identity_hash',
                    'timestamp_field' => 'created_at',
                ],
            ],
        ];
    }

    public static function getDefaultAttributionWindowDays(): int
    {
        return 30;
    }

    /**
     * Executes Hierarchical Entity Synchronization (Audiences, Connected Stores, Campaigns, Folders, Templates, Links).
     */
    public function syncEntities(
        array $config = [],
        ?callable $shouldContinue = null
    ): Response {
        $auth = $this->authProvider instanceof MultiAccountAuthProviderInterface
            ? $this->authProvider
            : new MailchimpAuthProvider();

        $accounts = $auth->getAccounts();
        $this->logger?->info(sprintf("Starting Mailchimp entity sync across %d configured accounts...", count($accounts)));

        foreach ($accounts as $accountId => $accountDescriptor) {
            if ($shouldContinue && !$shouldContinue()) {
                throw new Exception("Entity sync aborted by orchestrator.");
            }

            if (!$accountDescriptor['is_valid']) {
                $this->logger?->warning("Skipping invalid Mailchimp account: " . $accountId);
                continue;
            }

            $api = $this->getApi(['account_id' => $accountId]);

            // 1. Sync Audiences (Lists)
            $api->getAllListsInfoAndProcess(function ($audiences) use ($accountId) {
                $collection = MailchimpConvert::audiences($audiences, $accountId);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            });

            // 2. Sync Connected Stores
            $api->getAllEcommerceStoresAndProcess(function ($stores) use ($accountId) {
                $collection = MailchimpConvert::stores($stores, $accountId);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            });

            // 3. Sync Folders & Templates
            $api->getAllCampaignFoldersAndProcess(function ($folders) use ($accountId) {
                $collection = MailchimpConvert::folders($folders, $accountId);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            });

            $api->getAllTemplatesAndProcess(function ($templates) use ($accountId) {
                $collection = MailchimpConvert::templates($templates, $accountId);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            });

            // 4. Sync Campaigns and their Tracked CTA Links (Regular & Automations)
            $api->getAllCampaignsAndProcess(function ($campaigns) use ($api, $accountId) {
                $campaignCollection = MailchimpConvert::campaigns($campaigns, $accountId);
                if ($this->dataProcessor && $campaignCollection->count() > 0) {
                    ($this->dataProcessor)($campaignCollection, 'campaign');
                }

                foreach ($campaigns as $camp) {
                    $campaignId = (string)($camp['id'] ?? '');
                    if (!empty($campaignId)) {
                        $api->getAllClickDetailsAndProcess($campaignId, function ($links) use ($campaignId, $accountId) {
                            $linksCollection = MailchimpConvert::links($links, $campaignId, $accountId);
                            if ($this->dataProcessor && $linksCollection->count() > 0) {
                                ($this->dataProcessor)($linksCollection, 'unit');
                            }
                        });
                    }
                }
            });

            // 5. Sync Classic Automations / Workflows if present
            try {
                $api->getAllAutomationsAndProcess(function ($automations) use ($api, $accountId) {
                    // Normalize automations as campaigns
                    $normalized = [];
                    foreach ($automations as $auto) {
                        $auto['type'] = 'automation';
                        $auto['id'] = $auto['id'] ?? '';
                        $auto['settings'] = [
                            'title' => $auto['settings']['title'] ?? ($auto['id'] ?? ''),
                            'subject_line' => $auto['settings']['from_name'] ?? '',
                        ];
                        $auto['recipients'] = [
                            'list_id' => $auto['recipients']['list_id'] ?? null,
                        ];
                        $auto['emails_sent'] = $auto['emails_sent'] ?? 0;
                        $normalized[] = $auto;
                    }

                    $autoCollection = MailchimpConvert::campaigns($normalized, $accountId);
                    if ($this->dataProcessor && $autoCollection->count() > 0) {
                        ($this->dataProcessor)($autoCollection, 'campaign');
                    }
                });
            } catch (Exception $e) {
                $this->logger?->info("Note: automations endpoint skipped or not supported: " . $e->getMessage());
            }
        }

        return new Response(json_encode(['status' => 'success', 'message' => 'Mailchimp entities synced']), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Executes Atomic Event & Order Ingestion (Raw activity streams and connected store purchases).
     */
    public function sync(
        DateTime $startDate,
        DateTime $endDate,
        array $config = [],
        ?callable $shouldContinue = null,
        ?callable $identityMapper = null
    ): Response {
        if (!$this->dataProcessor) {
            throw new Exception("DataProcessor not set for MailchimpDriver");
        }

        $auth = $this->authProvider instanceof MultiAccountAuthProviderInterface
            ? $this->authProvider
            : new MailchimpAuthProvider();

        $accounts = $auth->getAccounts();
        $this->logger?->info(sprintf("Starting Mailchimp atomic activity sync across %d accounts (%s to %s)...", count($accounts), $startDate->format('Y-m-d'), $endDate->format('Y-m-d')));

        $sinceDate = $startDate->format('Y-m-d\TH:i:sP');

        foreach ($accounts as $accountId => $accountDescriptor) {
            if ($shouldContinue && !$shouldContinue()) {
                throw new Exception("Sync aborted by orchestrator.");
            }

            if (!$accountDescriptor['is_valid']) {
                continue;
            }

            $api = $this->getApi(['account_id' => $accountId]);

            // 1. Stream Campaign Activity Events
            $campaignsData = $api->getAllCampaigns();
            $startTimestamp = $startDate->getTimestamp();
            $endTimestamp = $endDate->getTimestamp();

            // Collect all campaign entities to process (regular, automated, RSS, etc.)
            $allCampaignsToSync = $campaignsData['campaigns'] ?? [];

            try {
                $automationsData = $api->getAllAutomations();
                foreach ($automationsData['automations'] ?? [] as $auto) {
                    $workflowId = (string)($auto['id'] ?? '');
                    if (!empty($workflowId)) {
                        // Ingest workflow emails as campaigns
                        $emailsResponse = $api->getAutomationEmails($workflowId);
                        foreach ($emailsResponse['emails'] ?? [] as $wEmail) {
                            $wEmail['type'] = 'automation';
                            $wEmail['recipients'] = [
                                'list_id' => $auto['recipients']['list_id'] ?? null,
                            ];
                            $allCampaignsToSync[] = $wEmail;
                        }
                    }
                }
            } catch (Exception $e) {
                // Not all accounts have automations permissions/endpoints
                $this->logger?->info("Automations activity stream skipped: " . $e->getMessage());
            }

            // Ingest Campaign & Automation Entities into channeled_campaigns
            if (!empty($allCampaignsToSync)) {
                $campaignCollection = MailchimpConvert::campaigns($allCampaignsToSync, $accountId);
                if ($this->dataProcessor && $campaignCollection->count() > 0) {
                    ($this->dataProcessor)($campaignCollection, 'campaign');
                }
            }

            foreach ($allCampaignsToSync as $campaign) {
                if ($shouldContinue && !$shouldContinue()) {
                    throw new Exception("Sync aborted by orchestrator.");
                }

                $campaignId = (string)($campaign['id'] ?? '');
                if (empty($campaignId)) {
                    continue;
                }

                $listId = (string)($campaign['recipients']['list_id'] ?? $accountId);
                $campaignSendTime = (string)($campaign['send_time'] ?? ($campaign['create_time'] ?? ''));
                $campSendTs = !empty($campaignSendTime) ? strtotime($campaignSendTime) : 0;

                // Check if campaign was sent in this window, OR if send_time is absent/ongoing
                $campaignInWindow = ($campSendTs === 0 || ($campSendTs >= $startTimestamp && $campSendTs <= $endTimestamp));

                // 1a. Stream recipient engagement (opens, clicks, bounces)
                $api->getAllEmailActivityAndProcess($campaignId, function ($activity) use ($campaignId, $listId, $accountId, $shouldContinue) {
                    if ($shouldContinue && !$shouldContinue()) {
                        throw new Exception("Sync aborted by orchestrator.");
                    }
                    $events = MailchimpConvert::events($activity, $campaignId, $listId, $accountId);
                    if ($this->dataProcessor && $events->count() > 0) {
                        ($this->dataProcessor)($events, 'event');
                    }
                }, batchSize: 1000, since: $sinceDate);

                // 1b. Stream sent recipients only if the campaign was sent in this timeframe
                if ($campaignInWindow) {
                    $api->getAllSentToMembersAndProcess($campaignId, function ($sentMembers) use ($campaignId, $listId, $accountId, $campaignSendTime, $startTimestamp, $endTimestamp, $shouldContinue) {
                        if ($shouldContinue && !$shouldContinue()) {
                            throw new Exception("Sync aborted by orchestrator.");
                        }

                        // Filter sent members to window if their last_changed date is available
                        $filtered = array_filter($sentMembers, function ($m) use ($startTimestamp, $endTimestamp, $campaignSendTime) {
                            $memberTs = !empty($m['last_changed']) ? strtotime($m['last_changed']) : (!empty($campaignSendTime) ? strtotime($campaignSendTime) : 0);
                            return $memberTs === 0 || ($memberTs >= $startTimestamp && $memberTs <= $endTimestamp);
                        });

                        $events = MailchimpConvert::sentToEvents($filtered, $campaignId, $listId, $accountId, $campaignSendTime);
                        if ($this->dataProcessor && $events->count() > 0) {
                            ($this->dataProcessor)($events, 'event');
                        }
                    }, batchSize: 1000);
                }

                // 1c. Stream unsubscribed recipients
                if ($campaignInWindow) {
                    $api->getAllUnsubscribedMembersAndProcess($campaignId, function ($unsubMembers) use ($campaignId, $listId, $accountId, $campaignSendTime, $startTimestamp, $endTimestamp, $shouldContinue) {
                        if ($shouldContinue && !$shouldContinue()) {
                            throw new Exception("Sync aborted by orchestrator.");
                        }

                        $filtered = array_filter($unsubMembers, function ($m) use ($startTimestamp, $endTimestamp, $campaignSendTime) {
                            $memberTs = !empty($m['timestamp']) ? strtotime($m['timestamp']) : (!empty($campaignSendTime) ? strtotime($campaignSendTime) : 0);
                            return $memberTs === 0 || ($memberTs >= $startTimestamp && $memberTs <= $endTimestamp);
                        });

                        $events = MailchimpConvert::unsubscribeEvents($filtered, $campaignId, $listId, $accountId, $campaignSendTime);
                        if ($this->dataProcessor && $events->count() > 0) {
                            ($this->dataProcessor)($events, 'event');
                        }
                    }, batchSize: 1000);
                }
            }

            // 2. Stream Connected Store Orders
            $storesData = $api->getAllEcommerceStores();
            foreach ($storesData['stores'] ?? [] as $store) {
                $storeId = (string)($store['id'] ?? '');
                if (empty($storeId)) {
                    continue;
                }

                $api->getAllEcommerceOrdersAndProcess($storeId, function ($orders) use ($storeId, $accountId) {
                    $ordersCollection = MailchimpConvert::orders($orders, $storeId, $accountId);
                    if ($this->dataProcessor && $ordersCollection->count() > 0) {
                        ($this->dataProcessor)($ordersCollection, 'order');
                    }
                }, batchSize: 1000);
            }
        }

        return new Response(json_encode(['status' => 'success', 'message' => 'Mailchimp atomic events synced']), 200, ['Content-Type' => 'application/json']);
    }
}

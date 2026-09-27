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

        if (!empty($credentials['accounts']) && is_array($credentials['accounts'])) {
            foreach ($credentials['accounts'] as $accountId => $account) {
                if (is_array($account)) {
                    $auth->storeAccountCredentials((string) $accountId, $account);
                }
            }

            $legacy = $auth->getCredentialsForAccount('default');
            if (is_array($legacy) && !empty($legacy['accounts']) && is_array($legacy['accounts'])) {
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

        // Flat structure (same as YAML file) - merge root-level fields
        $merged = array_merge($currentConfig, $newData);

        // Unpack audiences from assets.audiences to flat audiences
        $merged['audiences'] = $selectedAudiences;
        unset($merged['assets']);

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
            'sends' => ['sends', 'emails_sent'],
            'opens' => ['opens_total', 'opens_standard'],
            'clicks' => ['clicks_total', 'clicks_unique'],
            'bounces' => ['bounces_total', 'bounces_hard', 'bounces_soft'],
            'unsubscribes' => ['unsubscribes'],
            'orders' => ['orders_count'],
            'revenue' => ['revenue', 'total_revenue'],
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
                    'opens_standard' => ['condition' => ['action' => 'open', 'is_proxy' => false], 'reducer' => 'count'],
                    'opens_proxy' => ['condition' => ['action' => 'open', 'is_proxy' => true], 'reducer' => 'count'],
                    'clicks_total' => ['condition' => ['action' => 'click'], 'reducer' => 'count'],
                    'clicks_unique' => ['condition' => ['action' => 'click'], 'field' => 'identity_hash', 'reducer' => 'count_distinct'],
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

            // 4. Sync Campaigns and their Tracked CTA Links
            $api->getAllCampaignsAndProcess(function ($campaigns) use ($api, $accountId) {
                $campaignCollection = MailchimpConvert::campaigns($campaigns, $accountId);
                if ($this->dataProcessor && $campaignCollection->count() > 0) {
                    ($this->dataProcessor)($campaignCollection, $this->logger);
                }

                foreach ($campaigns as $camp) {
                    $campaignId = (string)($camp['id'] ?? '');
                    if (!empty($campaignId)) {
                        $api->getAllClickDetailsAndProcess($campaignId, function ($links) use ($campaignId, $accountId) {
                            $linksCollection = MailchimpConvert::links($links, $campaignId, $accountId);
                            if ($this->dataProcessor && $linksCollection->count() > 0) {
                                ($this->dataProcessor)($linksCollection, $this->logger);
                            }
                        });
                    }
                }
            });
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
            foreach ($campaignsData['campaigns'] ?? [] as $campaign) {
                $campaignId = (string)($campaign['id'] ?? '');
                if (empty($campaignId)) {
                    continue;
                }

                $api->getAllEmailActivityAndProcess($campaignId, function ($activity) use ($campaignId, $accountId) {
                    $events = MailchimpConvert::events($activity, $campaignId, $accountId);
                    if ($this->dataProcessor && $events->count() > 0) {
                        ($this->dataProcessor)($events, $this->logger);
                    }
                }, batchSize: 1000, since: $sinceDate);
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
                        ($this->dataProcessor)($ordersCollection, $this->logger);
                    }
                }, batchSize: 1000);
            }
        }

        return new Response(json_encode(['status' => 'success', 'message' => 'Mailchimp atomic events synced']), 200, ['Content-Type' => 'application/json']);
    }
}

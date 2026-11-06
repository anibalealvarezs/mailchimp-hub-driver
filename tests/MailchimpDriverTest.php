<?php

declare(strict_types=1);

namespace Anibalealvarezs\MailchimpHubDriver\Tests;

use Anibalealvarezs\MailchimpHubDriver\Auth\MailchimpAuthProvider;
use Anibalealvarezs\MailchimpHubDriver\Conversions\MailchimpConvert;
use Anibalealvarezs\MailchimpHubDriver\Drivers\MailchimpDriver;
use PHPUnit\Framework\TestCase;

class MailchimpDriverTest extends TestCase
{
    private string $tempTokenPath;

    protected function setUp(): void
    {
        $this->tempTokenPath = sys_get_temp_dir() . '/test_mailchimp_tokens_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        putenv('MAILCHIMP_TOKEN_PATH');
        unset($_ENV['MAILCHIMP_TOKEN_PATH']);

        if (file_exists($this->tempTokenPath)) {
            unlink($this->tempTokenPath);
        }
    }

    public function testOrdersPreserveStoreAudienceCurrencyAndScopedIdentity(): void
    {
        $order = ['id' => 'same-order', 'processed_at_foreign' => '2026-11-01T12:00:00+00:00',
            'order_total' => 12.50, 'currency_code' => 'EUR'];
        $first = MailchimpConvert::orders([$order], 'store-a', 'account', 'audience')->first();
        $second = MailchimpConvert::orders([$order], 'store-b', 'account', 'audience')->first();
        $repeat = MailchimpConvert::orders([$order], 'store-a', 'account', 'audience')->first();

        self::assertNotSame($first->getPlatformId(), $second->getPlatformId());
        self::assertSame($first->getPlatformId(), $repeat->getPlatformId());
        self::assertSame('store-a', $first->store_id);
        self::assertSame('audience', $first->audience_id);
        self::assertSame('EUR', $first->currency);
        self::assertSame('store-a', $first->getData()['store_id']);
        self::assertSame('audience', $first->getData()['audience_id']);
        self::assertSame(12.50, $first->getData()['total_amount']);
        self::assertSame('same-order', $first->source_order_id);
        self::assertSame('GBP', MailchimpConvert::orders([['id' => 'no-currency']], 'store-a', 'account', 'audience', 'GBP')->first()->getData()['currency']);
        self::assertSame('2026-11-01', $first->platformCreatedAt->format('Y-m-d'));
        self::assertNull(MailchimpConvert::orders([['id' => 'undated']], 'store-a', 'account', 'audience')->first()->platformCreatedAt);
    }

    public function testDailyUniqueOpenersUseRecipientIdentityWithinCampaign(): void
    {
        $engagement = MailchimpDriver::getPreAggregationRules()['campaign_engagement'];

        $this->assertSame('campaign_id', $engagement['scope_field']);
        $this->assertSame([
            'condition' => ['action' => 'open'],
            'field' => 'identity_hash',
            'reducer' => 'count_distinct',
        ], $engagement['metrics']['opens_unique_daily']);
    }

    public function testCommerceIdentitiesPreserveParentsAndUnknownAmounts(): void
    {
        $unknown = MailchimpConvert::orders([['id' => 'unknown']], 'store', 'account', 'audience')->first();
        self::assertNull($unknown->getData()['total_amount']);
        self::assertNull($unknown->financial_status);
        $product = MailchimpConvert::products([['id' => 'product', 'title' => 'Product',
            'variants' => [['id' => 'variant', 'sku' => 'SKU']]]], 'store', 'account', 'audience')->first();
        $order = MailchimpConvert::orders([['id' => 'order', 'order_total' => 0,
            'lines' => [['product_id' => 'product', 'product_variant_id' => 'variant']]]], 'store', 'account', 'audience')->first();
        self::assertSame($product->getPlatformId(), $order->lineItems[0]['product_id']);
        self::assertSame($product->variants[0]->platformId, $order->lineItems[0]['variant_id']);
        self::assertSame(0.0, $order->getData()['total_amount']);
        $rules = MailchimpConvert::promotions([
            ['id' => 'rule-a', 'codes' => [['id' => 'same-id', 'code' => 'SAME']]],
            ['id' => 'rule-b', 'codes' => [['id' => 'same-id', 'code' => 'SAME']]],
        ], 'store', 'account', 'audience')->toArray();
        self::assertNotSame($rules[0]->getData()['codes'][0]['identity'], $rules[1]->getData()['codes'][0]['identity']);
    }

    public function testEntitySyncScopesStoresAndUsesExplicitCommerceCallbacks(): void
    {
        $auth = new MailchimpAuthProvider($this->tempTokenPath);
        $auth->storeAccountCredentials('account', ['api_key' => 'fixture-us1']);
        $api = $this->createMock(\Anibalealvarezs\MailchimpApi\Services\Marketing\MarketingApi::class);
        $api->method('getAllListsInfoAndProcess')->willReturnCallback(static function (callable $callback): void {
            $callback([['id' => 'allowed', 'name' => 'Allowed'], ['id' => 'hidden', 'name' => 'Hidden']]);
        });
        $api->method('getAllEcommerceStoresAndProcess')->willReturnCallback(static function (callable $callback): void {
            $callback([['id' => 'store', 'list_id' => 'allowed'], ['id' => 'hidden-store', 'list_id' => 'hidden']]);
        });
        $api->expects(self::once())->method('getAllEcommerceProductsAndProcess')->with('store', self::isType('callable'), 1000, self::isType('callable'))
            ->willReturnCallback(static function (string $store, callable $callback): void {
                $callback([['id' => 'product', 'title' => 'Product']]);
            });
        $api->expects(self::once())->method('getAllEcommerceProductVariantsAndProcess')->willReturnCallback(
            static function (string $store, string $product, callable $callback): void { $callback([['id' => 'variant']]); }
        );
        $api->expects(self::once())->method('getAllEcommerceCustomersAndProcess')->willReturnCallback(
            static function (string $store, callable $callback): void { $callback([['id' => 'customer']]); }
        );
        $api->expects(self::once())->method('getAllEcommercePromoRulesAndProcess')->willReturnCallback(
            static function (string $store, callable $callback): void { $callback([['id' => 'rule']]); }
        );
        $api->expects(self::once())->method('getAllEcommercePromoCodesAndProcess')->willReturnCallback(
            static function (string $store, string $rule, callable $callback): void { $callback([['id' => 'code', 'code' => 'SALE']]); }
        );
        $api->expects(self::never())->method('getAllEcommerceCartsAndProcess');
        $driver = $this->getMockBuilder(MailchimpDriver::class)->setConstructorArgs([$auth])->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $batches = [];
        $driver->setDataProcessor(static function ($records, string $type) use (&$batches): void { $batches[$type][] = $records; });
        self::assertSame('entities', MailchimpDriver::getInstanceRules()['entities_sync']);
        $api->expects(self::never())->method('getAllEcommerceOrdersAndProcess');
        $driver->sync(new \DateTime('2026-11-01'), new \DateTime('2026-11-02'),
            ['entity' => 'entities', 'audiences' => [['id' => 'allowed', 'enabled' => true]]], static fn (): bool => true);
        self::assertSame(['account', 'store', 'product', 'customer', 'price_rule'], array_keys($batches));
        self::assertCount(1, $batches['account'][0]);
        self::assertCount(1, $batches['store']);
        self::assertCount(1, $batches['product'][1]->first()->variants);
        self::assertCount(1, $batches['price_rule'][0]->first()->getData()['codes']);
    }

    public function testMetricSyncPersistsAudienceBeforeStoresWithoutEntitySync(): void
    {
        $auth = new MailchimpAuthProvider($this->tempTokenPath);
        $auth->storeAccountCredentials('account', ['api_key' => 'fixture-us1']);
        $api = $this->createMock(\Anibalealvarezs\MailchimpApi\Services\Marketing\MarketingApi::class);
        $api->expects(self::once())->method('getAllListsInfoAndProcess')
            ->with(self::isType('callable'), true)
            ->willReturnCallback(static function (callable $callback): void {
                $callback([['id' => 'allowed', 'name' => 'Audience'], ['id' => 'hidden']]);
            });
        $api->method('getAllEcommerceStoresAndProcess')->willReturnCallback(static function (callable $callback): void {
            $callback([['id' => 'hidden-store', 'list_id' => 'hidden']]);
            $callback([['id' => 'a', 'list_id' => 'allowed'], ['id' => 'b', 'list_id' => 'allowed']]);
        });
        $api->expects(self::exactly(2))->method('getAllEcommerceOrdersAndProcess')
            ->willReturnCallback(static function (string $store, callable $callback): void {
                $callback([['id' => 'order', 'order_total' => 5]]);
            });
        $driver = $this->getMockBuilder(MailchimpDriver::class)->setConstructorArgs([$auth])->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $types = [];
        $driver->setDataProcessor(static function ($records, string $type) use (&$types): void {
            $types[] = $type;
            self::assertCount(1, $records);
            if ($type === 'account') self::assertSame('account', $records->first()->account_id);
        });
        $driver->sync(new \DateTime('2026-11-01'), new \DateTime('2026-11-02'),
            ['audiences' => [['id' => 'allowed', 'enabled' => true]]], static fn (): bool => true);
        self::assertSame(['account', 'store', 'order', 'store', 'order'], $types);
    }

    public function testStoreCredentialsUnpacksMultiAccountPayload(): void
    {
        putenv('MAILCHIMP_TOKEN_PATH=' . $this->tempTokenPath);
        $_ENV['MAILCHIMP_TOKEN_PATH'] = $this->tempTokenPath;

        MailchimpDriver::storeCredentials([
            'access_token' => '',
            'accounts' => [
                'mc_a' => ['api_key' => 'key-us4', 'server_prefix' => 'us4', 'account_name' => 'Client A'],
                'mc_b' => ['api_key' => 'key-us6', 'server_prefix' => 'us6', 'account_name' => 'Client B'],
            ],
        ]);

        $provider = new MailchimpAuthProvider($this->tempTokenPath);
        $accounts = $provider->getAccounts();

        $this->assertCount(2, $accounts);
        $this->assertArrayHasKey('mc_a', $accounts);
        $this->assertArrayHasKey('mc_b', $accounts);
        $this->assertArrayNotHasKey('default', $accounts);
        $this->assertTrue($accounts['mc_a']['is_valid']);
        $this->assertTrue($accounts['mc_b']['is_valid']);
        $this->assertEquals('key-us4', $provider->getCredentialsForAccount('mc_a')['api_key']);
        $this->assertEquals('mc_a', $provider->getActiveAccountId());
    }

    public function testStoreCredentialsPurgesLegacyNestedDefaultEntry(): void
    {
        file_put_contents($this->tempTokenPath, json_encode([
            'accounts' => [
                'default' => [
                    'accounts' => ['mc_legacy' => ['api_key' => 'key-us1']],
                    'access_token' => '',
                ],
            ],
        ]));

        putenv('MAILCHIMP_TOKEN_PATH=' . $this->tempTokenPath);
        $_ENV['MAILCHIMP_TOKEN_PATH'] = $this->tempTokenPath;

        MailchimpDriver::storeCredentials([
            'accounts' => ['mc_a' => ['api_key' => 'key-us4']],
        ]);

        $provider = new MailchimpAuthProvider($this->tempTokenPath);
        $accounts = $provider->getAccounts();

        $this->assertArrayNotHasKey('default', $accounts);
        $this->assertArrayHasKey('mc_a', $accounts);
        $this->assertEquals('mc_a', $provider->getActiveAccountId());
    }

    public function testStoreCredentialsKeepsSingleAccountFallback(): void
    {
        putenv('MAILCHIMP_TOKEN_PATH=' . $this->tempTokenPath);
        $_ENV['MAILCHIMP_TOKEN_PATH'] = $this->tempTokenPath;

        MailchimpDriver::storeCredentials([
            'account_id' => 'mc_single',
            'api_key' => 'key-us7',
        ]);

        $provider = new MailchimpAuthProvider($this->tempTokenPath);
        $accounts = $provider->getAccounts();

        $this->assertCount(1, $accounts);
        $this->assertArrayHasKey('mc_single', $accounts);
        $this->assertTrue($accounts['mc_single']['is_valid']);
    }

    public function testAuthProviderMultiAccountStorage(): void
    {
        $provider = new MailchimpAuthProvider($this->tempTokenPath);
        $this->assertEmpty($provider->getAccounts());

        $provider->storeAccountCredentials('client_a', [
            'api_key' => 'key-us4',
            'server_prefix' => 'us4',
            'account_name' => 'Client A',
        ]);

        $provider->storeAccountCredentials('client_b', [
            'api_key' => 'key-us6',
            'server_prefix' => 'us6',
            'account_name' => 'Client B',
        ]);

        $accounts = $provider->getAccounts();
        $this->assertCount(2, $accounts);
        $this->assertTrue($accounts['client_a']['is_valid']);
        $this->assertEquals('Client A', $accounts['client_a']['name']);

        $credA = $provider->getCredentialsForAccount('client_a');
        $this->assertEquals('key-us4', $credA['api_key']);

        $provider->removeAccountCredentials('client_a');
        $this->assertCount(1, $provider->getAccounts());
    }

    public function testConversionsMapping(): void
    {
        $audiences = [
            [
                'id' => 'list_123',
                'name' => 'Newsletter VIP',
                'date_created' => '2026-01-01 00:00:00',
                'stats' => ['member_count' => 500, 'unsubscribe_count' => 10, 'campaign_count' => 2],
            ]
        ];

        $convertedAudiences = MailchimpConvert::audiences($audiences, 'acc_1');
        $this->assertCount(1, $convertedAudiences);
        $this->assertEquals('list_123', $convertedAudiences->first()->getPlatformId());
        $this->assertEquals('Newsletter VIP', $convertedAudiences->first()->getTitle());

        $orders = [
            [
                'id' => 'ord_999',
                'created_at' => '2026-09-26 12:00:00',
                'order_total' => 125.50,
                'customer' => ['email_address' => 'buyer@example.com'],
            ]
        ];

        $convertedOrders = MailchimpConvert::orders($orders, 'store_abc', 'acc_1');
        $this->assertCount(1, $convertedOrders);
        $this->assertEquals(hash('sha256', json_encode(['acc_1', 'store_abc', 'ord_999'])), $convertedOrders->first()->getPlatformId());
        $this->assertEquals(md5('buyer@example.com'), $convertedOrders->first()->identity_hash);

        // Test sentToEvents
        $sentTo = [
            [
                'email_id' => 'abc123hash',
                'email_address' => 'subscriber@example.com',
                'status' => 'sent',
                'last_changed' => '2026-04-17 10:00:00',
            ]
        ];
        $convertedSends = MailchimpConvert::sentToEvents($sentTo, 'camp_1', 'list_123', 'acc_1');
        $this->assertCount(1, $convertedSends);
        $this->assertEquals('send', $convertedSends->first()->action);
        $this->assertEquals('abc123hash', $convertedSends->first()->identity_hash);

        // Test unsubscribeEvents
        $unsubs = [
            [
                'email_id' => 'unsub123hash',
                'email_address' => 'unsub@example.com',
                'timestamp' => '2026-04-18 11:00:00',
                'reason' => 'spam',
            ]
        ];
        $convertedUnsubs = MailchimpConvert::unsubscribeEvents($unsubs, 'camp_1', 'list_123', 'acc_1');
        $this->assertCount(1, $convertedUnsubs);
        $this->assertEquals('unsubscribe', $convertedUnsubs->first()->action);
        $this->assertEquals('unsub123hash', $convertedUnsubs->first()->identity_hash);
    }

    public function testGetChanneledAccountsMapsDiscoveredAudience(): void
    {
        $accounts = MailchimpDriver::getChanneledAccounts([
            'account_id' => 'mc_a',
            'platform_id' => 'list_123',
            'name' => 'Newsletter VIP',
            'type' => 'audience',
            'enabled' => true,
        ]);

        $this->assertCount(1, $accounts);
        $this->assertEquals('list_123', $accounts[0]['platformId']);
        $this->assertEquals('Newsletter VIP', $accounts[0]['name']);
        $this->assertEquals('audience', $accounts[0]['type']);
        $this->assertTrue($accounts[0]['enabled']);
        $this->assertNull($accounts[0]['platformCreatedAt']);
        $this->assertSame([], $accounts[0]['data']);
    }

    public function testGetChanneledAccountsAcceptsIdKeyAndDisabledFlag(): void
    {
        $accounts = MailchimpDriver::getChanneledAccounts([
            'id' => 'list_456',
            'name' => 'Seasonal Sale',
            'enabled' => false,
            'date_created' => '2026-01-01 00:00:00',
            'data' => ['member_count' => 12],
        ]);

        $this->assertCount(1, $accounts);
        $this->assertEquals('list_456', $accounts[0]['platformId']);
        $this->assertEquals('2026-01-01 00:00:00', $accounts[0]['platformCreatedAt']);
        $this->assertFalse($accounts[0]['enabled']);
        $this->assertSame(['member_count' => 12], $accounts[0]['data']);
    }

    public function testGetChanneledAccountsReturnsEmptyWithoutPlatformId(): void
    {
        $this->assertSame([], MailchimpDriver::getChanneledAccounts(['name' => 'No Id']));
    }

    public function testGetEnvMappingUsesNestedPerChannelShape(): void
    {
        $mapping = MailchimpDriver::getEnvMapping();

        $this->assertArrayHasKey('mailchimp', $mapping);
        $this->assertIsArray($mapping['mailchimp']);
        $this->assertSame(['MAILCHIMP_TOKEN_PATH' => 'token_path'], $mapping['mailchimp']);

        foreach ($mapping as $targetChan => $envMap) {
            $this->assertIsArray(
                $envMap,
                sprintf('Mapping for "%s" must be an array; a flat [ENV => key] shape makes Helpers::getChannelsConfig() foreach a string.', $targetChan)
            );

            foreach ($envMap as $envKey => $configKey) {
                $this->assertIsString($configKey);
                $this->assertMatchesRegularExpression('/^[A-Z0-9_]+$/', (string) $envKey, 'Keys must be env var names; Helpers uses them as getenv() arguments.');
            }
        }
    }

    public function testPreAggregationRulesContract(): void
    {
        $rules = MailchimpDriver::getPreAggregationRules();
        $this->assertArrayHasKey('campaign_engagement', $rules);
        $this->assertArrayHasKey('store_conversions', $rules);

        $metrics = $rules['campaign_engagement']['metrics'];
        $this->assertArrayHasKey('sends', $metrics);
        $this->assertArrayHasKey('opens_standard', $metrics);
        $this->assertArrayHasKey('opens_proxy', $metrics);
        $this->assertArrayHasKey('clicks_total', $metrics);
        $this->assertArrayHasKey('clicks_unique', $metrics);

        $this->assertEquals(30, MailchimpDriver::getDefaultAttributionWindowDays());
        $this->assertEquals(2, MailchimpDriver::getDefaultMaxWorkers());
    }
}

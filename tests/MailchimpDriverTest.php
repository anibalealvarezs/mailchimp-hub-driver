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
        $this->assertEquals('ord_999', $convertedOrders->first()->getPlatformId());
        $this->assertEquals(md5('buyer@example.com'), $convertedOrders->first()->identity_hash);
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
    }
}

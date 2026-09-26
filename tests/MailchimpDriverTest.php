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
        if (file_exists($this->tempTokenPath)) {
            unlink($this->tempTokenPath);
        }
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

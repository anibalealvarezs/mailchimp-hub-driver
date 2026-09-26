<?php

declare(strict_types=1);

namespace Anibalealvarezs\MailchimpHubDriver\Auth;

use Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider;
use Anibalealvarezs\ApiDriverCore\Interfaces\MultiAccountAuthProviderInterface;
use Anibalealvarezs\MailchimpApi\Services\Marketing\MarketingApi;
use Exception;

class MailchimpAuthProvider extends BaseAuthProvider implements MultiAccountAuthProviderInterface
{
    private string $activeAccountId = '';

    public function __construct(array|string $configOrPath = '')
    {
        if (is_array($configOrPath)) {
            $configOrPath = $configOrPath['token_path'] ?? $_ENV['MAILCHIMP_TOKEN_PATH'] ?? getenv('MAILCHIMP_TOKEN_PATH') ?: (getcwd() . '/storage/tokens/mailchimp_tokens.json');
        } elseif (!$configOrPath || (is_string($configOrPath) && empty($configOrPath))) {
            $configOrPath = $_ENV['MAILCHIMP_TOKEN_PATH'] ?? getenv('MAILCHIMP_TOKEN_PATH') ?: (getcwd() . '/storage/tokens/mailchimp_tokens.json');
        }

        parent::__construct($configOrPath);
    }

    public function setActiveAccountId(string $accountId): self
    {
        $this->activeAccountId = $accountId;
        return $this;
    }

    public function getActiveAccountId(): string
    {
        if (!empty($this->activeAccountId)) {
            return $this->activeAccountId;
        }

        $accounts = $this->getAccounts();
        if (!empty($accounts)) {
            return (string) array_key_first($accounts);
        }

        return '';
    }

    /**
     * @return array<string, array{account_id: string, name: ?string, is_valid: bool}>
     */
    public function getAccounts(): array
    {
        $accounts = $this->data['accounts'] ?? [];
        $result = [];

        foreach ($accounts as $id => $acc) {
            $result[(string)$id] = [
                'account_id' => (string)$id,
                'name' => $acc['account_name'] ?? ($acc['name'] ?? null),
                'is_valid' => !empty($acc['api_key']) || !empty($acc['access_token']),
            ];
        }

        return $result;
    }

    public function getCredentialsForAccount(string $accountId): ?array
    {
        return $this->data['accounts'][$accountId] ?? null;
    }

    public function storeAccountCredentials(string $accountId, array $credentials): void
    {
        if (!isset($this->data['accounts'])) {
            $this->data['accounts'] = [];
        }

        $this->data['accounts'][$accountId] = array_merge(
            $this->data['accounts'][$accountId] ?? [],
            $credentials,
            ['updated_at' => date('Y-m-d H:i:s')]
        );

        $this->save();
    }

    public function removeAccountCredentials(string $accountId): void
    {
        if (isset($this->data['accounts'][$accountId])) {
            unset($this->data['accounts'][$accountId]);
            $this->save();
        }
    }

    public function getAccessToken(): string
    {
        $acc = $this->getCredentialsForAccount($this->getActiveAccountId());
        return (string)($acc['access_token'] ?? ($acc['api_key'] ?? ''));
    }

    public function setAccessToken(string $token): void
    {
        $activeId = $this->getActiveAccountId();
        if (!empty($activeId)) {
            $this->storeAccountCredentials($activeId, ['access_token' => $token]);
        }
    }

    public function getUserId(): string
    {
        return $this->getActiveAccountId();
    }

    public function isValid(): bool
    {
        return !empty($this->getAccessToken());
    }

    public function hasCredentials(): bool
    {
        if (!$this->filePath || !file_exists($this->filePath)) {
            return false;
        }

        return !empty($this->getAccounts());
    }

    public function isExpired(): bool
    {
        return false;
    }

    public function refresh(): bool
    {
        return true;
    }

    public function getScopes(): array
    {
        return [];
    }

    public function updateCredentials(array $credentials): void
    {
        $accountId = $credentials['account_id'] ?? $this->getActiveAccountId();
        if (!empty($accountId)) {
            $this->storeAccountCredentials((string)$accountId, $credentials);
        }
    }

    /**
     * Validates live connectivity for all configured accounts via Mailchimp ping().
     *
     * @return array<string, array{valid: bool, status: string, detail?: string}>
     */
    public function validateAuthentication(): array
    {
        $statusReport = [];
        foreach ($this->getAccounts() as $accountId => $accountDescriptor) {
            $credentials = $this->getCredentialsForAccount($accountId);
            $apiKey = $credentials['api_key'] ?? null;
            $accessToken = $credentials['access_token'] ?? null;
            $serverPrefix = $credentials['server_prefix'] ?? 'us1';

            if (!$apiKey && !$accessToken) {
                $statusReport[$accountId] = [
                    'valid' => false,
                    'status' => 'missing_credentials',
                ];
                continue;
            }

            try {
                $client = new MarketingApi(
                    apiKey: $apiKey,
                    serverPrefix: $serverPrefix,
                    accessToken: $accessToken,
                );
                $ping = $client->ping();
                $isValid = ($ping['health_status'] ?? '') === "Everything's Chimpy!";
                $statusReport[$accountId] = [
                    'valid' => $isValid,
                    'status' => $isValid ? 'connected' : 'unhealthy',
                ];
            } catch (Exception $e) {
                $statusReport[$accountId] = [
                    'valid' => false,
                    'status' => 'error',
                    'detail' => $e->getMessage(),
                ];
            }
        }

        return $statusReport;
    }
}

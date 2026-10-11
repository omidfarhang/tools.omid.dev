<?php

declare(strict_types=1);

namespace App;

final class DropboxOAuth
{
    private const AUTH_URL = 'https://www.dropbox.com/oauth2/authorize';
    private const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';

    public function isConfigured(): bool
    {
        return Config::get('dropbox_app_key') !== '' && Config::get('dropbox_app_secret') !== '';
    }

    public function redirectUri(): string
    {
        return Config::baseUrl() . '/oauth/dropbox/callback.php';
    }

    public function authorizeUrl(string $state, bool $forceReapprove = false): string
    {
        $params = [
            'client_id' => Config::get('dropbox_app_key'),
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ];
        if ($forceReapprove) {
            // Required so Dropbox shows consent again when app scopes change.
            $params['force_reapprove'] = 'true';
        }

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /** @return array{ok:bool, access_token?:string, refresh_token?:string, expires_in?:int, account_id?:string, error?:string} */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /** @return array{ok:bool, access_token?:string, refresh_token?:string, expires_in?:int, account_id?:string, error?:string} */
    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
    }

    /**
     * Requires account_info.read.
     *
     * @return array{ok:bool, account_id?:string, email?:string, display_name?:string, error?:string}
     */
    public function getCurrentAccount(string $accessToken): array
    {
        $ch = curl_init('https://api.dropboxapi.com/2/users/get_current_account');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => 'null',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => $err ?: 'Account info request failed'];
        }

        $data = json_decode($body, true);
        if ($code !== 200 || !is_array($data)) {
            return [
                'ok' => false,
                'error' => is_array($data) ? (string) ($data['error_summary'] ?? $data['error'] ?? 'Account info error') : 'Account info error',
            ];
        }

        $name = is_array($data['name'] ?? null) ? $data['name'] : [];

        return [
            'ok' => true,
            'account_id' => isset($data['account_id']) ? (string) $data['account_id'] : '',
            'email' => isset($data['email']) ? (string) $data['email'] : '',
            'display_name' => isset($name['display_name']) ? (string) $name['display_name'] : '',
        ];
    }

    /** @param array<string, string> $params */
    private function tokenRequest(array $params): array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => Config::get('dropbox_app_key') . ':' . Config::get('dropbox_app_secret'),
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => $err ?: 'Token request failed'];
        }

        $data = json_decode($body, true);
        if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
            return [
                'ok' => false,
                'error' => is_array($data) ? ($data['error_description'] ?? $data['error'] ?? 'OAuth error') : 'OAuth error',
            ];
        }

        return [
            'ok' => true,
            'access_token' => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : null,
            'account_id' => isset($data['account_id']) ? (string) $data['account_id'] : '',
        ];
    }
}

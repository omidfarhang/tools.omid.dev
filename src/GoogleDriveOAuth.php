<?php

declare(strict_types=1);

namespace App;

final class GoogleDriveOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /** @var list<string> */
    private const SCOPES = [
        'https://www.googleapis.com/auth/drive.file',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
    ];

    public function isConfigured(): bool
    {
        return Config::get('google_drive_client_id') !== ''
            && Config::get('google_drive_client_secret') !== '';
    }

    public function redirectUri(): string
    {
        return Config::baseUrl() . '/oauth/google_drive/callback.php';
    }

    public function authorizeUrl(string $state, bool $forceConsent = false): string
    {
        $params = [
            'client_id' => Config::get('google_drive_client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'state' => $state,
            'include_granted_scopes' => 'true',
        ];
        if ($forceConsent) {
            // Required so Google returns a refresh token again after scope / account changes.
            $params['prompt'] = 'consent';
        }

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /** @return array{ok:bool, access_token?:string, refresh_token?:string, expires_in?:int, error?:string} */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /** @return array{ok:bool, access_token?:string, refresh_token?:string, expires_in?:int, error?:string} */
    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
    }

    /**
     * @return array{ok:bool, account_id?:string, email?:string, display_name?:string, error?:string}
     */
    public function getCurrentAccount(string $accessToken): array
    {
        $ch = curl_init(self::USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
            ],
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
                'error' => is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? 'Account info error') : 'Account info error',
            ];
        }

        return [
            'ok' => true,
            'account_id' => isset($data['sub']) ? (string) $data['sub'] : '',
            'email' => isset($data['email']) ? (string) $data['email'] : '',
            'display_name' => isset($data['name']) ? (string) $data['name'] : '',
        ];
    }

    /** @param array<string, string> $params */
    private function tokenRequest(array $params): array
    {
        $params['client_id'] = (string) Config::get('google_drive_client_id');
        $params['client_secret'] = (string) Config::get('google_drive_client_secret');

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
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
                'error' => is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? 'OAuth error') : 'OAuth error',
            ];
        }

        return [
            'ok' => true,
            'access_token' => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : null,
        ];
    }
}

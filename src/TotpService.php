<?php

declare(strict_types=1);

namespace App;

use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;

final class TotpService
{
    private TwoFactorAuth $tfa;

    public function __construct(?string $issuer = null)
    {
        $issuer = $issuer ?? (string) Config::get('app_name', 'Tools Center');
        $this->tfa = new TwoFactorAuth(
            new BaconQrCodeProvider(4, '#ffffff', '#000000', 'svg'),
            $issuer,
        );
    }

    public function createSecret(): string
    {
        return $this->tfa->createSecret();
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($code === '' || !ctype_digit($code)) {
            return false;
        }

        return $this->tfa->verifyCode($secret, $code);
    }

    public function otpauthUri(string $username, string $secret): string
    {
        $label = (string) Config::get('app_name', 'Tools Center') . ':' . $username;

        return $this->tfa->getQRText($label, $secret);
    }

    /**
     * Data-URI for an SVG QR code, or empty string if QR generation is unavailable
     * (e.g. missing ext-iconv). Callers should still show {@see manualKey()}.
     */
    public function qrDataUri(string $username, string $secret, int $size = 200): string
    {
        try {
            return $this->tfa->getQRCodeImageAsDataUri(
                (string) Config::get('app_name', 'Tools Center') . ':' . $username,
                $secret,
                $size
            );
        } catch (\Throwable) {
            return '';
        }
    }

    public function manualKey(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /**
     * @return list<string> Plaintext recovery codes (show once).
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    /**
     * @param list<string> $plainCodes
     * @return list<string> password_hash values
     */
    public function hashRecoveryCodes(array $plainCodes): array
    {
        $hashes = [];
        foreach ($plainCodes as $code) {
            $hashes[] = password_hash(self::normalizeRecoveryCode($code), PASSWORD_DEFAULT);
        }

        return $hashes;
    }

    /**
     * @param list<string> $hashes
     * @return array{ok: bool, hashes: list<string>}
     */
    public function consumeRecoveryCode(array $hashes, string $code): array
    {
        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === '') {
            return ['ok' => false, 'hashes' => $hashes];
        }

        $remaining = [];
        $matched = false;
        foreach ($hashes as $hash) {
            if (!$matched && password_verify($normalized, $hash)) {
                $matched = true;
                continue;
            }
            $remaining[] = $hash;
        }

        return ['ok' => $matched, 'hashes' => $remaining];
    }

    public static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
    }

    /**
     * @return list<string>
     */
    public static function decodeRecoveryHashes(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $hashes
     */
    public static function encodeRecoveryHashes(array $hashes): string
    {
        return json_encode(array_values($hashes), JSON_THROW_ON_ERROR);
    }
}

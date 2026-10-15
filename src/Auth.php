<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    private const PENDING_2FA_KEY = 'pending_2fa_user_id';
    private const TOTP_SETUP_SECRET_KEY = 'totp_setup_secret';

    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }

        return (new UserRepository())->findById((int) $id);
    }

    public static function check(): bool
    {
        $user = self::user();
        return $user !== null && (int) $user['is_active'] === 1;
    }

    /** Fixed bcrypt hash for timing equalization when the username is unknown. */
    private const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    /**
     * @return 'ok'|'inactive'|'invalid'|'totp_required'
     */
    public static function attemptLogin(string $username, string $password): string
    {
        $user = (new UserRepository())->findByUsername($username);
        $hash = is_array($user) ? (string) $user['password_hash'] : self::DUMMY_PASSWORD_HASH;
        $passwordOk = password_verify($password, $hash);

        if ($user === null || !$passwordOk) {
            return 'invalid';
        }
        if ((int) $user['is_active'] !== 1) {
            return 'inactive';
        }

        session_regenerate_id(true);
        unset($_SESSION['user_id'], $_SESSION[self::TOTP_SETUP_SECRET_KEY]);

        if (UserRepository::totpEnabled($user)) {
            $devices = new TrustedDeviceRepository();
            if ($devices->isTrusted((int) $user['id'])) {
                unset($_SESSION[self::PENDING_2FA_KEY]);
                $_SESSION['user_id'] = (int) $user['id'];

                return 'ok';
            }

            $_SESSION[self::PENDING_2FA_KEY] = (int) $user['id'];

            return 'totp_required';
        }

        unset($_SESSION[self::PENDING_2FA_KEY]);
        $_SESSION['user_id'] = (int) $user['id'];

        return 'ok';
    }

    public static function login(string $username, string $password): bool
    {
        return self::attemptLogin($username, $password) === 'ok';
    }

    public static function pendingTotpUserId(): ?int
    {
        $id = $_SESSION[self::PENDING_2FA_KEY] ?? null;
        if ($id === null || $id === '') {
            return null;
        }

        return (int) $id;
    }

    public static function pendingTotpUser(): ?array
    {
        $id = self::pendingTotpUserId();
        if ($id === null) {
            return null;
        }
        $user = (new UserRepository())->findById($id);
        if ($user === null || (int) $user['is_active'] !== 1 || !UserRepository::totpEnabled($user)) {
            self::clearPendingTotp();

            return null;
        }

        return $user;
    }

    public static function clearPendingTotp(): void
    {
        unset($_SESSION[self::PENDING_2FA_KEY]);
    }

    public static function completeTotpLogin(int $userId): void
    {
        session_regenerate_id(true);
        unset($_SESSION[self::PENDING_2FA_KEY], $_SESSION[self::TOTP_SETUP_SECRET_KEY]);
        $_SESSION['user_id'] = $userId;
    }

    public static function setTotpSetupSecret(string $secret): void
    {
        $_SESSION[self::TOTP_SETUP_SECRET_KEY] = $secret;
    }

    public static function totpSetupSecret(): ?string
    {
        $secret = $_SESSION[self::TOTP_SETUP_SECRET_KEY] ?? null;
        if (!is_string($secret) || $secret === '') {
            return null;
        }

        return $secret;
    }

    public static function clearTotpSetupSecret(): void
    {
        unset($_SESSION[self::TOTP_SETUP_SECRET_KEY]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(): array
    {
        if (!self::check()) {
            redirect('/login.php');
        }

        return self::user();
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        return $user;
    }

    public static function attemptCliToken(): ?array
    {
        $expected = (string) Config::get('app_cli_token');
        if ($expected === '') {
            return null;
        }

        $provided = $_SERVER['HTTP_X_CLI_TOKEN'] ?? ($_POST['cli_token'] ?? ($_GET['cli_token'] ?? ''));
        if (!is_string($provided) || !hash_equals($expected, $provided)) {
            return null;
        }

        $username = (string) Config::get('app_cli_username');
        $user = (new UserRepository())->findByUsername($username);
        if ($user === null || (int) $user['is_active'] !== 1) {
            return null;
        }

        return $user;
    }

    public static function requireLoginOrCli(): array
    {
        $cliUser = self::attemptCliToken();
        if ($cliUser !== null) {
            return $cliUser;
        }

        return self::requireLogin();
    }
}

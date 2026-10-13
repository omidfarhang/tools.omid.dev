<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function human_filesize(int|float $bytes, int $decimals = 2): string
{
    $size = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    $bytes = max(0, (float) $bytes);
    $factor = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
    $factor = min($factor, count($size) - 1);

    return sprintf("%.{$decimals}f", $bytes / (1024 ** $factor)) . $size[$factor];
}

function is_youtube(string $url): string|false
{
    return (new \App\Download\YoutubeSource())->extractVideoId($url);
}

function redirect(string $path): never
{
    if (!str_starts_with($path, 'http://') && !str_starts_with($path, 'https://')) {
        $base = rtrim(\App\Config::get('app_base_url'), '/');
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $path = $base . $path;
    }
    header('Location: ' . $path);
    exit;
}

/**
 * Allowlist redirect targets for file mutate endpoints (delete / rename / share / bulk).
 */
function sanitize_files_redirect(?string $redirect): string
{
    $redirect = (string) ($redirect ?? '/files.php');
    if ($redirect === '/admin/files.php') {
        return '/admin/files.php';
    }
    if (preg_match('#^/admin/files\.php\?user_id=(\d+)$#', $redirect, $m) === 1) {
        return '/admin/files.php?user_id=' . (int) $m[1];
    }

    return '/files.php';
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return $flashes;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

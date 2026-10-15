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
 * Preserves safe list query params (sort, dir, q, page, user_id).
 */
function sanitize_files_redirect(?string $redirect): string
{
    $redirect = (string) ($redirect ?? '/files.php');
    $parts = parse_url($redirect);
    $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : $redirect;

    if ($path !== '/files.php' && $path !== '/admin/files.php') {
        return '/files.php';
    }

    $query = [];
    if (is_array($parts) && !empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }

    $safe = [];
    if ($path === '/admin/files.php' && isset($query['user_id']) && (int) $query['user_id'] > 0) {
        $safe['user_id'] = (int) $query['user_id'];
    }
    if (isset($query['sort']) && is_string($query['sort']) && in_array($query['sort'], ['name', 'size', 'date', 'owner'], true)) {
        $safe['sort'] = $query['sort'];
    }
    if (isset($query['dir']) && is_string($query['dir']) && in_array(strtolower($query['dir']), ['asc', 'desc'], true)) {
        $safe['dir'] = strtolower($query['dir']);
    }
    if (isset($query['q']) && is_string($query['q'])) {
        $q = \App\Pager::searchQuery($query['q']);
        if ($q !== '') {
            $safe['q'] = $q;
        }
    }
    if (isset($query['page']) && (int) $query['page'] > 1) {
        $safe['page'] = (int) $query['page'];
    }

    if ($safe === []) {
        return $path;
    }

    return $path . '?' . http_build_query($safe);
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

/**
 * @return array<string, mixed>
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        json_response(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }
    if (!is_array($decoded)) {
        json_response(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

<?php

declare(strict_types=1);

namespace App;

final class View
{
    public static function render(string $title, string $content, ?array $user = null): void
    {
        $appName = (string) Config::get('app_name');
        $flashes = consume_flashes();
        include dirname(__DIR__) . '/templates/layout.php';
    }
}

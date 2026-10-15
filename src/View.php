<?php

declare(strict_types=1);

namespace App;

final class View
{
    /**
     * @param list<array{label: string, href?: string}> $breadcrumbs
     */
    public static function render(string $title, string $content, ?array $user = null, array $breadcrumbs = []): void
    {
        $appName = (string) Config::get('app_name');
        $flashes = consume_flashes();
        include dirname(__DIR__) . '/templates/layout.php';
    }
}

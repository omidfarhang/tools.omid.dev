<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;

Auth::requireAdmin();
redirect('/admin/settings/ytdlp.php');

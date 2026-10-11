<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\View;

if (Auth::check()) {
    redirect('/');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (Auth::login($username, $password)) {
        redirect('/');
    }
    $error = 'Invalid username or password.';
}

ob_start();
?>
<h1>Login</h1>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>
<form method="post" class="mt-3">
    <?= Csrf::field() ?>
    <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <input class="form-control" id="username" name="username" required autocomplete="username">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button class="btn btn-primary" type="submit">Sign in</button>
</form>
<?php
View::render('Login', ob_get_clean(), null);

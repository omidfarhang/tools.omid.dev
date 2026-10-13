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
    $result = Auth::attemptLogin($username, $password);
    if ($result === 'ok') {
        redirect('/');
    }
    $error = $result === 'inactive'
        ? 'Your account is pending admin approval.'
        : 'Invalid username or password.';
}

ob_start();
?>
<div class="card card-md">
    <div class="card-body">
        <h2 class="h2 text-center mb-4">Login to your account</h2>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <input class="form-control" id="username" name="username" placeholder="Enter username"
                       required autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-2">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" type="password" id="password" name="password"
                       placeholder="Password" required autocomplete="current-password">
            </div>
            <div class="form-footer">
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
            </div>
        </form>
    </div>
</div>
<div class="text-center text-secondary mt-3">
    Don't have an account yet? <a href="/register.php" tabindex="-1">Sign up</a>
</div>
<?php
View::render('Login', ob_get_clean(), null);

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\UserRepository;
use App\View;

if (Auth::check()) {
    redirect('/');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    $repo = new UserRepository();

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
        $error = 'Username must be 3–32 characters (letters, numbers, . _ -).';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($repo->findByUsername($username)) {
        $error = 'That username is already taken.';
    } else {
        $repo->register($username, $password);
        flash('success', 'Account created. An admin must activate it before you can sign in.');
        redirect('/login.php');
    }
}

ob_start();
?>
<div class="card card-md">
    <div class="card-body">
        <h2 class="h2 text-center mb-4">Create new account</h2>
        <p class="text-secondary text-center mb-4">Accounts stay inactive until an admin approves them.</p>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <input class="form-control" id="username" name="username" placeholder="Enter username"
                       required autocomplete="username" pattern="[a-zA-Z0-9._-]{3,32}"
                       value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" type="password" id="password" name="password"
                       placeholder="Password" required autocomplete="new-password" minlength="8">
            </div>
            <div class="mb-2">
                <label class="form-label" for="password_confirm">Confirm password</label>
                <input class="form-control" type="password" id="password_confirm" name="password_confirm"
                       placeholder="Confirm password" required autocomplete="new-password" minlength="8">
            </div>
            <div class="form-footer">
                <button class="btn btn-primary w-100" type="submit">Create new account</button>
            </div>
        </form>
    </div>
</div>
<div class="text-center text-secondary mt-3">
    Already have an account? <a href="/login.php" tabindex="-1">Sign in</a>
</div>
<?php
View::render('Register', ob_get_clean(), null);

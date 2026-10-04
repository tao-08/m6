<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (is_post()) {
    verify_csrf();
    $_SESSION = [];
    session_regenerate_id(true);
    flash('ログアウトしました', 'info');
}
redirect('login.php');

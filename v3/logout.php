<?php
/**
 * logout.php — ログアウト
 * GET（リンク）ではなく POST + CSRF にしている。
 * GET だと、他サイトに <img src=".../logout.php"> を置かれるだけで勝手にログアウトさせられるため。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (is_post()) {
    verify_csrf();
    $_SESSION = [];             // セッションの中身を全部消す
    session_regenerate_id(true);
    flash('ログアウトしました', 'info');
}
redirect('login.php');

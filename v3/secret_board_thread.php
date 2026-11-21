<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/secret_board.php';
$user = require_login();
secret_board_require_entry($user);
$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['thread_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM secret_board_thread WHERE thread_id = ?');
$st->execute([$id]);
$thread = $st->fetch();
if (!$thread) error_page(404);
$protected = $thread['password_hash'] !== null;
$key = 'thread:' . $id;
if ($protected && !secret_board_thread_unlocked($user, $id)) {
    if (is_post()) {
        verify_csrf();
        if (!secret_board_rate_limited($key) && password_verify((string)($_POST['passphrase'] ?? ''), (string)$thread['password_hash'])) {
            secret_board_clear_failures($key);
            secret_board_unlock_thread($user, $id);
            redirect('secret_board_thread?id=' . $id);
        }
        secret_board_record_failure($key);
        flash('合言葉が違います', 'error');
        redirect('secret_board_thread?id=' . $id);
    }
    render_header('スレッド認証', 'board');
    echo '<style>body{background:#1C1728;color:#F4EFFB}.container{color:#F4EFFB}.secret-entry{max-width:28rem;margin:3rem auto;padding:1.5rem;background:#2C233D;border:1px solid #443657;border-radius:var(--r-lg)}.secret-entry input{background:#211A2E;color:#F4EFFB;border-color:#57466F}</style>';
    echo '<form method="post" class="secret-entry">'.csrf_field().'<input type="hidden" name="thread_id" value="'.$id.'"><p class="eyebrow">Private thread</p><h1>'.h($thread['title']).'</h1><label class="field"><span>このスレッドの合言葉</span><input type="password" name="passphrase" autocomplete="current-password" required></label><button class="btn btn--primary" type="submit">閲覧する</button></form>';
    render_footer();
    exit;
}
if (is_post()) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'reply') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '' && mb_strlen($body) <= 2000) {
            $st = $pdo->prepare('INSERT INTO secret_board_post (thread_id, user_id, body) VALUES (?, ?, ?)');
            $st->execute([$id, (int)$user['user_id'], $body]);
            $pdo->prepare('UPDATE secret_board_thread SET updated_at = NOW() WHERE thread_id = ?')->execute([$id]);
        } else {
            flash('本文は1〜2000文字で入力してください', 'error');
        }
        redirect('secret_board_thread?id=' . $id);
    }
}
$st = $pdo->prepare('SELECT p.body,p.created_at,u.name FROM secret_board_post p LEFT JOIN user_account u ON u.user_id=p.user_id WHERE p.thread_id=? ORDER BY p.post_id');
$st->execute([$id]);
$posts = $st->fetchAll();
render_header($thread['title'], 'board');
echo '<style>body{background:#1C1728;color:#F4EFFB}.container{color:#F4EFFB}.secret-board .card{background:#2C233D;border:1px solid #443657;color:#F4EFFB}.secret-board .muted{color:#B1A6C2!important}.secret-board input,.secret-board textarea{background:#211A2E;color:#F4EFFB;border-color:#57466F}.secret-board .btn--primary{background:#B99AE8;color:#21172E;border-color:#B99AE8}</style>';
echo '<div class="secret-board"><nav class="crumbs"><a href="secret_board">裏掲示板</a><span>/</span>'.h($thread['title']).'</nav><h1 class="display display--sm">'.h($thread['title']).'</h1>';
foreach ($posts as $p) {
    echo '<article class="card board-post" style="padding:1rem;margin:.75rem 0"><div class="muted small">'.h($p['name'] ?? '退会ユーザー').' · '.h(substr($p['created_at'],0,16)).'</div><p>'.nl2br(h($p['body'])).'</p></article>';
}
echo '<form method="post" class="card" style="padding:1rem;margin-top:1rem">'.csrf_field().'<input type="hidden" name="thread_id" value="'.$id.'"><input type="hidden" name="action" value="reply"><label class="field"><span>返信</span><textarea name="body" rows="3" maxlength="2000" required></textarea></label><div class="form-actions"><button class="btn btn--primary" type="submit">送信</button></div></form></div>';
render_footer();

<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/secret_board.php';
$user = require_login();
$hash = secret_board_entry_hash();
if ($hash === '') {
    render_header('裏掲示板', 'board');
    echo '<section class="card" style="padding:1.25rem"><h1>裏掲示板の設定が必要です</h1><p class="muted">サーバー設定に ABBEY_SECRET_BOARD_HASH を登録してください。</p></section>';
    render_footer();
    exit;
}
if (!secret_board_entry_unlocked($user) && is_post()) {
    verify_csrf();
    $key = 'entry';
    if (!secret_board_rate_limited($key) && password_verify((string)($_POST['passphrase'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['secret_board_entry'] = true;
        $_SESSION['secret_board_entry_user'] = (int)$user['user_id'];
        secret_board_clear_failures($key);
        redirect('secret_board');
    }
    secret_board_record_failure($key);
    flash('合言葉が違います', 'error');
    redirect('secret_board');
}
if (secret_board_entry_unlocked($user)) {
    $pdo = db();
    if (is_post()) {
        verify_csrf();
        if (($_POST['action'] ?? '') === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $pass = (string)($_POST['thread_passphrase'] ?? '');
            if ($title === '' || mb_strlen($title) > 100 || $body === '' || mb_strlen($body) > 2000 || mb_strlen($pass) > 200) {
                flash('入力内容を確認してください', 'error');
                redirect('secret_board');
            }
            $threadHash = $pass === '' ? null : password_hash($pass, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('INSERT INTO secret_board_thread (user_id, title, password_hash) VALUES (?, ?, ?)');
                $st->execute([(int)$user['user_id'], $title, $threadHash]);
                $threadId = (int)$pdo->lastInsertId();
                $st = $pdo->prepare('INSERT INTO secret_board_post (thread_id, user_id, body) VALUES (?, ?, ?)');
                $st->execute([$threadId, (int)$user['user_id'], $body]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            if ($threadHash !== null) secret_board_unlock_thread($user, $threadId);
            redirect('secret_board_thread?id=' . $threadId);
        }
    }
    $threads = $pdo->query('SELECT t.thread_id, t.title, t.password_hash IS NOT NULL AS protected, t.updated_at, COUNT(p.post_id) AS posts
        FROM secret_board_thread t LEFT JOIN secret_board_post p ON p.thread_id = t.thread_id
        GROUP BY t.thread_id ORDER BY t.updated_at DESC, t.thread_id DESC LIMIT 200')->fetchAll();
    render_header('裏掲示板', 'board');
    echo '<style>
    body{background:#1C1728;color:#F4EFFB} .container{color:#F4EFFB}
    .secret-board .card{background:#2C233D;border-color:#443657;color:#F4EFFB}
    .secret-board .muted{color:#B1A6C2!important}
    .secret-board a{color:#D7C4F5}
    .secret-board .btn--primary{background:#B99AE8;color:#21172E;border-color:#B99AE8}
    .secret-board input,.secret-board textarea{background:#211A2E;color:#F4EFFB;border-color:#57466F}
    .secret-board .secret-form{padding:1rem;display:grid;gap:.8rem}
    </style>';
    echo '<div class="secret-board"><nav class="crumbs"><a href="board">アビーボード</a><span>/</span>裏掲示板</nav>';
    echo '<section class="hero"><div><p class="eyebrow">Abbey Board / Hidden</p><h1 class="display">裏掲示板</h1></div></section>';
    echo '<section class="card"><form method="post" class="secret-form">'.csrf_field().'<input type="hidden" name="action" value="create">';
    echo '<label class="field"><span>スレッド名</span><input name="title" maxlength="100" required></label>';
    echo '<label class="field"><span>本文</span><textarea name="body" rows="3" maxlength="2000" required></textarea></label>';
    echo '<label class="field"><span>個別の合言葉 <small class="muted">設定しない場合は一覧から閲覧できます</small></span><input type="password" name="thread_passphrase" maxlength="200" autocomplete="new-password"></label>';
    echo '<div class="form-actions"><button class="btn btn--primary" type="submit">スレッドを作成</button></div></form></section>';
    echo '<div class="card board-list" style="margin-top:1rem">';
    if (!$threads) echo '<p class="muted" style="padding:1rem">スレッドはありません</p>';
    foreach ($threads as $t) {
        echo '<a class="board-row" href="secret_board_thread?id='.(int)$t['thread_id'].'">'.icon('forum', 'board-row__icon').'<span class="board-row__main"><span class="board-row__title"><span class="board-row__name">'.h($t['title']).'</span>'.($t['protected'] ? ' '.icon('lock', 'board-row__lock') : '').'</span><span class="muted small">'.h(substr($t['updated_at'],0,16)).'</span></span><span class="board-row__count muted small">'.icon('chat_bubble').(int)$t['posts'].'</span></a>';
    }
    echo '</div></div>';
    render_footer();
    exit;
}
render_header('裏掲示板', 'board');
echo '<style>body{background:#1C1728;color:#F4EFFB}.container{color:#F4EFFB}.secret-entry{max-width:28rem;margin:3rem auto;padding:1.5rem;background:#2C233D;border:1px solid #443657;border-radius:var(--r-lg)}.secret-entry input{background:#211A2E;color:#F4EFFB;border-color:#57466F}</style>';
echo '<form method="post" class="secret-entry">'.csrf_field().'<p class="eyebrow">Abbey Board / Hidden</p><h1>裏掲示板</h1><label class="field"><span>秘密の合言葉</span><input type="password" name="passphrase" autocomplete="current-password" required></label><button class="btn btn--primary" type="submit">入室</button></form>';
render_footer();

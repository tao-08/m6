</main>
<footer class="footer">
    <p>&copy; <?= date('Y') ?> <?= h(APP_NAME) ?> by tao__08</p>
    <?php // GetSongBPM の無料 API キーの条件: ログインしなくても見えるページに getsongbpm.com へのリンクを載せる（TAB の BPM 検索） ?>
    <p class="small">BPM data by <a href="https://getsongbpm.com" target="_blank" rel="noopener">GetSongBPM</a></p>
</footer>
<?php
/*
 * チュートリアル。ログインしている人には毎回 <dialog> を置いておく（右上メニューの「チュートリアル」からいつでも開ける）。
 *   まだ見ていない人のときだけ data-tutorial-auto を付けて、開いた瞬間に自動で出す（開くのは assets/app.js の setupTutorial）。
 *   「見たか」は DB の user_account.tutorial_seen_at。毎ページ DB を見ないよう、1回見たらセッションに覚えておく。
 */
if (!empty($_SESSION['user'])) {
    if (!isset($_SESSION['user']['tutorial_seen'])) {
        $st = db()->prepare('SELECT tutorial_seen_at IS NOT NULL FROM user_account WHERE user_id = ?');
        $st->execute([(int)$_SESSION['user']['user_id']]);
        $_SESSION['user']['tutorial_seen'] = (bool)$st->fetchColumn();
    }
    $tutorialAuto = !$_SESSION['user']['tutorial_seen'];
    require __DIR__ . '/tutorial.php';
    // 「ホーム画面に追加する」の説明。右上メニュー（スマホだけ）から開く
    require __DIR__ . '/add_to_home.php';
}
?>
</body>
</html>

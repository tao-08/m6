<?php
/**
 * =====================================================================
 *  partials/landing.php — 未ログインで「/」を開いたときの説明ページ
 * =====================================================================
 *  Google の OAuth 審査（ブランド確認）は「ログインページだけのホームページは不可」。
 *  サービスの説明と、プライバシーポリシーへのリンクを、ログインなしで見られるようにする。
 *  index.php が、未ログインのときだけ読み込む。
 *  スクリーンショットは assets/landing/ に live.png・sched.png・board.png を置くと出る（無ければ出さない。個人名が写らない画面で撮ること）。
 * =====================================================================
 */
declare(strict_types=1);

$shots = ['live' => 'ライブ', 'sched' => '日程調整', 'board' => '掲示板'];
$shotUrl = static fn(string $key): ?string => is_file(__DIR__ . '/../assets/landing/' . $key . '.png') ? asset_url('assets/landing/' . $key . '.png') : null;

render_header(APP_NAME);
?>
<article class="landing">
    <section class="landing__hero">
        <h1 class="auth__logo">
            <img src="assets/online.png" alt="<?= h(APP_NAME) ?>" class="brand__logo--light" width="146" height="40">
            <img src="assets/logo-dark.png" alt="" class="brand__logo--dark" width="146" height="40">
        </h1>
        <p class="landing__lead">軽音サークルのライブデータベース。ライブ・バンド・セットリスト・出演履歴を記録し、練習の日程調整までひとつで行えます。</p>
        <div class="landing__cta">
            <a class="btn btn--primary" href="login">ログイン</a>
            <a class="btn" href="register">新規登録</a>
        </div>
        <p class="muted small">サークルの部員・関係者向けのサービスです。</p>
    </section>

    <section class="landing__grid">
        <div class="card">
            <h2><?= icon('event') ?> ライブ・出演履歴</h2>
            <p>年度ごとのライブ、バンド、メンバー、セットリスト、出演履歴を記録して、検索・閲覧できます。</p>
        </div>
        <div class="card" id="sched">
            <h2><?= icon('sync') ?> 日程調整とGoogleカレンダー連携</h2>
            <p>メンバーの空き時間から練習の候補日を出し、決定した練習をGoogleカレンダーに追加できます。</p>
            <p>連携は任意です。Googleカレンダーから読み取るのは予定の時刻とタイトルのみで、本人以外には「予定あり」とだけ表示します。取得したデータの扱いは<a class="text-link" href="privacy#google">プライバシーポリシー</a>に記載しています。</p>
        </div>
        <div class="card">
            <h2><?= icon('info') ?> 掲示板・曲・TAB</h2>
            <p>部員同士の掲示板、曲の情報、TAB譜の共有・練習に使えます。</p>
        </div>
    </section>

    <?php $hasShot = array_filter(array_keys($shots), static fn($k) => $shotUrl($k) !== null); ?>
    <?php if ($hasShot): ?>
    <section class="landing__shots">
        <?php foreach ($shots as $key => $label): ?>
            <?php if (($u = $shotUrl($key)) !== null): ?>
                <figure><img src="<?= h($u) ?>" alt="<?= h($label) ?>の画面" loading="lazy"><figcaption class="muted small"><?= h($label) ?></figcaption></figure>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</article>
<?php render_footer();

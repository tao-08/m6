<?php
/**
 * =====================================================================
 *  partials/header.php — 全ページ共通の <head> とナビゲーション
 * =====================================================================
 *  render_header('タイトル', 'lives') から呼ばれる。
 *    $pageTitle : <title> に出す文字
 *    $activeNav : ナビのどれを「今いるページ」として光らせるか
 * =====================================================================
 */
/** @var string $pageTitle @var string $activeNav */
$user = current_user();
$unreadNotices = 0;
if ($user) {
    // 行が無いお知らせ＝未読（notice_read は読んだ記録）。告知（お知らせ）と自動の通知（ベル）は別に数える
    // 2種類の未読数は、問い合わせ1回でまとめて数える
    $unreadCounts = notice_unread_counts((int)$user['user_id']);
    $unreadNotices = $unreadCounts['notice'];
    $unreadNotifs = $unreadCounts['notif'];
    // ベルのポップアップのタブ（key は api_notice_read.php の kind と同じ）
    $notifTabs = [
        'notif'  => ['label' => '通知', 'href' => 'notices', 'unread' => $unreadNotifs, 'items' => notice_recent((int)$user['user_id'], true)],
        'notice' => ['label' => 'サイトからのお知らせ', 'href' => 'notices', 'unread' => $unreadNotices, 'items' => notice_recent((int)$user['user_id'], false)],
    ];
    $notifTab = $unreadNotices > 0 ? 'notice' : 'notif';
    // 掲示板: 未読の書き込みがあるスレの数（見られるスレだけ。lib/board.php）
    require_once __DIR__ . '/../lib/board.php';
    $unreadBoard = board_unread_count($user);
    // 公開されたお知らせのプッシュ通知は、応答を返し終わってから送る（lib/push.php）
    require_once __DIR__ . '/../lib/push.php';
    push_dispatch_after_response();
}
// 保存後の「戻る」用（下の <head> の script）。
//   $prgDone   … フォームを送信して保存でき、このページへ移動して来た（lib/bootstrap.php の redirect() が付けた目印）。1回読んだら消す
//   $postResp  … このページは送信（POST）の返事そのもの（入力エラーで同じ画面をもう一度出したときなど）
$prgDone = !empty($_SESSION['prg_done']);
unset($_SESSION['prg_done']);
$postResp = is_post();
$nav = [
    'lives'   => ['./', 'ライブ'],
    'members' => ['members', 'メンバー'],
    'artists' => ['artists', 'アーティスト'],
    'stats'   => ['stats', '集計'],
    'tools'   => ['tools', 'ツール'], // バンドに紐づかない道具（白紙のセッティングシートなど）
    // 「新規追加」（import）はメニューに出さない。入力するのは一部の人だけなので、入口はライブ一覧の「＋ 新規追加」ボタン
];
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <!-- viewport-fit=cover: iPhone の画面の端（下のホームバーの所）まで使う。
         はみ出す分は CSS の env(safe-area-inset-*) で避けている（下のタブバー・戻るボタン） -->
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <!-- JavaScript の fetch() から CSRF トークンを送るために置いておく（assets/app.js） -->
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($pageTitle) ?> | <?= h(APP_NAME) ?></title>
    <!-- 左下の「← 前のページ」ボタンに出すページ名（assets/app.js の setupBackButton）。
         ログインしていないページ（ログイン・新規登録）には置かない → ボタンが出ない＆戻り先としても覚えない -->
    <?php if ($user): ?><meta name="page-title" content="<?= h($pageTitle) ?>"><?php endif; ?>
    <!-- セトリの曲名を押したとき、ページ移動せずに曲カードを開くために、最初に使うときだけ読み込むファイル（assets/app.js の setupSongModalLinks） -->
    <?php if ($user): ?><meta name="songs-modal-assets" content="<?= asset_url('assets/tab.css') ?> <?= asset_url('assets/songs.css') ?> <?= asset_url('assets/songs.js') ?>"><?php endif; ?>
    <!-- アカウント設定で「戻るボタンを表示する」を外した人。戻り先は覚え続ける（また表示にしたとき、すぐ出せるように） -->
    <?php if ($user && ($user['back_button'] ?? true) === false): ?><meta name="back-button" content="off"><?php endif; ?>
    <!--
        X（Twitter）・LINE・Discord などに URL を貼ったときのカード（OGP）。
        SNS のサーバーはログインしていないので、どのページの URL でもログイン画面に飛ばされる。
        だから全ページ同じ中身にする（ページ名を出すと「ログイン | …」になる。ライブ名などを出すとログインの外に漏れる）
    -->
    <meta name="description" content="<?= h(APP_DESCRIPTION) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= h(APP_NAME) ?>">
    <meta property="og:title" content="<?= h(APP_NAME) ?>">
    <meta property="og:description" content="<?= h(APP_DESCRIPTION) ?>">
    <meta property="og:url" content="<?= h(APP_URL) ?>">
    <meta property="og:image" content="<?= h(APP_URL) ?>assets/og.png?v=2"><!-- 画像を変えたら数字を上げる（SNS は同じ URL の画像を長く覚えている） -->
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="ja_JP">
    <meta name="twitter:card" content="summary_large_image">
    <!-- ファビコンは元の m6 の「A」に、角の丸い白い背景を付けたもの（ダークモードのタブでも埋もれない）。
         ブラウザはファビコンを特にしつこく覚えているので、画像を変えたら ?v= の数字を上げる -->
    <link rel="icon" href="assets/favicons/240.png?v=3" sizes="any">
    <link rel="icon" type="image/png" href="assets/favicons/48.png?v=3" sizes="48x48">
    <!--
        ホーム画面に追加したとき「アプリ」として開く（PWA）。
          manifest.json … アプリの名前・アイコン・開いたときの最初のページ（Android / PC の Chrome が読む。iPhone も読む）
          apple-touch-icon … iPhone のホーム画面のアイコン。透明な所は iPhone が黒く塗るので、白い背景を付けた画像にしてある
          *-web-app-capable … ホーム画面から開いたとき、Safari のアドレスバーを出さない
    -->
    <?php // 練習プレイヤーなど、ページごとに別のアプリとして追加したいページは $GLOBALS['page_manifest'] に manifest の URL を入れる（Cookie つきで読ませる） ?>
    <link rel="manifest" href="<?= h($GLOBALS['page_manifest'] ?? 'manifest.json') ?>"<?= isset($GLOBALS['page_manifest']) ? ' crossorigin="use-credentials"' : '' ?>>
    <link rel="apple-touch-icon" href="assets/favicons/apple-touch-icon.png?v=2" sizes="180x180">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="AbbeyRoad">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <!-- 画面の一番上（時計・電池の所）の色。下の script でライト/ダークに合わせて書き換える -->
    <meta name="theme-color" content="#f6f5f1">
    <!--
        ライト / ダークの切り替え。
        CSS が読み込まれる「前」に <html data-theme="..."> を決めておかないと、
        一瞬白く光ってから黒くなる（ちらつく）ので、ここだけ <head> の中に直接書いている。
        保存先は localStorage（ブラウザごとの保存領域）。無ければライト（白）で始める。
        theme-color の色は assets/app.css の --bg と同じ（変えたら assets/app.js の THEME_COLORS も）
    -->
    <script>
        (function () {
            var theme = null;
            try { theme = localStorage.getItem('theme'); } catch (e) {}
            if (theme !== 'light' && theme !== 'dark') {
                theme = 'light';
            }
            document.documentElement.dataset.theme = theme;
            document.querySelector('meta[name="theme-color"]').content = theme === 'dark' ? '#0d0d11' : '#f6f5f1';
        })();
    </script>
    <?php if (!embed_mode()): // モーダルの <iframe> の中では使わない（中の履歴まで動かしてしまうので） ?>
    <!--
        保存後の「戻る」で、送信元（編集画面など）に戻らないようにする。
          例: ライブ → バンド → 編集 →（保存）→ バンド で端末の「戻る」を押す → 編集も古いバンドも飛ばして「ライブ」へ
              ライブ → 編集 →（保存）→ バンド なら「戻る」で「ライブ」へ（編集だけ飛ばす）

        ブラウザの履歴（戻る・進むの列）は JavaScript から消せない。そこで「戻ってきた瞬間に、さらに戻る」で飛ばす。
          ① どの履歴かを見分ける札（prgToken）を history.state に付ける。history.state は履歴1つ1つに付けられる小さなメモで、
             戻る・進むで戻ってきたときもそのまま残っている。同じ URL の編集画面を2回開いても、札は別なので区別できる
          ② ページを離れるとき（pagehide）、そのページの URL と札を sessionStorage（このタブの間だけ残る保存場所）に置く
          ③ 次のページ: サーバーが「保存できて来た」（$prgDone）と言っていたら、②の札を「飛ばす」一覧（prg-skip）に入れる
          ④ 戻る・進むで来たページの札が一覧にあれば、画面を隠して（is-skipping）すぐに history.go(-n) でさらに戻る
             戻った先は古い内容のまま復元されることがある（bfcache）ので、読み直す（prg-reload）

        CSS が読まれる前（画面が描かれる前）に隠したいので、app.js ではなくここに直接書いている
    -->
    <script>
        (function () {
            var POSTED = <?= $prgDone ? 'true' : 'false' ?>;     // 保存できて、このページへ移動して来た
            var POST_RESP = <?= $postResp ? 'true' : 'false' ?>; // 送信の返事そのもの（入力エラーで同じ画面を出し直した）
            var SKIP = 'prg-skip', LEFT = 'prg-left', RELOAD = 'prg-reload', MAX = 50;
            var html = document.documentElement;

            // 比べる用の URL（# 以降は無視。よそのサイトなら null）。assets/app.js の setupBackButton の keyOf と同じ
            function keyOf(u) {
                try {
                    var x = new URL(u, location.href);
                    return x.origin === location.origin ? x.origin + x.pathname + x.search : null;
                } catch (e) { return null; }
            }
            // sessionStorage はプライベートモードなどで使えないことがある → そのときは何もしない（普通の「戻る」のまま）
            function load(k) { try { return JSON.parse(sessionStorage.getItem(k)); } catch (e) { return null; } }
            function save(k, v) { try { sessionStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
            function drop(k) { try { sessionStorage.removeItem(k); } catch (e) {} }
            // 今の履歴のメモ。ほかの処理（タブ切り替えで URL を変える等）のメモを消さないよう、足して書き戻す
            function state() { var s = history.state; return s && typeof s === 'object' ? s : {}; }
            function mark(extra) {
                try { history.replaceState(Object.assign({}, state(), extra), ''); } catch (e) {}
            }
            var here = keyOf(location.href);

            // ① 札を付ける。prgDepth = 送信元を飛ばすとき、何個ぶん戻るか
            //    入力エラーで出し直した画面（同じ URL の送信の返事）は、元の編集画面の上にもう1個積まれているので +1
            var left = load(LEFT);
            drop(LEFT);
            function ensure() {
                if (state().prgToken) return;
                var depth = POST_RESP && left && left.url === here ? (left.depth || 1) + 1 : 1;
                mark({ prgToken: Math.random().toString(36).slice(2) + Date.now().toString(36), prgDepth: depth });
            }
            ensure();

            // ② 離れるとき、このページの札を次のページへ渡す。
            //    ほかの処理が history.replaceState(null, ...) で札を消していることがあるので、ここで付け直してから渡す
            addEventListener('pagehide', function () {
                ensure();
                save(LEFT, { url: here, token: state().prgToken, depth: state().prgDepth || 1 });
            });

            // ③ 保存できて来た → 送信元の札を「飛ばす」一覧へ
            //    referrer（どのページから来たか）が送信元と同じ・今のページとは違う、のときだけ（同じページに戻る保存は対象外）
            if (POSTED && left && left.token && left.url !== here && left.url === keyOf(document.referrer)) {
                var skip = load(SKIP) || {};
                // 送信元の前のページ（左下の戻るボタンが覚えている戻り先。assets/app.js の setupBackButton の back-pages）が
                // 今のページと同じなら（バンド → 編集 → 保存 → バンド）、その古いバンドも飛ばす
                var pages = load('back-pages') || {};
                var before = pages[left.url] && pages[left.url].prev;
                skip[left.token] = { depth: left.depth || 1, two: !!(before && keyOf(before.url) === here), dest: here };
                var keys = Object.keys(skip); // 古いものから捨てる（キーは入れた順に並ぶ）
                keys.slice(0, Math.max(0, keys.length - MAX)).forEach(function (k) { delete skip[k]; });
                save(SKIP, skip);
            }

            // ④ 戻る・進むで来たとき
            //    restored = bfcache（ページがメモリからそのまま復元された）。中身が古いままなので、頼まれていたら読み直す
            function arrive(viaHistory, restored) {
                var s = viaHistory ? (load(SKIP) || {})[state().prgToken] : null;
                if (!s) {
                    if (load(RELOAD)) {
                        drop(RELOAD);
                        if (restored) location.reload();
                    }
                    return;
                }
                html.classList.add('is-skipping'); // 編集画面を一瞬でも見せない（app.css）
                save(RELOAD, 1);                   // 戻った先で読み直してもらう
                var n = s.depth + (s.two ? 1 : 0);
                // navigation API（Chrome・Edge・新しい Safari）なら、今が履歴の何番目か分かる → 戻れる数だけ戻る
                var idx = window.navigation && navigation.currentEntry ? navigation.currentEntry.index : -1;
                function fallback() {
                    if (s.two) history.go(-s.depth); // 古いバンドまでしか無い → そこへ戻って読み直す
                    else { drop(RELOAD); location.replace(s.dest); } // 編集画面が最初のページ → 保存後のページに置き換える
                }
                if (idx < 0) {
                    // 分からないブラウザ: とりあえず戻ってみて、1.5秒たってもこのページのままなら、戻れなかったと見なす
                    var gone = false;
                    addEventListener('pagehide', function () { gone = true; }, { once: true });
                    history.go(-n);
                    setTimeout(function () { if (!gone) fallback(); }, 1500);
                } else if (idx >= n) {
                    history.go(-n);
                } else if (s.two && idx >= s.depth) {
                    history.go(-s.depth);
                } else {
                    drop(RELOAD);
                    location.replace(s.dest);
                }
                setTimeout(function () { html.classList.remove('is-skipping'); }, 4000); // 念のため。何が起きても4秒で見えるように戻す
            }
            var navEntry = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            arrive(!!navEntry && navEntry.type === 'back_forward', false);
            addEventListener('pageshow', function (e) {
                if (!e.persisted) return; // 普通の読み込みは上で済んでいる
                html.classList.remove('is-skipping');
                arrive(true, true);
            });
        })();
    </script>
    <?php endif; ?>
    <!--
        豪華な演出（assets/fx.css / assets/fx.js）のスイッチ。CSS が読み込まれる「前」に <html> にクラスを付ける。
          FX_MODE … 'full'（全部盛り）/ 'lite'（軽量: 背景のゆらぎ・傾き・ぼかしを止める）/ 'off'（演出なし = 元の見た目）
                    ★ 全員まとめて元に戻したいときは、ここを 'off' にして main へ反映（デプロイ）するだけ。
          個人の設定 … ブラウザのコンソールで localStorage.setItem('fx', 'off' か 'lite')。消すと元に戻る。
                    （FX_MODE より重くはできない。FX_MODE が 'off' なら個人設定で 'full' にしても付かない）
          自動で下げる場合 … 「視差効果を減らす」設定の端末は 'off'、通信節約モード・メモリ少なめの端末は 'lite'
          fx-noreveal … 位置の計算が大事な編集画面（セッティングシート・TAB譜・練習・スタジオ割・新規追加）では、
                        スクロールで出てくる演出だけ使わない
          fx-boot … 読み込み中のちらつき防止の仮の非表示。fx.js が外す。動かなくても 2.5 秒で必ず外れる
    -->
    <script>
        (function () {
            var FX_MODE = 'off'; // 2026-11-03 いったん演出を止めて元の見た目に戻した（再開するときは 'full' か 'lite'。作業ブランチ claude/fancy-motion は 'full'）
            var level = { off: 0, lite: 1, full: 2 };
            var mode = FX_MODE;
            try {
                var mine = localStorage.getItem('fx');
                if (level[mine] !== undefined && level[mine] < level[mode]) mode = mine;
            } catch (e) {}
            var c = navigator.connection;
            if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) mode = 'off';
            else if (mode === 'full' && ((c && c.saveData) || (navigator.deviceMemory && navigator.deviceMemory <= 2))) mode = 'lite';
            if (mode === 'off') return;
            var html = document.documentElement;
            html.classList.add('fx');
            if (mode === 'lite') html.classList.add('fx-lite');
            if (/\/(setting_sheet|tab|practice|studio|import)(\.php)?\/?$/.test(location.pathname)) {
                html.classList.add('fx-noreveal');
            } else {
                html.classList.add('fx-boot');
                setTimeout(function () { html.classList.remove('fx-boot'); }, 2500);
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;900&display=swap" rel="stylesheet">
    <!-- アイコン用フォント（Material Symbols Rounded）。lib/bootstrap.php の icon() で使う。
         display=block: 読み込み中にアイコン名の英単語（edit など）が一瞬見えるのを防ぐ
         icon_names: 使う名前だけ入れて軽くする（lib/icon_names.php。約 1.2MB → 約 70KB） -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400..600,0..1,0&icon_names=<?= h(implode(',', require __DIR__ . '/../lib/icon_names.php')) ?>&display=block" rel="stylesheet">
    <!-- ?v= はキャッシュ対策。ファイルの更新時刻が入る（lib/bootstrap.php の asset_url）ので、CSS・JS を変えても数字を上げなくてよい -->
    <link rel="stylesheet" href="<?= asset_url('assets/app.css') ?>">
    <!-- 豪華な演出。app.css の「後」に読む（同じ強さの規則は後に書いたほうが勝つため）。全部 html.fx 始まりなので、fx クラスが無ければ効かない -->
    <link rel="stylesheet" href="<?= asset_url('assets/fx.css') ?>">
    <?php if (($instCss = instrument_color_css()) !== ''): ?>
    <!-- 楽器の色（楽器の管理 instruments.php で変更した色）。中身は lib/bootstrap.php の instrument_color_css()。
         色は '#rrggbb' の形だけを通しているので、そのまま書き出して安全 -->
    <style><?= $instCss ?></style>
    <?php endif; ?>
    <script src="<?= asset_url('assets/app.js') ?>" defer></script>
    <script src="<?= asset_url('assets/fx.js') ?>" defer></script><!-- 演出。app.js の「後」に動かす（defer は書いた順に動く） -->
</head>
<body<?= embed_mode() ? ' class="is-embed"' : '' ?>>
<?php if (!embed_mode()): // 埋め込み表示（モーダルの <iframe> の中）ではナビを出さない ?>
<header class="topbar">
    <div class="topbar__inner">
        <!--
            ロゴは元の m6 のヘッダーと同じ src/assets/online.png。
            ダークモードでは「.Online」の黒文字が背景に溶けるので、文字だけ白くした logo-dark.png に CSS で切り替える。
        -->
        <a class="brand" href="./" aria-label="AbbeyRoad.online トップへ">
            <img src="assets/online.png" alt="AbbeyRoad.online" class="brand__logo brand__logo--light" width="146" height="40">
            <img src="assets/logo-dark.png" alt="" class="brand__logo brand__logo--dark" width="146" height="40">
        </a>
        <?php if ($user): /* ナビ・検索・掲示板・通知は同じ条件なので、ひとつの if にまとめてある */ ?>
        <nav class="nav" aria-label="メイン">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= h($href) ?>" class="nav__link<?= $activeNav === $key ? ' is-active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <a href="search" class="icon-btn<?= $activeNav === 'search' ? ' is-active' : '' ?>" aria-label="検索" title="検索">
            <?= icon('search') ?>
        </a>
        <a href="board" class="icon-btn<?= $activeNav === 'board' ? ' is-active' : '' ?>" aria-label="アビーボード" title="アビーボード">
            <?= icon('forum') ?>
            <?php if ($unreadBoard > 0): ?><span class="badge-dot"><?= $unreadBoard > 9 ? '9+' : $unreadBoard ?></span><?php endif; ?>
        </a>
        <details class="usermenu notifmenu">
            <summary class="icon-btn<?= $activeNav === 'notices' ? ' is-active' : '' ?>" aria-label="通知" title="通知" data-notice-open>
                <?= icon('notifications') ?>
                <?php $unreadAll = $unreadNotices + $unreadNotifs; if ($unreadAll > 0): ?><span class="badge-dot" data-notice-badge><?= $unreadAll > 9 ? '9+' : $unreadAll ?></span><?php endif; ?>
            </summary>
            <div class="usermenu__panel notifmenu__panel">
                <!-- タブ: 通知（自動）とお知らせ（告知）。未読のお知らせがあればお知らせのタブから開く（告知を埋もれさせない） -->
                <div class="tabs tabs--static notifmenu__tabs" role="tablist">
                    <?php foreach ($notifTabs as $key => $t): ?>
                        <button type="button" class="tab<?= $key === $notifTab ? ' is-active' : '' ?>" role="tab" data-notif-tab="<?= $key ?>" aria-selected="<?= $key === $notifTab ? 'true' : 'false' ?>"><?= $t['label'] ?><?php if ($t['unread'] > 0): ?> <span class="badge-dot notifmenu__count" data-notif-count><?= $t['unread'] > 9 ? '9+' : $t['unread'] ?></span><?php endif; ?></button>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($notifTabs as $key => $t): ?>
                <div data-notif-pane="<?= $key ?>"<?= $key === $notifTab ? '' : ' hidden' ?>>
                    <?php foreach ($t['items'] as $n): ?>
                        <a href="<?= h($n['link_url'] ?? $t['href']) ?>" class="notifmenu__item">
                            <span class="notifmenu__title"><?= $n['is_important'] ? icon('priority_high', 'notice__mark') : '' ?><?= h($n['title']) ?><?= $n['is_new'] ? ' <span class="tag tag--plan">NEW</span>' : '' ?></span>
                            <span class="muted small"><?= h(substr($n['publish_at'], 0, 16)) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$t['items']): ?><p class="muted small notifmenu__empty"><?= $t['label'] ?>はありません</p><?php endif; ?>
                    <a href="<?= $t['href'] ?>" class="notifmenu__all">すべて見る</a>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php else: ?>
        <!-- テーマ切り替えボタン（中身のアイコンは CSS で太陽/月を出し分け） -->
        <button type="button" class="icon-btn" data-theme-toggle aria-label="ライト/ダーク切り替え" title="ライト/ダーク切り替え">
            <?= icon('light_mode', 'icon-sun') ?>
            <?= icon('dark_mode', 'icon-moon') ?>
        </button>
        <?php endif; ?>
        <?php if ($user): ?>
        <!-- <details> はクリックで開閉する HTML 標準の部品。JS なしでメニューが作れる -->
        <details class="usermenu">
            <!-- 検索ボタンと同じ見た目の丸いボタンに、人型のアイコン（person）を出す -->
            <summary class="icon-btn" aria-label="アカウント" title="アカウント"><?= icon('person') ?></summary>
            <div class="usermenu__panel">
                <div class="usermenu__name">
                    <span class="usermenu__who"><?= h($user['name']) ?><?= $user['admin'] ? ' <span class="tag">管理者</span>' : (!empty($user['executive']) ? ' <span class="tag">幹部</span>' : '') ?></span>
                    <!-- テーマ切り替え（中身のアイコンは CSS で太陽/月を出し分け） -->
                    <button type="button" class="icon-btn icon-btn--sm" data-theme-toggle aria-label="ライト/ダーク切り替え" title="ライト/ダーク切り替え">
                        <?= icon('light_mode', 'icon-sun') ?>
                        <?= icon('dark_mode', 'icon-moon') ?>
                    </button>
                </div>
                <?php if (!empty($user['member_id'])): ?>
                    <a href="member?id=<?= (int)$user['member_id'] ?>">マイページ</a>
                <?php else: ?>
                    <!-- メンバー未紐付けだとマイページが無い。紐付けは管理者がユーザー管理で行う（アカウント設定に案内を出している） -->
                    <a href="account">マイページ <span class="muted small">（管理者の紐付け待ち）</span></a>
                <?php endif; ?>
                <a href="account">アカウント設定</a>
                <a href="contact">問い合わせ・要望</a>
                <button type="button" class="linkbtn" data-tutorial-open>チュートリアル</button>
                <!-- スマホ・タブレットだけ出す（.touch-only）。ホーム画面から開いているときは、もう追加済みなので出さない。説明は partials/add_to_home.php -->
                <button type="button" class="linkbtn touch-only" data-a2hs-open>ホーム画面に追加する</button>
                <form method="post" action="logout"><?= csrf_field() ?><button type="submit" class="linkbtn">ログアウト</button></form>
                <?php if ($user['admin']): ?>
                    <!-- 管理者専用のメニューは単色の背景の枠でまとめて、一般メニューと見分けられるようにする -->
                    <div class="usermenu__admin">
                        <div class="usermenu__admin-label">管理者専用メニュー</div>
                        <a href="users">ユーザー管理</a>
                        <a href="history">変更履歴</a>
                        <a href="sheet_log">PDF作成ログ</a>
                        <a href="members_merge">メンバーの統合</a>
                        <a href="members_entry">メンバープロフィールの一括編集</a>
                        <a href="masters">会場・日程名・係・アーティストの管理</a>
                        <a href="instruments">楽器の管理</a>
                    </div>
                <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>
<!-- お知らせ（「更新しました」など）はヘッダーの下に固定で出し、4秒で消す（assets/app.js の setupToasts） -->
<div class="toasts" data-toasts aria-live="polite">
<?php foreach (take_flashes() as $f): ?>
    <div class="flash flash--<?= h($f['type']) ?> toast" role="status"><?= nl2br(h($f['message'])) ?></div>
<?php endforeach; ?>
</div>
<main class="container">

<?php
/**
 * =====================================================================
 *  tt_builder.php — タイムテーブル作成ツール（幹部と管理者だけ）
 * =====================================================================
 *  画面
 *    tt_builder              … 下書きの一覧 + 名簿のアップロード
 *    tt_builder?id=1#days    … 日程希望（バンドごとに出られる日をまとめて選ぶ）
 *    tt_builder?id=1         … 出順の並べ替え・自動配置・持ち時間と時刻
 *    tt_builder?id=1&csv=2   … 2日目の CSV を書き出す（取り込みのタイムテーブルの形）
 *  POST（すべて CSRF チェック）
 *    upload  … 名簿を読んで下書きを作る
 *    publish … 取り込み（import.php）のプレビューへ渡す。そこで確定するとライブとして登録される
 *    delete  … 下書きを消す
 *  編集中の変更は tt_builder_api.php に「操作」として送られ、自動保存・他の人の画面へ反映される。
 *  画面を作るのは assets/tt_builder.js（ここは JSON を埋め込むだけ）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tt_builder.php';
$user = require_executive();

const TT_MAX_FILES = 5;
const TT_MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

$pdo = db();

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ---------- 名簿を読んで下書きを作る ----------
    if ($action === 'upload') {
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 100));
        if ($title === '') {
            flash('ライブ名を入力してください', 'error');
            redirect('tt_builder');
        }
        $up = $_FILES['files'] ?? null;
        $count = is_array($up['name'] ?? null) ? count($up['name']) : 0;
        if ($count === 0 || ($count === 1 && $up['error'][0] === UPLOAD_ERR_NO_FILE) || $count > TT_MAX_FILES) {
            flash('名簿のファイルを1〜' . TT_MAX_FILES . '個選んでください', 'error');
            redirect('tt_builder');
        }
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $name = basename((string)$up['name'][$i]);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            // is_uploaded_file: 本当にフォームからアップロードされたファイルか確認（偽のパスを渡す攻撃対策）
            if ($up['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'][$i])
                || $up['size'][$i] > TT_MAX_FILE_SIZE || !in_array($ext, IMPORT_EXTENSIONS, true)) {
                flash("{$name}: 読み込めません（CSV / Excel(.xlsx) / PDF、5MBまで）", 'error');
                continue;
            }
            $files[] = ['path' => $up['tmp_name'][$i], 'name' => $name];
        }
        if (!$files) {
            redirect('tt_builder');
        }
        $plan = build_import_plan($files);
        foreach ($plan['errors'] as $e) {
            flash($e, 'error');
        }
        if (!$plan['rosters'] || !array_filter($plan['rosters'], static fn($r) => $r['bands'])) {
            flash('名簿（バンド名・Vo・Gt…の表）が見つかりませんでした', 'error');
            redirect('tt_builder');
        }
        $state = tt_new_state($title, $plan['rosters']);
        $pdo->prepare('INSERT INTO timetable_draft (title, state, source, created_by, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$title, json_encode($state, JSON_UNESCAPED_UNICODE), json_encode($plan['rosters'], JSON_UNESCAPED_UNICODE),
                $user['user_id'], $user['user_id']]);
        redirect('tt_builder?id=' . (int)$pdo->lastInsertId() . '#days');
    }

    $id = (int)($_POST['id'] ?? 0);
    $draft = $id > 0 ? tt_load($pdo, $id) : null;
    if ($draft === null) {
        flash('下書きが見つかりません', 'error');
        redirect('tt_builder');
    }

    // ---------- 取り込みのプレビューへ ----------
    if ($action === 'publish') {
        $plan = tt_import_plan($draft['state'], json_decode($draft['source'], true) ?: []);
        if (!$plan['timetables']) {
            flash('バンドが1つもありません', 'error');
            redirect('tt_builder?id=' . $id);
        }
        $plan['tt_draft'] = $id; // 確定したら published_live_id を入れる（import.php）
        $_SESSION['import_plan'] = $plan;
        unset($_SESSION['import_form']);
        flash('開催日・会場を入れて確定するとライブとして登録されます', 'info');
        redirect('import');
    }

    // ---------- 削除 ----------
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM timetable_draft WHERE draft_id = ?')->execute([$id]);
        flash('下書きを削除しました');
        redirect('tt_builder');
    }
    redirect('tt_builder');
}

$id = (int)($_GET['id'] ?? 0);

/* ==================== 一覧 + アップロード ==================== */
if ($id <= 0) {
    $drafts = $pdo->query('SELECT d.draft_id, d.title, d.updated_at, d.published_live_id, u.name AS updated_name
        FROM timetable_draft d LEFT JOIN user_account u ON u.user_id = d.updated_by ORDER BY d.updated_at DESC')->fetchAll();
    render_header('タイムテーブル作成', 'tools');
    ?>
    <h1 class="display display--sm"><?= icon('view_timeline') ?> タイムテーブル作成</h1>
    <?php if ($drafts): ?>
        <div class="tool-list">
            <?php foreach ($drafts as $d): ?>
                <a class="card tool-card" href="tt_builder?id=<?= (int)$d['draft_id'] ?>">
                    <span class="tool-card__icon"><?= icon($d['published_live_id'] ? 'task_alt' : 'edit_note') ?></span>
                    <span class="tool-card__body">
                        <span class="tool-card__name"><?= h($d['title']) ?></span>
                        <span class="muted small"><?= h(date('n/j H:i', strtotime($d['updated_at']))) ?> <?= h((string)$d['updated_name']) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card upload" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <label class="field"><span>ライブ名</span>
            <input name="title" maxlength="100" required placeholder="例: 9月ライブ"></label>
        <label class="dropzone" data-dropzone>
            <input type="file" name="files[]" accept=".csv,.xlsx,.xlsm,.pdf,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple required data-file-input>
            <span class="dropzone__icon" aria-hidden="true"><?= icon('upload_file') ?></span>
            <span class="dropzone__title">名簿をドロップ</span>
            <span class="muted small">CSV / Excel / PDF</span>
            <ul class="dropzone__list" data-file-list></ul>
        </label>
        <div class="loading" data-loading-box hidden role="status" aria-live="polite">
            <span class="loading__spinner" aria-hidden="true"></span>
            <p class="loading__title" data-loading-title>読み込み中…</p>
            <p class="muted small" data-loading-sub></p>
        </div>
        <button class="btn btn--primary btn--block" type="submit">新規作成</button>
    </form>
    <?php
    render_footer();
    exit;
}

$draft = tt_load($pdo, $id);
if ($draft === null) {
    http_response_code(404);
    render_header('タイムテーブル作成', 'tools');
    echo '<div class="empty card"><p class="empty__title">下書きが見つかりません</p><a class="btn" href="tt_builder">一覧へ</a></div>';
    render_footer();
    exit;
}
$state = $draft['state'];

/* ==================== CSV の書き出し（読むだけなので GET） ==================== */
if (isset($_GET['csv'])) {
    $d = (int)$_GET['csv'] - 1;
    if ($d < 0 || $d >= $state['dayCount']) {
        http_response_code(400);
        exit('日程の指定がおかしいです');
    }
    $file = $state['title'] . DAY_LABELS[$d] . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    // 日本語のファイル名は filename* で（RFC 5987）。filename は ASCII の代わり
    header("Content-Disposition: attachment; filename=\"timetable.csv\"; filename*=UTF-8''" . rawurlencode($file));
    echo tt_csv($state, $d);
    exit;
}

/* ==================== 編集画面（中身は assets/tt_builder.js が作る） ==================== */
$info = tt_member_info($pdo, tt_member_keys($state));
$similar = tt_member_suggest($pdo, $state, $info); // DBにいない人の「もしかして」候補
// 候補の人に置きかえたときの学年・出演履歴も画面で使えるように、候補の分も調べておく
$candKeys = array_values(array_unique(array_column(array_merge(...array_values($similar) ?: [[]]), 'k')));
$info += tt_member_info($pdo, $candKeys);
$boot = [
    'id' => $id,
    'v' => (int)$draft['version'],
    'state' => $state,
    'info' => $info,
    'similar' => (object)$similar,
    'labels' => array_slice(DAY_LABELS, 0, TT_MAX_DAYS),
    'venues' => $pdo->query('SELECT name FROM venue ORDER BY sort_order, name')->fetchAll(PDO::FETCH_COLUMN), // プレビューの会場のプルダウン
    'published' => $draft['published_live_id'] !== null ? (int)$draft['published_live_id'] : null,
];
render_header($state['title'] . ' — タイムテーブル作成', 'tools');
?>
<div class="ttb" data-tt-builder>
    <noscript><p class="card">JavaScript を有効にしてください</p></noscript>
</div>
<form method="post" id="ttb-publish" data-confirm="取り込みのプレビューへ進みます。開催日・会場を入れて確定するとライブとして登録されます。" hidden>
    <?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= $id ?>">
</form>
<form method="post" id="ttb-delete" data-confirm="「<?= h($state['title']) ?>」の下書きを削除します。元に戻せません。よろしいですか？" hidden>
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
</form>
<script type="application/json" id="ttb-data"><?= tt_json($boot) ?></script>
<script src="<?= asset_url('assets/tt_builder.js') ?>" defer></script>
<?php render_footer();

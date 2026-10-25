<?php
/**
 * =====================================================================
 *  api_tab_save.php — TAB 譜の保存・削除 API（JSON を返す）
 * =====================================================================
 *  tab.php の編集モードから、assets/tab.js が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:
 *    保存: {"action":"save", "tab_id":12, "updated_at":"2026-10-24 10:00:00", "part":"Gt1",
 *           "tuning":"E A D G B E", "bpm":120, "beats_per_bar":4, "steps_per_beat":4,
 *           "offset_ms":1500, "capo":0, "first_bar":1, "is_partial":false, "memo":"…", "links":[{"u":"https://…","l":"表示名"}], "youtube_url":"https://youtu.be/…"（空なら ""）, "data":{"bars":[…]}}
 *    削除: {"action":"delete", "tab_id":12}
 *    曲のメモ: {"action":"song_memo", "song_key":"…", "memo":"…"}（画像の追加・削除は api_tab_image.php）
 *    履歴: {"action":"history", "tab_id":12}   … {"ok":true,"items":[{rev_id,action,label,at,user}]}（新しい順）
 *    復元: {"action":"restore", "rev_id":34}    … {"ok":true,"redirect":"tab?id=12"}（削除した TAB も同じ tab_id で戻せる）
 *  レスポンス（JSON）:
 *    保存: {"ok":true, "updated_at":"…"}   ← 次の保存でこの値を送り返す（楽観ロック）
 *    削除: {"ok":true, "redirect":"tab?key=…"}
 *  エラー: {"error":"..."}（400 / 401 / 403 / 404 / 405 / 409）
 *
 *  楽観ロック: 誰でも編集できるので、開いている間に別の人が保存していたら上書きしてしまう。
 *    そこで「開いたときの updated_at」を一緒に送らせ、DB の値と違えば 409 で断る（相手の変更を消さない）。
 *  編集・削除・復元はログイン者なら誰でもできる（wiki 型）。そのかわり、変更のたびに履歴（song_tab_revision）を残して、いつでも戻せる。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tabs.php';
require_once __DIR__ . '/lib/youtube.php'; // youtube_video_id()
require_once __DIR__ . '/lib/claude.php';  // claude_json()（BPM の AI 推定）

header('Content-Type: application/json; charset=utf-8');

/** エラーの JSON を返して終わる */
function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// API なのでログイン画面へのリダイレクトではなく、エラーの JSON を返す
$user = current_user();
if ($user === null) {
    fail(401, 'ログインしてください');
}
if (!tab_allowed()) {
    fail(403, '管理人のみ利用できます'); // 今は管理人だけ（lib/tabs.php の tab_allowed）
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf(); // JS から X-CSRF-Token ヘッダーで送られてくる

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    fail(400, '送られた内容がおかしいです');
}
$action = $body['action'] ?? '';
$tabId = tab_int($body['tab_id'] ?? null) ?? 0;
if ($tabId <= 0 && !in_array($action, ['restore', 'song_memo'], true)) { // 復元だけは tab_id ではなく rev_id で指定する
    fail(400, 'TAB の指定がおかしいです');
}
$userId = (int)$user['user_id'];
$pdo = db();

/** DB のエラーの文言。本番では中身（テーブル名など）を見せない */
function db_error(PDOException $e, string $fallback): string
{
    return config('debug') ? $e->getMessage() : $fallback;
}

if ($action === 'delete') {
    // 誰でも削除できる。消す直前の状態を履歴に残すので、あとで「復元」で戻せる（同じトランザクションで行う）
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM song_tab WHERE tab_id = ? FOR UPDATE');
        $st->execute([$tabId]);
        $row = $st->fetch();
        if (!$row) {
            $pdo->rollBack();
            fail(404, 'TAB が見つかりません');
        }
        tab_record_revision($pdo, $tabId, 'delete', $userId, $row);
        $pdo->prepare('DELETE FROM song_tab WHERE tab_id = ?')->execute([$tabId]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail(500, db_error($e, '削除できませんでした'));
    }
    flash('TAB を削除しました');
    echo json_encode(['ok' => true, 'redirect' => 'tab?key=' . rawurlencode($row['song_key'])], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'history') {
    // 履歴の一覧（新しい順）。先頭 = 今の状態なので、画面側で先頭には「復元」を出さない
    $st = $pdo->prepare('SELECT r.rev_id, r.action, r.created_at, u.name
        FROM song_tab_revision r LEFT JOIN user_account u ON u.user_id = r.user_id
        WHERE r.tab_id = ? ORDER BY r.rev_id DESC LIMIT ' . TAB_REVISION_KEEP);
    $st->execute([$tabId]);
    $items = array_map(static fn($r) => [
        'rev_id' => (int)$r['rev_id'],
        'action' => $r['action'],
        'label'  => TAB_ACTION_LABELS[$r['action']] ?? $r['action'],
        'at'     => date('Y/n/j H:i', (int)strtotime($r['created_at'])),
        'user'   => $r['name'] ?? '（削除済み）',
    ], $st->fetchAll());
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'song_memo') {
    // 曲のメモ（全パート共通。song_meta）。最初に保存したときに行を作る
    $key = $body['song_key'] ?? null;
    $memo = tab_sanitize_memo($body['memo'] ?? null);
    if (!is_string($key) || !tab_key_valid($key)) {
        fail(400, '曲の指定がおかしいです');
    }
    if ($memo === false) {
        fail(400, 'メモは' . TAB_MEMO_MAX . '文字までです');
    }
    try {
        $pdo->prepare('INSERT INTO song_meta (song_key, memo, updated_by) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE memo = VALUES(memo), updated_by = VALUES(updated_by)')
            ->execute([$key, $memo === '' ? null : $memo, $userId]);
    } catch (PDOException $e) {
        fail(500, db_error($e, '保存できませんでした'));
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'practice') {
    // 練習用マーカー（自分だけ）。TAB の編集履歴には入れない。JS が止まってから約1秒で自動で送ってくる
    $clean = tab_sanitize_practice($body['data'] ?? null);
    if ($clean === null) {
        fail(400, 'マーカーのデータがおかしいです');
    }
    $st = $pdo->prepare('SELECT 1 FROM song_tab WHERE tab_id = ?');
    $st->execute([$tabId]);
    if (!$st->fetchColumn()) {
        fail(404, 'TAB が見つかりません');
    }
    try {
        // 主キー (tab_id, user_id): 人ごとに1行。自分の行だけを入れ替える
        $pdo->prepare('INSERT INTO song_tab_practice (tab_id, user_id, data) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([$tabId, $userId, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    } catch (PDOException $e) {
        fail(500, db_error($e, '保存できませんでした'));
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (in_array($action, ['yt_search', 'bpm_search', 'bpm_ai'], true)) {
    // 音源の動画・BPM の候補を探す。検索語は DB の曲名・アーティスト名から作る（ブラウザから好きな語で API を叩かせない）
    //   YouTube の検索は1回 100 ユニット（無料枠 1日 10,000）、AI は1回数円かかるので、同じ TAB の結果はセッションに覚えて使い回す
    $st = $pdo->prepare('SELECT title, artist_name FROM song_tab WHERE tab_id = ?');
    $st->execute([$tabId]);
    $song = $st->fetch();
    if (!$song) {
        fail(404, 'TAB が見つかりません');
    }
    $cacheKey = $action . ':' . $tabId;
    if (!isset($_SESSION['tab_search'][$cacheKey])) {
        // AI の推定は十数秒かかる（ウェブ検索）。そのあいだセッションを掴んだままだと、同じ人の他のリクエスト（練習マーカーの自動保存など）が待たされるので、先に手放す
        session_write_close();
        try {
            $items = match ($action) {
                'yt_search' => youtube_search(trim($song['title'] . ' ' . ($song['artist_name'] ?? ''))),
                'bpm_ai'    => tab_bpm_ai($song['title'], $song['artist_name']),
                default     => tab_bpm_search($song['title'], $song['artist_name']),
            };
        } catch (RuntimeException $e) {
            fail(502, $e->getMessage());
        }
        session_start(); // 結果を覚えるために開き直す（$_SESSION は読み直される）
        $_SESSION['tab_search'][$cacheKey] = $items;
    }
    echo json_encode(['ok' => true, 'items' => $_SESSION['tab_search'][$cacheKey]], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'restore') {
    // 履歴の1行の時点に戻す。保存されていた JSON をそのまま信用せず、保存と同じ検査を通し直す
    $revId = tab_int($body['rev_id'] ?? null) ?? 0;
    $st = $pdo->prepare('SELECT tab_id, song_key, snapshot FROM song_tab_revision WHERE rev_id = ?');
    $st->execute([$revId]);
    $rev = $st->fetch();
    if (!$rev) {
        fail(404, '履歴が見つかりません');
    }
    $c = tab_snapshot_clean($rev['snapshot']);
    if ($c === null) {
        fail(400, 'この履歴は復元できません');
    }
    $tabId = (int)$rev['tab_id'];
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT tab_id, song_key FROM song_tab WHERE tab_id = ? FOR UPDATE');
        $st->execute([$tabId]);
        $cur = $st->fetch();
        // 同じ曲・同じパート名の別の TAB が今ある（削除したあとに作り直された）なら、重複になるので断る
        $st = $pdo->prepare('SELECT 1 FROM song_tab WHERE song_key = ? AND part = ? AND tab_id <> ?');
        $st->execute([$rev['song_key'], $c['part'], $tabId]);
        if ($st->fetchColumn()) {
            $pdo->rollBack();
            fail(409, '同じパート名の TAB がすでにあります。名前を変えるか、先に削除してください');
        }
        $json = json_encode($c['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $linksJson = json_encode($c['links'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($cur) {
            // tab_id が別の曲に使い回されていたら上書きしない
            if ($cur['song_key'] !== $rev['song_key']) {
                $pdo->rollBack();
                fail(409, '復元できません（別の TAB に置き換わっています）');
            }
            // updated_at = NOW(3) で必ず更新日時を進める（開きっぱなしの人の保存が 409 になり、復元を上書きしない）
            $pdo->prepare('UPDATE song_tab SET part = ?, instrument_id = ?, tuning = ?, bpm = ?, beats_per_bar = ?, steps_per_beat = ?,
                    offset_ms = ?, first_bar = ?, is_partial = ?, capo = ?, youtube_id = ?, links = ?, memo = ?, data = ?, updated_by = ?, updated_at = NOW(3) WHERE tab_id = ?')
                ->execute([$c['part'], $c['instrument_id'], $c['tuning'], $c['bpm'], $c['beats_per_bar'], $c['steps_per_beat'],
                    $c['offset_ms'], $c['first_bar'], $c['is_partial'], $c['capo'], $c['youtube_id'], $linksJson, $c['memo'], $json, $userId, $tabId]);
        } else {
            // 削除されていた: 同じ tab_id で入れ直す。曲（track）やアカウントがもう無ければ NULL にする（外部キーを通すため）
            $source = $c['track_source'];
            $trackId = $c['track_id'];
            if ($source !== null) {
                $st = $pdo->prepare('SELECT 1 FROM track WHERE source = ? AND track_id = ?');
                $st->execute([$source, $trackId]);
                if (!$st->fetchColumn()) {
                    $source = $trackId = null;
                }
            }
            $createdBy = null;
            if ($c['created_by'] !== null) {
                $st = $pdo->prepare('SELECT 1 FROM user_account WHERE user_id = ?');
                $st->execute([$c['created_by']]);
                $createdBy = $st->fetchColumn() ? $c['created_by'] : null;
            }
            $pdo->prepare('INSERT INTO song_tab (tab_id, song_key, track_source, track_id, title, artist_name, part, instrument_id, tuning,
                    bpm, beats_per_bar, steps_per_beat, offset_ms, first_bar, is_partial, kind, capo, youtube_id, links, memo, data, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tabId, $rev['song_key'], $source, $trackId, $c['title'], $c['artist_name'], $c['part'], $c['instrument_id'],
                    $c['tuning'], $c['bpm'], $c['beats_per_bar'], $c['steps_per_beat'], $c['offset_ms'], $c['first_bar'], $c['is_partial'], $c['kind'], $c['capo'], $c['youtube_id'], $linksJson, $c['memo'], $json,
                    $createdBy, $userId]);
        }
        tab_record_revision($pdo, $tabId, 'restore', $userId);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail(500, db_error($e, '復元できませんでした'));
    }
    flash('TAB を復元しました');
    echo json_encode(['ok' => true, 'redirect' => 'tab?id=' . $tabId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'save') {
    fail(400, '操作の指定がおかしいです');
}

// ---- 保存: 届いた値を1つずつ検証する（ブラウザから来た値は信用しない）----
$sentUpdatedAt = $body['updated_at'] ?? null;
if (!is_string($sentUpdatedAt) || $sentUpdatedAt === '') {
    fail(400, '更新日時がありません');
}

$part = $body['part'] ?? null;
if (!is_string($part) || preg_match('/[\x00-\x1F\x7F]/u', $part)) {
    fail(400, 'パート名がおかしいです');
}
$part = trim($part);
if ($part === '' || mb_strlen($part) > 20) {
    fail(400, 'パート名は1〜20文字で入力してください');
}

$bpm = $body['bpm'] ?? null;
if ((!is_int($bpm) && !is_float($bpm)) || !is_finite((float)$bpm) || $bpm < 20 || $bpm > 400) {
    fail(400, 'BPM は20〜400で入力してください');
}
$bpm = round((float)$bpm, 2); // DECIMAL(5,2)

$beats = tab_int($body['beats_per_bar'] ?? null);
if ($beats === null || $beats < 1 || $beats > 16) {
    fail(400, '拍子は1〜16拍で入力してください');
}
$steps = tab_int($body['steps_per_beat'] ?? null);
if ($steps === null || !in_array($steps, TAB_STEPS, true)) {
    fail(400, '分割がおかしいです');
}
$capo = tab_int($body['capo'] ?? 0);
if ($capo === null || $capo < 0 || $capo > TAB_MAX_CAPO) {
    fail(400, 'カポは0〜12で入力してください');
}
$memo = tab_sanitize_memo($body['memo'] ?? null);
if ($memo === false) {
    fail(400, 'メモは' . TAB_MEMO_MAX . '文字までです');
}
$memo = $memo === '' ? null : $memo;
$links = tab_sanitize_links($body['links'] ?? []);
if ($links === null) {
    fail(400, 'リンクがおかしいです（https の URL を最大' . TAB_MAX_LINKS . '個）');
}
$linksJson = json_encode($links, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$offset = tab_int($body['offset_ms'] ?? null);
if ($offset === null || $offset < 0 || $offset > TAB_MAX_MS) {
    fail(400, '開始位置がおかしいです');
}

// YouTube: 空なら NULL。それ以外は URL から動画 ID だけ取り出して保存する（URL の変なものは保存しない）
$youtubeUrl = $body['youtube_url'] ?? '';
if (!is_string($youtubeUrl)) {
    fail(400, 'YouTube の URL がおかしいです');
}
$youtubeUrl = trim($youtubeUrl);
$youtubeId = null;
if ($youtubeUrl !== '') {
    $youtubeId = youtube_video_id($youtubeUrl);
    if ($youtubeId === null) {
        fail(400, 'YouTube の動画の URL ではありません');
    }
}

// 種類（string / drum）は作ったあとに変えられない。チューニング・音符の検査の前に調べる
$st = $pdo->prepare('SELECT kind FROM song_tab WHERE tab_id = ?');
$st->execute([$tabId]);
$kind = $st->fetchColumn();
if ($kind === false) {
    fail(404, 'TAB が見つかりません');
}
$firstBar = tab_int($body['first_bar'] ?? 1);
if ($firstBar === null || $firstBar < 1 || $firstBar > 999) {
    fail(400, '開始小節は1〜999で入力してください');
}
$isPartial = !empty($body['is_partial']) ? 1 : 0;

$tuning = $body['tuning'] ?? null;
if (!is_string($tuning)) {
    fail(400, 'チューニングがおかしいです');
}
$tuning = trim(preg_replace('/ +/', ' ', $tuning) ?? '');
if (!tab_tuning_valid_for($kind, $tuning)) {
    fail(400, 'チューニングがおかしいです');
}

try {
    // 行をロックして読み、更新日時を比べてから書く（比べてから書くまでの間に別の人が書き込めないように）
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT tuning, updated_at FROM song_tab WHERE tab_id = ? FOR UPDATE');
    $st->execute([$tabId]);
    $row = $st->fetch();
    if (!$row) {
        $pdo->rollBack();
        fail(404, 'TAB が見つかりません');
    }
    // 弦の数は作ったあとに変えられない（音符の「何弦か」が意味を失うため）
    $strings = tab_string_count($row['tuning']);
    if (tab_string_count($tuning) !== $strings) {
        $pdo->rollBack();
        fail(400, '弦の数は変更できません');
    }
    // 音符の検証。step は「1小節のマス数」の範囲内、string は弦の数の範囲内
    $data = tab_sanitize_data($body['data'] ?? null, $strings, $beats * $steps, $kind);
    if ($data === null) {
        $pdo->rollBack();
        fail(400, '譜面のデータがおかしいです');
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    if ($row['updated_at'] !== $sentUpdatedAt) {
        $pdo->rollBack();
        fail(409, '他の人が更新しました。再読み込みしてください');
    }
    $pdo->prepare('UPDATE song_tab SET part = ?, tuning = ?, bpm = ?, beats_per_bar = ?, steps_per_beat = ?,
            offset_ms = ?, first_bar = ?, is_partial = ?, capo = ?, youtube_id = ?, links = ?, memo = ?, data = ?, updated_by = ?
        WHERE tab_id = ?')
        ->execute([$part, $tuning, $bpm, $beats, $steps, $offset, $firstBar, $isPartial, $capo, $youtubeId, $linksJson, $memo, $json, $userId, $tabId]);
    tab_record_revision($pdo, $tabId, 'save', $userId); // 変更と同じトランザクションで履歴を残す
    // 何も変わっていないと updated_at は動かない。いずれにせよ DB の今の値を返す
    $st = $pdo->prepare('SELECT updated_at FROM song_tab WHERE tab_id = ?');
    $st->execute([$tabId]);
    $updatedAt = (string)$st->fetchColumn();
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 1062 = UNIQUE 違反（同じ曲に同じパート名がもうある）
    if (($e->errorInfo[1] ?? 0) === 1062) {
        fail(409, '同じパート名がすでにあります');
    }
    fail(500, db_error($e, '保存できませんでした'));
}

echo json_encode(['ok' => true, 'updated_at' => $updatedAt], JSON_UNESCAPED_UNICODE);

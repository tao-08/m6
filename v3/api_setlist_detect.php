<?php
/**
 * =====================================================================
 *  api_setlist_detect.php — バンドの YouTube から曲を検出する API（JSON を返す）。管理者だけ
 * =====================================================================
 *  songs_edit.php の「YouTube から曲を検出」（assets/setlist_detect.js）が fetch() で呼ぶ。
 *  リクエスト（POST）: band_id
 *  すぐ {"state": "running"} を返し、裏で動画ごとの MP3（YouTube の区分。lib/chapter_job.php の band_video_mp3）を聴かせ、
 *  Gemini が聴き終わるまで待つ。MP3 の無い動画が1本でもあれば検出しない（YouTube からは落とさない）。flex=0 なら Standard（速い）、それ以外は Flex（半額。数分〜15分）。途中経過は setlist_job_*（lib/gemini_setlist.php）
 *  status=1: 途中経過を見る。{"state": "running"} / 終わっていれば曲名・候補・始まる時刻をセッションに置いて {"state": "done"}
 *    → 画面が songs_edit?from_detect=1 で確認画面を開く
 *  終わったら、押した人の端末すべてにプッシュ通知を送る（lib/push.php。画面を閉じていても気づけるように）
 *  src=a:N / d:ファイルID: YouTube の代わりにバンドの MIX（アップロードした MP3 / ドライブ）を聴かせる。始まりの秒は YouTube の秒ではないので、セトリには入れない
 *    代わりに、その MIX の曲のチャプター（band_audio_chapter）にする。status=1 で終わったとき、チャプターが無ければすぐ保存
 *    あれば保存せず {"state": "done", "overwrite": true} を返す → 画面が上書きを聞き、はいなら chapters=1 で保存（それまでセッションに置く）
 *  動画が2本以上（band_video）なら1本ずつ順に聴かせてつなぐ。曲ごとに何本目か（video = song.video_no）を付ける
 *  1曲だけ検出し直す（↻）: from, to（秒）と video（何本目。0 = 1本目）も送ると、その動画の MP3 から区間を切り出して聴かせ、{"candidates": [{title, artist}]} を返す。exclude[] の曲名は候補に出さない。compare=1 で試聴と聴き比べる。se=1 は SE
 *    tail=1: 区間の最後の曲を答えさせる（その曲の秒がわからず、前の曲の始まりから聴かせるとき）
 *  エラー: {"error": "...", "detail": "理由（あれば。画面の ⓘ に出す）"}（400 / 401 / 403 / 404 / 502 / 503）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/youtube.php';
require_once __DIR__ . '/lib/ytdlp.php'; // audio_cut_mp3
require_once __DIR__ . '/lib/gemini_setlist.php';
require_once __DIR__ . '/lib/push.php';
require_once __DIR__ . '/lib/chapter_job.php'; // band_mix_file・band_video_mp3

header('Content-Type: application/json; charset=utf-8');

$user = api_user('is_admin', '管理者のみ使えます'); // 送るたびに Gemini の料金がかかるので管理者だけ

$cfg = gemini_setlist_config() ?? fail(503, '曲の検出は使えません');
$bandId = (int)($_POST['band_id'] ?? 0);
// コピー元のアーティスト（オムニバスのバンドは持たないので NULL）。iTunes の曲名一覧を Gemini に渡して選ばせる（lib/gemini_setlist.php）
$st = db()->prepare('SELECT b.band_id, b.name, b.youtube_url, b.song_count, IF(b.is_omnibus = 1, NULL, a.name) AS artist FROM band b LEFT JOIN artist a ON a.artist_id = b.artist_id WHERE b.band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch() ?: fail(404, 'バンドが見つかりません');
// 動画 ID（並びの番号 = song.video_no）。保存のときに確かめた URL だけなので、ID が取れないもの（再生リストなど）は null のまま番号を保つ
$videoIds = array_map('youtube_video_id', band_video_urls(db(), [$band])[$bandId] ?? []);
$src = (string)($_POST['src'] ?? '');
preg_match('/^(a:\d+|d:[\w-]+)?$/', $src) || fail(400, '音源が正しくありません');

$section = null;
if (isset($_POST['from'])) {
    $from = max(0, (int)$_POST['from']);
    $to = (int)($_POST['to'] ?? 0);
    $section = [$from, $to > $from ? min($to, $from + 900) : $from + 420]; // 最後の曲は次の曲が無いので7分。長すぎる区間は15分で切る
}

// 途中経過を見る（終わっていればセッションに置いて、途中経過は消す）
if (!empty($_POST['status'])) {
    $job = setlist_job_get($bandId) ?? fail(404, '解析していません');
    if ($job['state'] === 'running') {
        api_ok(['state' => 'running']);
    }
    setlist_job_put($bandId, null);
    if ($job['state'] !== 'done') {
        fail(502, (string)($job['error'] ?? '解析に失敗しました'), ['detail' => (string)($job['detail'] ?? '')]);
    }
    $_SESSION['setlist_detect'][$bandId] = array_map(static fn($s) => array_diff_key($s, ['mix_start' => 0, 'end' => 0]), $job['songs']);
    // MIX: 曲の始まり・終わりと MC の秒を、その MIX のチャプターにする（セトリには入れない）。同じ秒は1つ（曲の始まり > MC > 終わり）
    $chapters = [];
    $marks = isset($job['src']) ? [['song', array_column($job['songs'], 'mix_start')], ['mc', $job['mcs'] ?? []], ['end', array_column($job['songs'], 'end')]] : [];
    foreach ($marks as [$kind, $secs]) {
        foreach (array_filter($secs, 'is_int') as $sec) {
            $chapters[$sec] ??= ['kind' => $kind, 'ms' => $sec * 1000];
        }
    }
    if ($chapters) {
        ksort($chapters);
        $st = db()->prepare('SELECT COUNT(*) FROM band_audio_chapter WHERE band_id = ? AND audio_key = ?');
        $st->execute([$bandId, $job['src']]);
        if ((int)$st->fetchColumn() > 0) {
            $_SESSION['setlist_detect_chapters'][$bandId] = [$job['src'], array_values($chapters)];
            api_ok(['state' => 'done', 'overwrite' => true]);
        }
        chapter_job_save($bandId, $job['src'], array_values($chapters));
    }
    api_ok(['state' => 'done']);
}
// MIX のチャプターを上書きする（status=1 が overwrite を返し、はいを押したとき）
if (!empty($_POST['chapters'])) {
    [$key, $chapters] = $_SESSION['setlist_detect_chapters'][$bandId] ?? fail(404, '保存するチャプターがありません');
    unset($_SESSION['setlist_detect_chapters'][$bandId]);
    chapter_job_save($bandId, $key, $chapters);
    api_ok(['saved' => true]);
}

session_write_close(); // 長く待つので、ほかの画面を待たせない
@set_time_limit(0);
ignore_user_abort(true); // 画面を閉じても最後まで続ける（↻ も途中で切れると音声の一時ファイルが残る）

if ($section !== null) { // ↻ はその場で待つ（1曲なので約20秒）
    $videoId = $videoIds[(int)($_POST['video'] ?? 0)] ?? fail(400, 'YouTube のリンクがありません');
    $file = band_mix_file($bandId, band_video_mp3($bandId, $videoId) ?? fail(400, 'MP3 がありません'));
    is_string($file) && fail(502, $file);
    $audio = audio_cut_mp3($file[0], $section[0], $section[1] - $section[0]) ?? fail(502, '音声を切り出せませんでした');
    // exclude[]: もう候補に出た曲名。押すたびに、まだ出ていない曲から探す
    $exclude = array_slice(array_map(static fn($t) => mb_substr(trim((string)$t), 0, 100), array_filter((array)($_POST['exclude'] ?? []), 'is_string')), 0, 60);
    $se = !empty($_POST['se']);
    try {
        // compare=1: ↻ の1回目（と「出た候補を戻す」の後）だけ、候補を試聴と聴き比べる。se=1: SE はコピー元の曲とは限らない
        $cands = gemini_setlist_one($audio, 'audio/mpeg', $cfg, !empty($_POST['tail']), $se ? null : $band['artist'], $exclude, !empty($_POST['compare']) && empty($_POST['tail']) && !$se, $se);
    } finally {
        @unlink($audio);
    }
    if (is_string($cands)) {
        fail(502, $cands, ['detail' => gemini_why()]);
    }
    if (!$cands) {
        fail(404, $exclude ? 'ほかの候補が見つかりませんでした' : '曲が見つかりませんでした');
    }
    api_ok(['candidates' => $cands]);
}
if ((setlist_job_get($bandId)['state'] ?? '') === 'running') {
    api_ok(['state' => 'running']); // もう裏で解析している（二重に払わない）
}
if (bg_job_full()) { // サイト全体が重くならないように（lib/chapter_job.php の BG_JOB_MAX）
    fail(429, 'ほかの解析が終わってから試してください');
}
$src !== '' || ($videoIds[0] ?? null) !== null || fail(400, 'YouTube のリンクがありません');
$mp3s = $src !== '' ? [] : array_map(static fn($v) => band_video_mp3($bandId, $v), array_filter($videoIds)); // 何本目 => 音源キー
in_array(null, $mp3s, true) && fail(400, 'MP3 がない動画があります'); // 全部そろうまで検出しない
$flex = ($_POST['flex'] ?? '1') !== '0';
setlist_job_put($bandId, ['state' => 'running', 'at' => $startedAt = time(), 'flex' => $flex]); // flex は setlist_jobs.php に出す

api_detach(['state' => 'running']); // 画面には先に返して、接続を切る。このあとの処理は裏で続く

try {
    if ($src !== '') { // MIX: 1本だけ聴かせる。曲の終わりと MC も聞く（チャプター用）
        $mcs = [];
        $file = band_mix_file($bandId, $src);
        $part = is_string($file) ? $file : gemini_setlist($file[0], $file[1], $cfg, $band['artist'], $flex, isset($band['song_count']) ? (int)$band['song_count'] : null, null, $mcs);
        $job = is_string($part) ? ['state' => 'error', 'error' => $part, 'detail' => is_string($file) ? '' : gemini_why()] : null;
        $songs = is_string($part) ? [] : array_map(static fn($s) => ['start' => null, 'mix_start' => $s['start']] + $s, $part); // 秒は MIX のチャプターに（status=1）
    }
    $job ??= $src !== '' ? setlist_detect_result($songs) + ['mcs' => $mcs] : setlist_detect_videos($bandId, $band, $mp3s, count($videoIds), $cfg, $flex);
} catch (Throwable $e) {
    error_log('setlist detect: ' . $e->getMessage());
    $job = ['state' => 'error', 'error' => '解析に失敗しました'];
}
setlist_job_put($bandId, $job + ['at' => $startedAt, 'flex' => $flex] + ($src !== '' ? ['src' => $src] : []));

// 押した人に知らせる（アプリ通知の設定によらず、端末があれば送る。押した本人が待っている結果なので）
$subs = db()->prepare('SELECT subscription_id, endpoint, p256dh, auth FROM push_subscription WHERE user_id = ?');
$subs->execute([$user['user_id']]);
push_send($subs->fetchAll(), [
    'title' => $job['state'] === 'done' ? '曲の検出が終わりました' : '曲の検出に失敗しました',
    'body' => $band['name'],
    'url' => 'songs_edit?band=' . $bandId, // 開くと途中経過を見に行き、確認画面へ進む（data-pending）
    'tag' => 'setlist-detect-' . $bandId,
]);

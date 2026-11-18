<?php
/**
 * =====================================================================
 *  api_intro.php — イントロクイズ（intro.php）の出題・採点の API（JSON を返す）
 * =====================================================================
 *  ★ 正解はブラウザに渡さない。ラウンドの状態は $_SESSION['intro'] に持つ（正解の位置・各問を出した時刻・点数）。
 *    ブラウザに渡すのは、いま鳴らす問の音源 URL と、reveal 後の選択肢（曲名 4 つ）だけ。
 *    点数はサーバーが「reveal した時刻から回答が届くまで」で決める（ブラウザの時計は信用しない）。
 *
 *  リクエスト（POST, JSON。CSRF は X-CSRF-Token ヘッダー）: {"action": "...", ...}
 *    start  {year?, live?, artist?}   … ラウンドを始める。値は整数か null だけ通す
 *        → {round, total, ranked, preview}   ranked = ランキングに載るラウンドか（絞り込みなし・10 問）
 *    reveal {round, q}                … その問の選択肢を出す。出した時刻を記録（2 回目以降は記録し直さない）
 *        → {choices: [曲名 × 4]}
 *    answer {round, q, choice}        … 回答（choice は 0〜3 か null = 時間切れ）
 *        → {correct, right, points, score, info: {title, artist, artwork, bands: [{id, name, live}]}, next: {preview} | null, finished}
 *          最後の問のときは finished = true で、best / month_best / ranking / mine / month_ranking / month_mine / last_month_top も返す
 *    broken {round, q}                … まだ reveal していない問の音源が鳴らなかったとき、別の曲に差し替える（1 ラウンド 3 回まで）
 *        → {preview}
 *  エラー: {"error": "..."}（400 / 401 / 405 / 500）
 *  名前・曲名は生のまま返す（JS は textContent で入れる）。SQL はすべてプリペアドステートメント。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/intro.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

/** 送られてきた round（ラウンドの ID）と q（問の番号）が、いまのラウンドの「いま出している問」と合っているか調べて、ラウンドの状態を返す */
function intro_session_round(array $body): array
{
    $r = $_SESSION['intro'] ?? null;
    $id = $body['round'] ?? null;
    if (!is_array($r) || !is_string($id) || !hash_equals($r['id'], $id)) {
        fail(400, '最初からやり直してください');
    }
    $q = $body['q'] ?? null;
    if (!is_int($q) || $q !== $r['current']) {
        fail(400, '最初からやり直してください');
    }
    return $r;
}

/** 曲 1 つから、問題 1 問分（選択肢つき）を作る。ダミーが足りなければ null */
function intro_make_question(array $track, array $scopeTitles, callable $allTitles): ?array
{
    $c = intro_choices($track['title'], $scopeTitles, $allTitles);
    if ($c === null) {
        return null;
    }
    return [
        'key'         => $track['key'],
        'source'      => $track['source'],
        'track_id'    => $track['track_id'],
        'title'       => $track['title'],
        'choices'     => $c[0],
        'correct'     => $c[1],
        'preview'     => $track['preview'],
        'revealed_at' => null,
        'answered'    => false,
    ];
}

$body = api_body();
if (!is_array($body)) {
    fail(400, '送信内容がおかしいです');
}
$action = $body['action'] ?? null;
$userId = (int)$user['user_id'];
$pdo = db();

// 全曲の曲名（ダミーが足りないときだけ読む）
$allTitles = static function () use ($pdo): array {
    static $all = null;
    return $all ??= intro_titles($pdo, ['year' => null, 'live' => null, 'artist' => null]);
};

try {
    switch ($action) {
        case 'start':
            $f = intro_filters_clean($body);
            if ($f === null) {
                fail(400, '送信内容がおかしいです');
            }
            $pool = intro_pool($pdo, $f);
            intro_lookup_previews($pdo, $pool);
            $ready = array_values(array_filter($pool, static fn($t) => $t['preview'] !== null));
            if (count($ready) < INTRO_MIN_SONGS) {
                fail(400, '出題できる曲が足りません');
            }
            shuffle($ready);
            $scopeTitles = intro_titles($pdo, $f);
            $total = min(INTRO_QUESTIONS, count($ready));
            $questions = [];
            foreach ($ready as $t) {
                if (count($questions) >= $total) {
                    break;
                }
                $q = intro_make_question($t, $scopeTitles, $allTitles);
                if ($q !== null) {
                    $questions[] = $q;
                }
            }
            if (count($questions) < INTRO_MIN_SONGS) {
                fail(400, '出題できる曲が足りません');
            }
            $ranked = intro_filters_none($f) && count($questions) === INTRO_QUESTIONS;
            $round = bin2hex(random_bytes(16));
            $_SESSION['intro'] = [
                'id'      => $round,
                'ranked'  => $ranked,
                'filters' => $f,
                'q'       => $questions,
                'current' => 0,
                'score'   => 0,
                'correct' => 0,
                'broken'  => 0,
                'used'    => array_column($questions, 'key'), // 出した曲（差し替えで同じ曲を出さない）
            ];
            api_ok(['round' => $round, 'total' => count($questions), 'ranked' => $ranked, 'preview' => $questions[0]['preview']]);

        case 'reveal':
            $r = intro_session_round($body);
            $q = &$r['q'][$r['current']];
            if ($q['answered']) {
                fail(400, '回答済みです');
            }
            $q['revealed_at'] ??= microtime(true); // 2 回目以降は記録し直さない（遅らせて有利にならないように）
            $choices = $q['choices'];
            unset($q);
            $_SESSION['intro'] = $r;
            api_ok(['choices' => $choices]);

        case 'answer':
            $r = intro_session_round($body);
            $choice = $body['choice'] ?? null;
            if ($choice !== null && (!is_int($choice) || $choice < 0 || $choice >= INTRO_CHOICES)) {
                fail(400, '送信内容がおかしいです');
            }
            $q = &$r['q'][$r['current']];
            if ($q['revealed_at'] === null || $q['answered']) {
                fail(400, '回答できません');
            }
            $elapsed = microtime(true) - $q['revealed_at'];
            $points = ($choice !== null && $choice === $q['correct']) ? intro_points($elapsed) : 0; // 不正解・時間切れ・遅すぎは 0 点
            $q['answered'] = true;
            $correct = $q['correct'];
            $source = $q['source'];
            $trackId = $q['track_id'];
            unset($q);
            $r['score'] += $points;
            $r['correct'] += $points > 0 ? 1 : 0;
            $out = [
                'correct'  => $correct,
                'right'    => $points > 0,
                'points'   => $points,
                'score'    => $r['score'],
                'info'     => intro_track_info($pdo, $r['filters'], $source, $trackId),
                'next'     => null,
                'finished' => false,
            ];
            if ($r['current'] + 1 < count($r['q'])) {
                $r['current']++;
                $out['next'] = ['preview' => $r['q'][$r['current']]['preview']];
                $_SESSION['intro'] = $r;
                api_ok($out);
            }
            // 最後の問: ランキングに載るラウンドなら自己ベストを保存する（保存に失敗したら、状態を進めずエラーにする）
            [$best, $monthBest] = $r['ranked'] ? intro_save_best($pdo, $userId, $r['score'], $r['correct']) : [false, false];
            unset($_SESSION['intro']);
            api_ok(['finished' => true, 'best' => $best, 'month_best' => $monthBest] + $out + intro_rankings($pdo, $userId));

        case 'broken':
            $r = intro_session_round($body);
            if ($r['q'][$r['current']]['revealed_at'] !== null || $r['q'][$r['current']]['answered']) {
                fail(400, '音源を読み込めません');
            }
            if ($r['broken'] >= INTRO_BROKEN_MAX) {
                fail(400, '音源を読み込めません');
            }
            $candidates = array_values(array_filter(intro_pool($pdo, $r['filters']),
                static fn($t) => $t['preview'] !== null && !in_array($t['key'], $r['used'], true)));
            shuffle($candidates);
            $scopeTitles = intro_titles($pdo, $r['filters']);
            $new = null;
            foreach ($candidates as $t) {
                $new = intro_make_question($t, $scopeTitles, $allTitles);
                if ($new !== null) {
                    break;
                }
            }
            if ($new === null) {
                fail(400, '音源を読み込めません');
            }
            $r['q'][$r['current']] = $new;
            $r['used'][] = $new['key'];
            $r['broken']++;
            $_SESSION['intro'] = $r;
            api_ok(['preview' => $new['preview']]);

        default:
            fail(400, '送信内容がおかしいです');
    }
} catch (PDOException $e) {
    fail(500, config('debug') ? $e->getMessage() : '処理できませんでした');
}

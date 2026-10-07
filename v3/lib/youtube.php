<?php
/**
 * =====================================================================
 *  lib/youtube.php — YouTube のプレイリストを読んで、動画をバンドに割り当てる
 * =====================================================================
 *  プレイリストの中身は YouTube Data API v3 の playlistItems で取る（config.php の youtube.api_key が要る）。
 *    HTML を読む方法はキー不要だが、YouTube がページの作りを変えた日に動かなくなるので使わない。
 *    無料枠は1日 10,000 ユニット。playlistItems は 50本ごとに 1 ユニット。
 *
 *  動画とバンドは「タイトルにバンド名が入っているか」で結び付ける（並び順は出演順とは限らないので使わない）。
 *    「12月 SEKAI NO OWARI」「12月スピッツ」「12月ライブ Fall Out Boy」→ 記号・空白・大文字小文字をそろえて部分一致
 *    「12月エルレ（鈴木）」のような略称は、artist.php でアーティストの別名（エルレ → ELLEGARDEN）を登録すれば当たる
 *  自動で決めきれないもの（1バンドに2本ある・同じ名前のバンドが2組ある）は、確認画面（live_youtube.php）で人が選ぶ。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/albums.php';       // album_http（通信）・album_match_key（表記ゆれをそろえる）
require_once __DIR__ . '/import/text.php';  // band_split_suffix（「ヨルシカ(安田)」→ ヨルシカ / 安田）

/** 読み込むページ数の上限（50本 × 10 = 500本）。変なプレイリストで延々と通信しないように */
const YOUTUBE_MAX_PAGES = 10;

/** YouTube の動画 ID（11文字の英数字と - _）か */
function youtube_video_id_valid(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{11}$/', $id);
}

/** 動画 ID から、保存する URL を作る（ID は検証済みのものだけ渡す） */
function youtube_watch_url(string $videoId): string
{
    return 'https://www.youtube.com/watch?v=' . $videoId;
}

/**
 * 動画の URL から動画 ID を取り出す。動画の URL でなければ null
 *   https://www.youtube.com/watch?v=ID / https://youtu.be/ID
 */
function youtube_video_id(string $url): ?string
{
    if (!youtube_url_valid($url)) {
        return null;
    }
    $parts = parse_url($url);
    if (strtolower($parts['host'] ?? '') === 'youtu.be') {
        $id = ltrim($parts['path'] ?? '', '/');
    } else {
        parse_str($parts['query'] ?? '', $q);
        $id = $q['v'] ?? '';
    }
    return is_string($id) && youtube_video_id_valid($id) ? $id : null;
}

/**
 * URL からプレイリストの ID（list=…）を取り出す。プレイリストの URL でなければ null
 *   https://www.youtube.com/playlist?list=PLxxxx / https://www.youtube.com/watch?v=xxx&list=PLxxxx のどちらも OK
 */
function youtube_playlist_id(string $url): ?string
{
    if (!youtube_url_valid($url)) {
        return null;
    }
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q); // parse_str: "a=1&b=2" → ['a' => '1', 'b' => '2']
    $list = $q['list'] ?? '';
    return is_string($list) && preg_match('/^[A-Za-z0-9_-]{2,64}$/', $list) ? $list : null;
}

/**
 * プレイリストの動画を全部取ってくる。
 *   戻り値: [['video_id' => 'JHxMHX59ASY', 'title' => '12月 SEKAI NO OWARI'], ...]（プレイリストの順）
 *   非公開・削除済みの動画は飛ばす（見られないリンクを入れても意味がないので）
 * @throws RuntimeException キーが無い・通信できない・プレイリストが見つからない
 */
function youtube_playlist_items(string $playlistId): array
{
    $key = (string)(config('youtube')['api_key'] ?? '');
    if ($key === '') {
        throw new RuntimeException('config.php に YouTube の API キー（youtube.api_key）が設定されていません');
    }
    $videos = [];
    $pageToken = '';
    for ($page = 0; $page < YOUTUBE_MAX_PAGES; $page++) {
        // http_build_query: 配列 → "a=1&b=2"（値は自動で URL エンコードされる）
        $query = http_build_query(['part' => 'snippet,status', 'maxResults' => 50, 'playlistId' => $playlistId]
            + ($pageToken !== '' ? ['pageToken' => $pageToken] : []));
        // キーは URL ではなくヘッダーで渡す（アクセスログに残らないように）
        $res = album_http('https://www.googleapis.com/youtube/v3/playlistItems?' . $query, ['X-Goog-Api-Key: ' . $key]);
        if ($res === null) {
            throw new RuntimeException('YouTube に接続できませんでした。時間をおいてもう一度試してください');
        }
        if ($res['status'] === 404) {
            throw new RuntimeException('プレイリストが見つかりません（非公開になっていないか確認してください）');
        }
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            $reason = $res['json']['error']['message'] ?? ('HTTP ' . $res['status']);
            throw new RuntimeException('YouTube からプレイリストを読めませんでした（' . $reason . '）');
        }
        foreach ($res['json']['items'] ?? [] as $item) {
            $id = (string)($item['snippet']['resourceId']['videoId'] ?? '');
            $privacy = $item['status']['privacyStatus'] ?? '';
            if (!youtube_video_id_valid($id) || !in_array($privacy, ['public', 'unlisted'], true)) {
                continue;
            }
            $videos[] = ['video_id' => $id, 'title' => (string)($item['snippet']['title'] ?? '')];
        }
        $pageToken = (string)($res['json']['nextPageToken'] ?? '');
        if ($pageToken === '') {
            break;
        }
    }
    return $videos;
}

/**
 * 動画をバンドに割り当てる（DB も通信も使わない、純粋な計算だけ → tests/run.php でテストできる）
 *
 * @param array $videos [['video_id' => ..., 'title' => ...], ...]
 * @param array $bands  [band_id => ['name' => 'ELLEGARDEN(鈴木)', 'names' => ['ELLEGARDEN', 'エルレ', ...]], ...]
 *                      names = タイトルの中で探す名前（バンド名の括弧の前・アーティスト名・アーティストの別名）
 * @return array{bands: array<int, string[]>, auto: array<int, string>, unmatched: string[]}
 *   bands[band_id] = そのバンドの候補の動画 ID（プレイリストの順）
 *   auto[band_id]  = 迷わず決められた動画 ID（候補が1本だけで、その動画を取り合うバンドもいない）
 *   unmatched      = どのバンドにも当たらなかった動画 ID
 */
function youtube_match_bands(array $videos, array $bands): array
{
    // バンドごとに「探す名前」と「括弧の中（代表者名）」を比べやすい形にしておく
    $prepared = [];
    foreach ($bands as $id => $b) {
        [$core, $suffix] = band_split_suffix($b['name']);
        $keys = [];
        foreach ([$core, ...($b['names'] ?? [])] as $n) {
            $k = album_match_key((string)$n);
            if (mb_strlen($k) >= 2) { // 1文字の名前は、どのタイトルにも入っていそうなので使わない
                $keys[$k] = true;
            }
        }
        $prepared[$id] = ['keys' => array_keys($keys), 'suffix' => album_match_key($suffix)];
    }

    $result = ['bands' => array_fill_keys(array_keys($bands), []), 'unmatched' => []];
    $owners = []; // video_id => その動画が候補になったバンドの数
    foreach ($videos as $v) {
        $title = album_match_key($v['title']);
        // タイトルに名前が入っているバンドを集める。値は当たった名前の長さ（長いほど確か）
        $hits = [];
        foreach ($prepared as $id => $p) {
            foreach ($p['keys'] as $k) {
                if (str_contains($title, $k)) {
                    $hits[$id] = max($hits[$id] ?? 0, mb_strlen($k));
                }
            }
        }
        // 同じ名前のバンドが2組（エルレ(岩崎) と エルレ(鈴木)）→ 括弧の中の名前がタイトルにある方に絞る
        if (count($hits) > 1) {
            $bySuffix = array_filter($hits, fn($len, $id) => $prepared[$id]['suffix'] !== ''
                && str_contains($title, $prepared[$id]['suffix']), ARRAY_FILTER_USE_BOTH);
            if ($bySuffix) {
                $hits = $bySuffix;
            }
        }
        // まだ2組以上なら、長い名前で当たった方（「Mrs. GREEN APPLE」と「GREEN」なら前者）
        if (count($hits) > 1) {
            $best = max($hits);
            $hits = array_filter($hits, fn($len) => $len === $best);
        }
        if (!$hits) {
            $result['unmatched'][] = $v['video_id'];
            continue;
        }
        // それでも決まらないときは全部の候補に入れる（確認画面で人が選ぶ）
        foreach (array_keys($hits) as $id) {
            $result['bands'][$id][] = $v['video_id'];
        }
        $owners[$v['video_id']] = count($hits);
    }
    // 自動で入れてよいのは「候補が1本だけ」かつ「その動画が他のバンドの候補になっていない」バンドだけ
    $result['auto'] = [];
    foreach ($result['bands'] as $id => $ids) {
        if (count($ids) === 1 && ($owners[$ids[0]] ?? 0) === 1) {
            $result['auto'][$id] = $ids[0];
        }
    }
    return $result;
}

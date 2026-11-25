<?php
/**
 * =====================================================================
 *  lib/acrcloud.php — ACRCloud File Scanning でライブ音源の演奏曲を検出する
 * =====================================================================
 *  バンド編集で選んだ動画から、ブラウザ（assets/acr_scan.js）が音だけ抜いた MP3 を
 *  api_acr_scan.php がここ経由で ACRCloud に送る。結果は songs_edit.php?from_acr=1 が曲名にする。
 *  scripts/acr_poc.php（検証用）も結果の統合（acr_merge_results）にこれを使う。
 *  設定は config.php の 'acrcloud'。トークンは画面・ログに出さない。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/albums.php'; // album_match_key（曲名の表記ゆれをそろえる）

/** この秒数以内の重なり・すき間なら、同じ曲の同じ演奏として1行にまとめる */
const ACR_MERGE_GAP_SEC = 5.0;
/** コンテナを作れるリージョン（URL のホスト名に入れるので、この中の値しか使わない） */
const ACR_REGIONS = ['eu-west-1', 'us-west-2', 'ap-southeast-1'];
/** 送れる MP3 の大きさ（既存のバンドの MP3 と同じ上限） */
const ACR_UPLOAD_MAX_BYTES = 60 * 1024 * 1024;
/** 曲名から消す付け足し（ライブ版・カラオケ版などが当たっても、同じ曲として扱う） */
const ACR_VERSION_WORDS = 'live|karaoke|instrumental|off ?vocal|remaster(?:ed)?|ver\.?|version|edit|mix|acoustic|ライブ|カラオケ|オフボーカル|インスト';

/**
 * results.music / results.cover_songs の1要素を、扱いやすい形にそろえる
 *   戻り値: ['start' => 秒, 'end' => 秒 または null, 'title', 'artist', 'src' => 'music'|'cover', 'score' => int|null, 'isrc']。
 *   曲名が取れない要素は null
 */
function acr_normalize_item(array $e, string $src): ?array
{
    $title = trim((string)($e['result']['title'] ?? ''));
    if ($title === '' || !isset($e['offset']) || !is_numeric($e['offset'])) {
        return null;
    }
    $names = [];
    foreach ($e['result']['artists'] ?? [] as $a) {
        $n = trim((string)($a['name'] ?? ''));
        if ($n !== '') {
            $names[] = $n;
        }
    }
    $start = (float)$e['offset'];
    $dur = isset($e['played_duration']) && is_numeric($e['played_duration']) ? (float)$e['played_duration'] : null;
    // score が無いものは null のまま（確度を推測しない）。music の score は 0〜100 の整数
    $score = isset($e['result']['score']) && is_numeric($e['result']['score']) ? (int)$e['result']['score'] : null;
    return [
        'start'  => $start,
        'end'    => $dur !== null ? $start + $dur : null,
        'title'  => $title,
        'artist' => implode(', ', $names),
        'src'    => $src,
        'score'  => $score,
        'isrc'   => trim((string)($e['result']['external_ids']['isrc'] ?? '')),
    ];
}

/** 2つの要素が「同じ曲」か。ISRC が両方あればそれで、無ければ曲名の表記をそろえて比べる */
function acr_same_song(array $a, array $b): bool
{
    if ($a['isrc'] !== '' && $b['isrc'] !== '') {
        return strcasecmp($a['isrc'], $b['isrc']) === 0;
    }
    $ka = album_match_key($a['title']);
    return $ka !== '' && $ka === album_match_key($b['title']);
}

/**
 * music と cover_songs をまとめて、開始位置の順に並べる
 *   同じ曲で時間帯が重なる（またはほぼ隣り合う）ものは1行に統合する → 由来は 'music+cover'
 *   同じ曲でも離れた場所なら別の行（再演）
 * @param array $results 取得結果の results（music / cover_songs を持つ）
 * @return array[] acr_normalize_item の形（src は 'music' | 'cover' | 'music+cover'）
 */
function acr_merge_results(array $results): array
{
    $items = [];
    foreach (['music' => 'music', 'cover_songs' => 'cover'] as $key => $src) {
        foreach ($results[$key] ?? [] as $e) {
            $it = is_array($e) ? acr_normalize_item($e, $src) : null;
            if ($it !== null) {
                $items[] = $it;
            }
        }
    }
    usort($items, fn($a, $b) => $a['start'] <=> $b['start']);

    $rows = [];
    foreach ($items as $it) {
        $hit = null;
        foreach ($rows as $i => $r) {
            // 長さが取れない要素は、開始位置だけで比べる
            $rEnd = $r['end'] ?? $r['start'];
            if (acr_same_song($r, $it) && $it['start'] <= $rEnd + ACR_MERGE_GAP_SEC && ($it['end'] ?? $it['start']) >= $r['start'] - ACR_MERGE_GAP_SEC) {
                $hit = $i;
                break;
            }
        }
        if ($hit === null) {
            $rows[] = $it;
            continue;
        }
        $r = $rows[$hit];
        $r['start'] = min($r['start'], $it['start']);
        $ends = array_filter([$r['end'], $it['end']], fn($v) => $v !== null);
        $r['end'] = $ends ? max($ends) : null;
        if ($r['src'] !== $it['src']) {
            $r['src'] = 'music+cover';
        }
        $r['score'] = $r['score'] ?? $it['score'];
        $r['isrc'] = $r['isrc'] !== '' ? $r['isrc'] : $it['isrc'];
        if ($r['artist'] === '') {
            $r['artist'] = $it['artist'];
        }
        $rows[$hit] = $r;
    }
    usort($rows, fn($a, $b) => $a['start'] <=> $b['start']);
    return $rows;
}

/** 曲名から「(Live)」「- カラオケ -」「[Instrumental]」などの付け足しを消す */
function acr_clean_title(string $title): string
{
    $w = ACR_VERSION_WORDS;
    // 括弧の中に付け足しの言葉があれば、括弧ごと消す
    $t = preg_replace('/\s*[(\[（【〔][^)\]）】〕]*(?:' . $w . ')[^)\]）】〕]*[)\]）】〕]/iu', '', $title) ?? $title;
    // 「 - Live at …」「 -カラオケ-」のような後ろの付け足し
    $t = preg_replace('/\s+[-‐–—~〜]\s*[^-‐–—~〜]*(?:' . $w . ')[^-‐–—~〜]*[-‐–—~〜]?\s*$/iu', '', $t) ?? $t;
    $t = trim($t);
    return $t !== '' ? $t : trim($title);
}

/**
 * 検出結果 → セトリの曲名（演奏した順）
 *   ライブ版・カラオケ版・原曲が別々に当たるので、付け足しを消した曲名で1つにまとめ、最初に当たった位置の順に並べる
 *   shortcut: 同じ曲を2回演奏しても1曲になる。再演するバンドが出てきたら、位置が離れていれば分ける
 * @return string[]
 */
function acr_setlist_titles(array $results): array
{
    $titles = [];
    foreach (acr_merge_results($results) as $r) {
        $title = acr_clean_title($r['title']);
        $key = album_match_key($title);
        $titles[$key !== '' ? $key : $title] ??= $title;
    }
    return array_values($titles);
}

function acr_valid_file_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9-]{8,64}$/', $id);
}

/** config.php の設定。使えないとき（トークン・コンテナが無い・リージョンが変）は null */
function acr_config(): ?array
{
    $c = config('acrcloud');
    if (!is_array($c)) {
        return null;
    }
    $token = trim((string)($c['access_token'] ?? ''));
    $container = trim((string)($c['container_id'] ?? ''));
    $region = trim((string)($c['region'] ?? 'ap-southeast-1'));
    if ($token === '' || !ctype_digit($container) || !in_array($region, ACR_REGIONS, true)) {
        return null;
    }
    return ['token' => $token, 'files' => 'https://api-' . $region . '.acrcloud.com/api/fs-containers/' . $container . '/files'];
}

/** ACRCloud を呼ぶ。戻り値: [HTTP ステータス, JSON]。つながらなければ null */
function acr_request(array $env, string $url, mixed $postFields, int $timeout): ?array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $env['token'], 'Accept: application/json', 'User-Agent: AbbeyRoad.online'],
    ];
    if ($postFields !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $postFields;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    if ($body === false) {
        error_log('ACRCloud cURL failure: errno=' . curl_errno($ch));
        return null;
    }
    $json = json_decode((string)$body, true);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), is_array($json) ? $json : []];
}

/** MP3 を送って解析を始める。戻り値: file_id。失敗したら null（理由はログへ） */
function acr_upload_audio(array $env, string $path, string $name): ?string
{
    $res = acr_request($env, $env['files'], [
        'data_type' => 'audio',
        'file'      => new CURLFile($path, 'audio/mpeg', $name),
    ], 120);
    if ($res === null || !in_array($res[0], [200, 201], true)) {
        error_log('ACRCloud upload failed: HTTP ' . ($res[0] ?? 0) . ' ' . substr((string)($res[1]['message'] ?? $res[1]['error'] ?? ''), 0, 200));
        return null;
    }
    $id = (string)($res[1]['data']['id'] ?? '');
    return acr_valid_file_id($id) ? $id : null;
}

/** 解析の状態と結果。戻り値: ACRCloud の file（state: 0 処理中 / 1 完了 / -1 結果なし / -2, -3 エラー）。取れなければ null */
function acr_fetch_file(array $env, string $fileId): ?array
{
    if (!acr_valid_file_id($fileId)) {
        return null;
    }
    $res = acr_request($env, $env['files'] . '/' . $fileId, null, 20);
    $file = $res !== null && $res[0] === 200 ? ($res[1]['data'][0] ?? null) : null;
    return is_array($file) ? $file : null;
}

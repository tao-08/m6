<?php
/**
 * lib/gemini_setlist.php — ライブ音源を Gemini に聴かせて、演奏した曲名を演奏順に取り出す（動画から曲を検出）
 *   セットリスト編集の「YouTube から曲を検出」で、api_setlist_detect.php が動画ごとの MP3（YouTube の区分）を渡す。
 *   結果はセトリの確認画面（songs_edit.php?from_detect=1）に並べるだけで、保存は人が確かめてから
 */
declare(strict_types=1);

require_once __DIR__ . '/gemini_chapters.php'; // gemini_audio_json（音源を上げて聴かせる）
require_once __DIR__ . '/albums.php'; // itunes_artist_titles（コピー元の曲名一覧）

const GEMINI_SETLIST_PROMPT = <<<'TXT'
添付は学生バンドのライブ音源で、既存の曲のカバー演奏です。演奏された曲を、演奏した順にすべて挙げてください。

- 歌詞・メロディ・曲調から原曲を特定し、原曲の正式な曲名とアーティスト名を答える
- MC で曲名が言われたら、それも手がかりにする
- 曲を特定できる自信があれば confident を true、自信がなければ false にする
- confident が false の曲は、可能性のある曲を candidates に5曲、可能性の高い順に挙げ、title には最も可能性の高い曲を入れる。特定できなくても、歌詞・曲調・アレンジが近い曲を挙げる
- start には曲が始まる時刻を「分:秒」（1時間を超えたら「時:分:秒」）で答える
- 同じ曲を2回演奏したら2回挙げる
- 入場の SE・転換中に流れる音源は含めない
TXT;

/** 2026-11 にライブ音源で比べ、2回とも全曲当たった（3.8-flash は 2/3 と 1/3）。モデルが無くなったらここを変える */
const GEMINI_SETLIST_MODEL = 'gemini-4-hydrogen';

/** config.php の Gemini の API キー。無ければ null */
function gemini_setlist_config(): ?array
{
    $g = (array)(config('gemini') ?? []);
    $key = trim((string)($g['api_key'] ?? ''));
    return $key !== '' ? ['key' => $key, 'model' => GEMINI_SETLIST_MODEL] : null;
}

/**
 * 考える量の上限（トークン）。2026-11 に試したところ、上限なしだと知らない曲で 1.8万トークン悩み1本 約29円になった。
 * 1024 でもサンボマスターは全曲当たり、1本 約10円
 */
const GEMINI_SETLIST_THINKING = 1024;

/** 自信が低い曲に付ける候補の数 */
const GEMINI_SETLIST_MAX_CANDIDATES = 5;

/**
 * Gemini の返事 → 曲（演奏順）
 *   自信が低い曲は candidates（[{title, artist}]、可能性の高い順）を付ける。曲名が空なら第1候補を曲名にする
 *   「曲はあるがわからない」曲は、曲名が空のまま残す（確認画面で何曲目かがわかるように）
 *   start: 曲が始まる秒（読めなければ null）。確認画面で YouTube のその場面へ飛ぶのに使う
 * @return array<int, array{title: string, start: ?int, candidates: array<int, array{title: string, artist: string}>}>
 */
function gemini_setlist_songs(array $json, int $max = GEMINI_SETLIST_MAX_CANDIDATES): array
{
    $clean = static fn(mixed $v, int $len): string => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $len);
    $songs = [];
    foreach (is_array($json['songs'] ?? null) ? $json['songs'] : [] as $s) {
        if (!is_array($s)) {
            continue;
        }
        $cands = [];
        if (($s['confident'] ?? true) === false) {
            foreach (is_array($s['candidates'] ?? null) ? $s['candidates'] : [] as $c) {
                $t = is_array($c) ? $clean($c['title'] ?? '', 100) : '';
                if ($t !== '' && count($cands) < $max) {
                    $cands[] = ['title' => $t, 'artist' => $clean($c['artist'] ?? '', 100)];
                }
            }
        }
        $title = $clean($s['title'] ?? '', 100);
        $ms = is_string($s['start'] ?? null) ? gemini_chapter_ms($s['start']) : null;
        $songs[] = ['title' => $title !== '' ? $title : ($cands[0]['title'] ?? ''), 'start' => $ms === null ? null : intdiv($ms, 1000), 'candidates' => $cands,
            'artist' => $clean($s['artist'] ?? '', 100)]; // 自信ありの曲も、念のため試聴できるよう候補を1曲出すのに使う（songs_edit.php）
        if (is_string($s['end'] ?? null) && ($end = gemini_chapter_ms($s['end'])) !== null) { // MIX のときだけ聞く（gemini_setlist の $mcs）
            $songs[array_key_last($songs)]['end'] = intdiv($end, 1000);
        }
    }
    return $songs;
}

/** 特定できた曲か（曲名があり、候補が付いていない） */
function gemini_setlist_sure(array $song): bool
{
    return $song['title'] !== '' && !$song['candidates'];
}

/** 特定できなかった曲（曲名が空・候補つき）があるか */
function gemini_setlist_has_unknown(array $songs): bool
{
    return !$songs || count(array_filter($songs, 'gemini_setlist_sure')) < count($songs);
}

/**
 * 1回目の結果の、特定できなかった曲だけを2回目（コピー元を伝えて聴かせた結果）で置き換える。1回目で特定できた曲は変えない
 *   1回目に1曲も特定できていなければ、2回目をそのまま使う
 *   shortcut: 曲の数が違うと、どの曲がどれに当たるかわからないので埋めない。数がずれる例が多ければ、当たった曲名を目印に合わせる
 */
function gemini_setlist_fill(array $first, array $second): array
{
    if (!array_filter($first, 'gemini_setlist_sure')) {
        return $second;
    }
    if (count($first) !== count($second)) {
        return $first;
    }
    foreach ($first as $i => $song) {
        if (!gemini_setlist_sure($song)) {
            $first[$i] = $second[$i];
        }
    }
    return $first;
}

/** コピー元を伝える指示。2026-11 に試し、最初から渡すと有名曲の当たりが落ちたので、わからない曲が残ったときだけ使う */
function gemini_setlist_hint_prompt(string $artist): string
{
    return GEMINI_SETLIST_PROMPT . "
- このバンドは「{$artist}」のコピーバンドとして登録されている。{$artist} の曲を中心に考える
- candidates の上位3曲は {$artist} の曲にする";
}

/**
 * コピー元の曲名一覧を渡して、その中から選ばせる指示。
 *   2026-11 に6本で試し、一覧なし（2回聴かせる）より「自信ありで外れ」が7曲→3曲に減った。1回で済むので料金も半分
 * @param string[] $titles itunes_artist_titles
 */
function gemini_setlist_list_prompt(string $artist, array $titles): string
{
    return GEMINI_SETLIST_PROMPT . "
- このバンドは「{$artist}」のコピーバンド。曲名は下の一覧から選ぶ。一覧に無い曲だと確信できるときだけ、一覧外の曲名を答える
- candidates の上位3曲も一覧から選ぶ

【{$artist} の曲一覧】
" . implode("\n", $titles);
}

/** Gemini に返させる JSON の形 */
function gemini_setlist_schema(): array
{
    $pair = ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING'], 'artist' => ['type' => 'STRING']], 'required' => ['title', 'artist']];
    return [
        'type' => 'OBJECT',
        'properties' => ['songs' => ['type' => 'ARRAY', 'items' => [
            'type' => 'OBJECT',
            'properties' => $pair['properties'] + [
                'start' => ['type' => 'STRING'],
                'confident' => ['type' => 'BOOLEAN'],
                'candidates' => ['type' => 'ARRAY', 'items' => $pair],
            ],
            'required' => ['title', 'artist', 'start', 'confident'],
        ]]],
        'required' => ['songs'],
    ];
}

/**
 * @param string $mime 音声の種類（YouTube の m4a は audio/aac）
 * @param bool $flex Flex（半額・遅い）で聴かせるか。false なら Standard（速い・料金は2倍）
 * @param ?string $artist コピー元のアーティスト（オムニバス・不明なら null）。iTunes に曲があれば一覧から選ばせる（1回）。
 *   無ければ一覧なしで聴かせ、わからない曲が残ったらアーティストだけ伝えてもう一度聴かせる。一覧があれば、曲ごとに歌詞と試聴で確かめる（gemini_setlist_check）
 * @param ?int $count タイムテーブルの曲数（band.song_count。不明なら null）。伝えて聴き落としを減らす
 * @param ?array $part [何本目, 全部で何本]。1つのライブを「バンド名 1」「バンド名 2」のように分けた動画のとき。1本だけなら null
 * @param ?array $mcs 渡すと、曲の終わり（曲の end）と MC の始まりの秒（$mcs に入れる）も聞く。MIX のチャプター用（api_setlist_detect.php）
 * @return array|string gemini_setlist_songs の形。失敗したら画面に出せる理由
 */
function gemini_setlist(string $path, string $mime, array $cfg, ?string $artist = null, bool $flex = true, ?int $count = null, ?array $part = null, ?array &$mcs = null): array|string
{
    $schema = gemini_setlist_schema();
    $marks = $mcs !== null;
    // shortcut: 曲数を伝えたときの精度は試していない（無い曲を作らないかを10本ほどで試す）
    $more = $count > 0 ? ($part === null ? "
- タイムテーブルでは{$count}曲。挙げた曲がそれより少なければ、聴き落とした曲がないか確かめる。ただし聴き取れない曲を作らない" : "
- タイムテーブルでは{$part[1]}本の動画を合わせて{$count}曲（この動画だけの曲数ではない）。聴き取れない曲を作らない") : '';
    $more .= $part === null ? '' : gemini_setlist_part_prompt($part[0], $part[1]);
    if ($marks) {
        $more .= "
- end には曲の最後の音が消えた時刻を「分:秒」で答える。拍手・チューニングは曲に含めない。途切れずに次の曲へつながるときは空にする
- mcs には、出演者がマイクで話し始めた時刻（MC の始まり）を「分:秒」ですべて挙げる。演奏中の掛け声・煽りは含めない";
        $schema['properties']['songs']['items']['properties']['end'] = ['type' => 'STRING'];
        $schema['properties']['mcs'] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
        $schema['required'][] = 'mcs';
    }
    $artist = trim((string)$artist);
    $titles = $artist !== '' ? itunes_artist_titles($artist) : [];
    // $flex: Flex（半額）で聴かせる。返事まで数分かかるので、api_setlist_detect は画面に先に返してから裏で待つ
    $prompt = ($titles ? gemini_setlist_list_prompt($artist, $titles) . GEMINI_SETLIST_PHRASES_PROMPT : GEMINI_SETLIST_PROMPT) . $more;
    if ($titles) {
        $schema['properties']['songs']['items']['properties']['phrases'] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
    }
    $json = gemini_audio_json($path, $mime, $cfg['key'], $cfg['model'], $prompt, $schema, GEMINI_SETLIST_THINKING, $flex);
    if (is_string($json)) {
        return $json;
    }
    $songs = gemini_setlist_songs($json);
    if ($marks) {
        $mcs = array_values(array_filter(array_map(static fn($t) => is_string($t) ? gemini_chapter_ms($t) : null, (array)($json['mcs'] ?? [])), 'is_int'));
        $mcs = array_map(static fn($ms) => intdiv($ms, 1000), $mcs);
    }
    if ($titles) { // 一覧を渡したら、2回目はしない（試験で2回目と同じ以上に当たった）
        // 歌詞の一節は確かめるのに使うだけ。返す曲（途中経過のファイル・セッションに残る）には入れない
        $phrases = array_map(static fn($s) => $s['phrases'] ?? [], array_values(array_filter((array)($json['songs'] ?? []), 'is_array')));
        return gemini_setlist_check($songs, $phrases, $path, $artist, $cfg, $flex);
    }
    if ($artist !== '' && gemini_setlist_has_unknown($songs)) {
        $again = gemini_audio_json($path, $mime, $cfg['key'], $cfg['model'], gemini_setlist_hint_prompt($artist) . $more, $schema, GEMINI_SETLIST_THINKING, $flex);
        if (is_array($again)) { // 2回目が失敗したら、1回目の結果だけ返す
            $songs = gemini_setlist_fill($songs, gemini_setlist_songs($again));
        }
    }
    return $songs;
}

/**
 * 1つのライブを何本かの動画に分けたときに足す指示（{$k}本目 / 全{$n}本）
 *   shortcut: 動画の境目で切れた曲を2回数えないかは試していない（分かれた動画のバンドで数本試す）
 */
function gemini_setlist_part_prompt(int $k, int $n): string
{
    return "
- この音声は、1つのバンドのライブ演奏を{$n}本の動画に分けたうちの{$k}本目。答えるのはこの動画の中で演奏された曲だけ。start はこの動画の頭からの時刻"
        . ($k > 1 ? "
- 冒頭が前の動画の最後の曲の続き（曲の途中から始まる）なら、その曲は答えない" : '')
        . ($k < $n ? "
- 最後の曲が途中で切れていても、その曲がこの動画の中で始まっていれば答える" : '');
}

/** 一覧から選ばせるときに足す指示。曲ごとに聞き取れた歌詞の一節を返させ、gemini_setlist_lyrics で曲名を確かめる */
const GEMINI_SETLIST_PHRASES_PROMPT = '
- phrases には、その曲ではっきり聞き取れた歌詞の一節を3つまで（各20文字以内）、聞こえたとおりに書く。聞き取れなければ空にする。推測で補わない';

/** 聴き比べで聴かせるライブの長さ（秒。曲の始まりから）。2026-11 の試験で4分より13曲中2曲悪かったが、料金を優先して2分 */
const GEMINI_SETLIST_COMPARE_SEC = 120;

/** 歌詞の一節から曲名を調べるモデル（Google 検索つき。api_lyrics_search と同じ） */
const GEMINI_SETLIST_LYRICS_MODEL = 'gemini-3.8-flash';

/** 曲名を比べるときの形（全角半角・大文字小文字・記号・空白の違いを無視する） */
function gemini_setlist_norm(string $t): string
{
    return (string)preg_replace('/[\s\p{P}\p{S}]/u', '', mb_strtolower(mb_convert_kana($t, 'asKV')));
}

/**
 * ①（一覧から選ばせた結果）を曲ごとに確かめる。2026-11 に10バンド37曲で試し、自信ありの外れが7曲 → 1曲になった
 *   1. 聞き取れた歌詞の一節を Google 検索で曲名にする（gemini_setlist_lyrics）。①が自信ありで1位と同じなら確定
 *   2. 違えば、候補（①・歌詞の上位・①の候補）のうち試聴がある3曲とライブを聴き比べる（gemini_setlist_compare）。合えばその曲で確定
 *   3. どれでもない・候補が1曲以下・失敗したら、自信なしにして候補を並べる（確認画面で人が選ぶ）
 * @param array $phrases 曲ごとの歌詞の一節（$songs と同じ順）。ここで使うだけで、返さない
 */
function gemini_setlist_check(array $songs, array $phrases, string $path, string $artist, array $cfg, bool $flex): array
{
    $previews = []; // 曲名の形 => [曲名, 試聴の URL]
    foreach (itunes_artist_tracks($artist) as $t => $url) {
        if ($url !== '') {
            $previews[gemini_setlist_norm((string)$t)] ??= [(string)$t, $url];
        }
    }
    foreach ($songs as $i => $s) {
        $phr = [];
        foreach ((array)($phrases[$i] ?? []) as $p) {
            if (is_string($p) && trim($p) !== '' && count($phr) < 3) {
                $phr[] = mb_substr(trim($p), 0, 30);
            }
        }
        $lyr = $phr ? gemini_setlist_lyrics($phr, $artist, $cfg['key']) : [];
        if (gemini_setlist_sure($s) && gemini_setlist_norm($s['title']) === gemini_setlist_norm($lyr[0] ?? '')) {
            continue; // 聴き比べなくても、①と歌詞の1位が同じ
        }
        $pool = array_merge([$s['title']], array_slice($lyr, 0, 2), array_column($s['candidates'], 'title'), array_slice($lyr, 2));
        $cset = [];
        foreach ($pool as $t) {
            if (isset($previews[$k = gemini_setlist_norm($t)])) {
                $cset[$k] = $previews[$k];
            }
        }
        $cset = array_values(array_slice($cset, 0, 3));
        $match = count($cset) >= 2 && $s['start'] !== null // 1曲だけでは比べたことにならない
            ? gemini_setlist_compare($path, $s['start'], $songs[$i + 1]['start'] ?? null, array_column($cset, 1), $cfg, $flex) : null;
        if ($match !== null) {
            $songs[$i] = ['title' => $cset[$match][0], 'candidates' => [], 'artist' => $artist] + $s;
            continue;
        }
        $cands = [];
        foreach (array_merge([$s['title']], array_column($cset, 0), $lyr, array_column($s['candidates'], 'title')) as $t) {
            if (($k = gemini_setlist_norm($t)) !== '') {
                $cands[$k] ??= ['title' => $t, 'artist' => $artist];
            }
        }
        $songs[$i]['candidates'] = array_slice(array_values($cands), 0, GEMINI_SETLIST_MAX_CANDIDATES);
    }
    return $songs;
}

/**
 * 歌詞の一節（聞き取りなので誤りを含む）を Google 検索つきの Flash で曲名にする。一節は送るだけで、保存も表示もしない
 * @return string[] 曲名（可能性の高い順、5曲まで）。失敗したら []
 */
function gemini_setlist_lyrics(array $phrases, string $artist, string $key): array
{
    $req = [
        'contents' => [['role' => 'user', 'parts' => [['text' => "歌詞の一節から曲を推定し、可能性の高い順に5件、JSON {\"candidates\":[{\"title\":\"\",\"artist\":\"\"}]} だけを返す。歌詞は回答に含めない。アーティストは「{$artist}」の可能性が高い。\n歌詞の一節（聞き取りなので誤りを含む）: " . implode(' / ', $phrases)]]]],
        'generationConfig' => ['temperature' => 0.1],
        'tools' => [['google_search' => new stdClass()]], // 検索と JSON の形の指定は一緒に使えないので、文で頼む
    ];
    $url = GEMINI_API . '/v1beta/models/' . GEMINI_SETLIST_LYRICS_MODEL . ':generateContent';
    $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($req, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json']];
    $res = gemini_call($url, $key, $opts, 60);
    if (($res[0] ?? 0) !== 200) {
        $res = gemini_call($url, $key, $opts, 60); // 1回だけやり直す
    }
    $text = '';
    foreach (json_decode($res[2] ?? '', true)['candidates'][0]['content']['parts'] ?? [] as $p) {
        $text .= is_array($p) ? (string)($p['text'] ?? '') : '';
    }
    $json = preg_match('/\{.*\}/s', $text, $m) ? json_decode($m[0], true) : null;
    $titles = [];
    foreach ((array)($json['candidates'] ?? []) as $c) {
        if (is_array($c) && is_string($c['title'] ?? null) && trim($c['title']) !== '' && count($titles) < 5) {
            $titles[] = mb_substr(trim($c['title']), 0, 100);
        }
    }
    return $titles;
}

/**
 * ライブの1曲（$from 秒から GEMINI_SETLIST_COMPARE_SEC 秒）と、原曲の試聴（iTunes の30秒）を聴き比べさせる
 * @param ?int $to 次の曲の始まり（秒。最後の曲なら null）
 * @param string[] $urls 試聴の URL（2〜3曲）
 * @return ?int 合った試聴の番号（0 始まり）。どれでもない・失敗したら null
 */
function gemini_setlist_compare(string $path, int $from, ?int $to, array $urls, array $cfg, bool $flex): ?int
{
    $base = sys_get_temp_dir() . '/abbey-cmp-' . bin2hex(random_bytes(6));
    $len = max(30, min(GEMINI_SETLIST_COMPARE_SEC, ($to ?? PHP_INT_MAX) - $from));
    $files = [];
    try {
        $cut = audio_cut_mp3($path, $from, $len);
        if ($cut === null || !rename($cut, "$base.live.mp3") || is_string($live = gemini_upload("$base.live.mp3", 'audio/mpeg', $cfg['key']))) {
            $cut && @unlink($cut);
            return null;
        }
        $files[] = $live;
        $parts = [['text' => '1つ目の音声は、学生バンドがライブでカバー演奏した1曲（前後の曲や MC が少し入ることがある）。'], ['file_data' => ['mime_type' => 'audio/mpeg', 'file_uri' => $live['uri']]]];
        foreach (array_values($urls) as $k => $url) {
            if (!preg_match('#^https://[a-z0-9.-]+\.apple\.com/#i', $url)) { // iTunes の試聴以外は取りに行かない
                return null;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($ch);
            $ok = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 && is_string($body) && $body !== '';
            curl_close($ch);
            if (!$ok || file_put_contents("$base.p$k.m4a", $body) === false || is_string($f = gemini_upload("$base.p$k.m4a", 'audio/aac', $cfg['key']))) {
                return null;
            }
            $files[] = $f;
            $parts[] = ['text' => '原曲の試聴 ' . ($k + 1) . '（曲の一部30秒）:'];
            $parts[] = ['file_data' => ['mime_type' => 'audio/aac', 'file_uri' => $f['uri']]];
        }
        $parts[] = ['text' => 'ライブの演奏と同じ曲の原曲を、メロディ・コード進行・リズム・曲の構成・歌詞を聴き比べて選ぶ。match に試聴の番号（1〜' . count($urls) . '）、どれでもなければ 0。曲名や先入観ではなく、音で比べること。'];
        $schema = ['type' => 'OBJECT', 'properties' => ['match' => ['type' => 'INTEGER'], 'reason' => ['type' => 'STRING']], 'required' => ['match', 'reason']];
        $json = gemini_json($parts, $cfg['key'], $cfg['model'], $schema, GEMINI_SETLIST_THINKING, $flex);
        $match = is_array($json) ? (int)($json['match'] ?? 0) : 0;
        return $match >= 1 && $match <= count($urls) ? $match - 1 : null;
    } finally {
        foreach ($files as $f) {
            gemini_delete($f, $cfg['key']);
        }
        foreach (glob("$base.*") ?: [] as $f) {
            @unlink($f);
        }
    }
}

/** 1曲だけ検出し直すとき（確認画面の ↻）。区間だけ切り出した音声（audio_cut_mp3）を聴かせる */
const GEMINI_SETLIST_ONE_PROMPT = <<<'TXT'
添付は学生バンドのライブ音源の一部で、既存の曲のカバー演奏が1曲入っています。前後の曲や MC が少し入っていることがあります。
この曲の原曲として可能性のある曲を10曲、可能性の高い順に挙げてください。

- 歌詞・メロディ・曲調から原曲を特定し、原曲の正式な曲名とアーティスト名を答える
- 特定できなくても、歌詞・曲調・アレンジが近い曲を挙げる
TXT;

/** SE（演奏ではなく、会場で流す音源）を ↻ で検出し直すとき */
const GEMINI_SETLIST_ONE_SE_PROMPT = <<<'TXT'
添付はライブ会場で流れた SE（既存の曲の音源。バンドの演奏ではない）の一部です。前後に MC や演奏が少し入っていることがあります。
この SE の曲として可能性のある曲を10曲、可能性の高い順に挙げてください。

- 歌詞・メロディ・曲調から曲を特定し、正式な曲名とアーティスト名を答える
- 特定できなくても、歌詞・曲調が近い曲を挙げる
TXT;

/** Gemini の返事（1曲だけ） → 候補 [{title, artist}]（可能性の高い順、10曲まで。↻ は音声の料金が同じなので多めに出す） */
function gemini_setlist_one_candidates(array $json): array
{
    $one = gemini_setlist_songs(['songs' => [['title' => '', 'confident' => false, 'candidates' => $json['candidates'] ?? null]]], 10);
    return $one[0]['candidates'];
}

/** 曲の始まりがわからず、前の曲から聴かせるとき（区間に2曲以上入る）に足す指示 */
const GEMINI_SETLIST_TAIL_PROMPT = '
- ただし、この音源には曲が2曲以上入っている。答えるのは最後に演奏される曲だけ';

/**
 * @param bool $tail 区間の最後の曲を答えさせる（GEMINI_SETLIST_TAIL_PROMPT）
 * @param string[] $exclude 候補に出さない曲名（↻ を押すたびに、もう出た候補から外す。MBV「thorn」は何度聴かせても候補に出なかった）
 * @param bool $compare 候補を試聴と聴き比べて並べ直す（↻ の1回目と「出た候補を戻す」の後だけ。1回 約3円増えるので）。区間の頭が別の曲になる $tail では使わない
 * @param bool $se SE（GEMINI_SETLIST_ONE_SE_PROMPT）。コピー元の曲とは限らないので $artist は渡さない
 * @return array|string 候補 [{title, artist}]。失敗したら画面に出せる理由
 */
function gemini_setlist_one(string $path, string $mime, array $cfg, bool $tail = false, ?string $artist = null, array $exclude = [], bool $compare = false, bool $se = false): array|string
{
    $ex = [];
    foreach ($exclude as $t) {
        $ex[gemini_setlist_norm($t)] = $t;
    }
    unset($ex['']);
    $keep = static fn(string $t): bool => !isset($ex[gemini_setlist_norm($t)]);
    // コピー元がわかれば、候補の上位3曲はその曲から選ばせる（伝えないと別のアーティストの曲ばかり挙がった）
    $artist = trim((string)$artist);
    $titles = array_values(array_filter($artist !== '' ? itunes_artist_titles($artist) : [], $keep));
    $hint = $artist === '' ? '' : ($titles
        ? "\n- このバンドは「{$artist}」のコピーバンド。上位3曲は下の一覧から選ぶ\n\n【{$artist} の曲一覧】\n" . implode("\n", $titles)
        : "\n- このバンドは「{$artist}」のコピーバンド。上位3曲は {$artist} の曲にする");
    $pair = ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING'], 'artist' => ['type' => 'STRING']], 'required' => ['title', 'artist']];
    $schema = ['type' => 'OBJECT', 'properties' => ['candidates' => ['type' => 'ARRAY', 'items' => $pair]], 'required' => ['candidates']];
    $not = $ex ? "\n- 次の曲ではないとわかっている。挙げない: " . implode(' / ', $ex) : '';
    $json = gemini_audio_json($path, $mime, $cfg['key'], $cfg['model'], ($se ? GEMINI_SETLIST_ONE_SE_PROMPT : GEMINI_SETLIST_ONE_PROMPT) . ($tail ? GEMINI_SETLIST_TAIL_PROMPT : '') . $not . $hint, $schema, GEMINI_SETLIST_THINKING);
    if (is_string($json)) {
        return $json;
    }
    // 言っても挙げることがあるので、10曲に絞る前に外す
    $json['candidates'] = array_values(array_filter((array)($json['candidates'] ?? []), static fn($c) => !is_array($c) || $keep((string)($c['title'] ?? ''))));
    $cands = gemini_setlist_one_candidates($json);
    if (!$compare || $artist === '') {
        return $cands;
    }
    // 試聴がある上位3曲とライブ（区間の頭から）を聴き比べ、合った曲を1番目にする。比べられない・どれでもなければそのまま
    $previews = [];
    foreach (itunes_artist_tracks($artist) as $t => $url) {
        if ($url !== '') {
            $previews[gemini_setlist_norm((string)$t)] ??= $url;
        }
    }
    $idx = array_slice(array_keys(array_filter($cands, static fn($c) => isset($previews[gemini_setlist_norm($c['title'])]))), 0, 3);
    $match = count($idx) >= 2 ? gemini_setlist_compare($path, 0, null, array_map(static fn($i) => $previews[gemini_setlist_norm($cands[$i]['title'])], $idx), $cfg, false) : null;
    if ($match !== null) {
        $hit = $cands[$idx[$match]];
        unset($cands[$idx[$match]]);
        array_unshift($cands, $hit);
    }
    return array_values($cands);
}

/*
 * 全体の検出の途中経過（Flex は返事まで数分かかるので、api_setlist_detect は画面に先に返して裏で待つ）
 *   バンドごとに1ファイル（公開フォルダの外 .abbey-ytdlp）。{state: running|done|error, at: 始めた時刻, songs|error}
 *   画面（assets/setlist_detect.js）が数秒ごとに見に来て、done なら確認画面へ。画面を閉じても裏の処理は続く
 */
require_once __DIR__ . '/ytdlp.php'; // ytdlp_dir

/** 裏で待つ上限。これを過ぎても running のままなら、裏の処理が止まったとみなす（Flex は最大 10 分 × 2 回 + やり直しの待ち） */
const SETLIST_JOB_STALE = 3600; // Flex のやり直し（待つのは合わせて約17分）＋最後の1回（最大11分）が収まる長さ

function setlist_job_path(int $bandId): string
{
    return ytdlp_dir() . '/detect-' . $bandId . '.json';
}

/** 途中経過。無ければ null。止まった running は error にして返す */
function setlist_job_get(int $bandId): ?array
{
    $job = json_decode((string)@file_get_contents(setlist_job_path($bandId)), true);
    if (!is_array($job)) {
        return null;
    }
    if (($job['state'] ?? '') === 'running' && time() - (int)($job['at'] ?? 0) > SETLIST_JOB_STALE) {
        return ['state' => 'error', 'error' => '解析が止まりました。もう一度試してください'];
    }
    return $job;
}

/** 今、裏で検出しているバンドの数（止まったものは数えない） */
function setlist_job_running_count(): int
{
    $n = 0;
    foreach (glob(ytdlp_dir() . '/detect-*.json') ?: [] as $file) {
        if (preg_match('/detect-(\d+)\.json$/', $file, $m) && (setlist_job_get((int)$m[1])['state'] ?? '') === 'running') {
            $n++;
        }
    }
    return $n;
}

/** 検出の結果を途中経過の形に。曲名が1つも無ければ失敗（確認画面を開いても意味がない） */
function setlist_detect_result(array $songs): array
{
    return array_filter(array_column($songs, 'title'), 'strlen') ? ['state' => 'done', 'songs' => $songs] : ['state' => 'error', 'error' => '曲が見つかりませんでした'];
}

/**
 * バンドの動画ごとの MP3 を1本ずつ順に聴かせてつなぐ（api_setlist_detect.php・api_setlist_bulk.php）。2本以上のときだけ「何本目か」を伝える
 *   $band: artist・song_count。$mp3s: 何本目 => 音源キー（band_video_mp3）
 * @return array 途中経過（setlist_job_put に渡す形。at・flex は呼ぶ側で足す）
 */
function setlist_detect_videos(int $bandId, array $band, array $mp3s, int $videos, array $cfg, bool $flex): array
{
    require_once __DIR__ . '/chapter_job.php'; // band_mix_file
    $songs = [];
    foreach ($mp3s as $no => $key) {
        $file = band_mix_file($bandId, $key); // 元のファイル（消さない）
        if (is_string($file)) {
            return ['state' => 'error', 'error' => $file];
        }
        $part = gemini_setlist($file[0], $file[1], $cfg, $band['artist'], $flex, isset($band['song_count']) ? (int)$band['song_count'] : null,
            $videos > 1 ? [$no + 1, $videos] : null);
        if (is_string($part)) {
            return ['state' => 'error', 'error' => $part, 'detail' => gemini_why()];
        }
        foreach ($part as $s) {
            $songs[] = $s + ['video' => $no];
        }
    }
    return setlist_detect_result($songs);
}

function setlist_job_put(int $bandId, ?array $job): void
{
    if ($job === null) {
        @unlink(setlist_job_path($bandId));
        return;
    }
    if (!is_dir(ytdlp_dir())) {
        @mkdir(ytdlp_dir(), 0700, true);
    }
    file_put_contents(setlist_job_path($bandId), json_encode($job, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

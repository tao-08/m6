<?php
/**
 * lib/gemini_setlist.php — ライブ音源を Gemini に聴かせて、演奏した曲名を演奏順に取り出す（動画から曲を検出）
 *   セットリスト編集の「YouTube から曲を検出」で、api_setlist_detect.php がバンドの YouTube から落とした音声（lib/ytdlp.php）を渡す。
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
function gemini_setlist_songs(array $json): array
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
                if ($t !== '' && count($cands) < GEMINI_SETLIST_MAX_CANDIDATES) {
                    $cands[] = ['title' => $t, 'artist' => $clean($c['artist'] ?? '', 100)];
                }
            }
        }
        $title = $clean($s['title'] ?? '', 100);
        $ms = is_string($s['start'] ?? null) ? gemini_chapter_ms($s['start']) : null;
        $songs[] = ['title' => $title !== '' ? $title : ($cands[0]['title'] ?? ''), 'start' => $ms === null ? null : intdiv($ms, 1000), 'candidates' => $cands];
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
- このバンドは「{$artist}」のコピーバンドとして登録されている。{$artist} の曲を中心に考える";
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
 * @param ?string $artist コピー元のアーティスト（オムニバス・不明なら null）。iTunes に曲があれば一覧から選ばせる（1回）。
 *   無ければ一覧なしで聴かせ、わからない曲が残ったらアーティストだけ伝えてもう一度聴かせる
 * @return array|string gemini_setlist_songs の形。失敗したら画面に出せる理由
 */
function gemini_setlist(string $path, string $mime, array $cfg, ?string $artist = null): array|string
{
    $schema = gemini_setlist_schema();
    $artist = trim((string)$artist);
    $titles = $artist !== '' ? itunes_artist_titles($artist) : [];
    // Flex（半額）で聴かせる。返事まで数分かかるので、api_setlist_detect は画面に先に返してから裏で待つ
    $prompt = $titles ? gemini_setlist_list_prompt($artist, $titles) : GEMINI_SETLIST_PROMPT;
    $json = gemini_audio_json($path, $mime, $cfg['key'], $cfg['model'], $prompt, $schema, GEMINI_SETLIST_THINKING, true);
    if (is_string($json)) {
        return $json;
    }
    $songs = gemini_setlist_songs($json);
    if ($titles) {
        return $songs; // 一覧を渡したら、2回目はしない（試験で2回目と同じ以上に当たった）
    }
    if ($artist !== '' && gemini_setlist_has_unknown($songs)) {
        $again = gemini_audio_json($path, $mime, $cfg['key'], $cfg['model'], gemini_setlist_hint_prompt($artist), $schema, GEMINI_SETLIST_THINKING, true);
        if (is_array($again)) { // 2回目が失敗したら、1回目の結果だけ返す
            $songs = gemini_setlist_fill($songs, gemini_setlist_songs($again));
        }
    }
    return $songs;
}

/** 1曲だけ検出し直すとき（確認画面の ↻）。区間だけ落とした音声を聴かせる */
const GEMINI_SETLIST_ONE_PROMPT = <<<'TXT'
添付は学生バンドのライブ音源の一部で、既存の曲のカバー演奏が1曲入っています。前後の曲や MC が少し入っていることがあります。
この曲の原曲として可能性のある曲を5曲、可能性の高い順に挙げてください。

- 歌詞・メロディ・曲調から原曲を特定し、原曲の正式な曲名とアーティスト名を答える
- 特定できなくても、歌詞・曲調・アレンジが近い曲を挙げる
TXT;

/** Gemini の返事（1曲だけ） → 候補 [{title, artist}]（可能性の高い順、5曲まで） */
function gemini_setlist_one_candidates(array $json): array
{
    $one = gemini_setlist_songs(['songs' => [['title' => '', 'confident' => false, 'candidates' => $json['candidates'] ?? null]]]);
    return $one[0]['candidates'];
}

/** 曲の始まりがわからず、前の曲から聴かせるとき（区間に2曲以上入る）に足す指示 */
const GEMINI_SETLIST_TAIL_PROMPT = '
- ただし、この音源には曲が2曲以上入っている。答えるのは最後に演奏される曲だけ';

/**
 * @param bool $tail 区間の最後の曲を答えさせる（GEMINI_SETLIST_TAIL_PROMPT）
 * @return array|string 候補 [{title, artist}]。失敗したら画面に出せる理由
 */
function gemini_setlist_one(string $path, string $mime, array $cfg, bool $tail = false): array|string
{
    $pair = ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING'], 'artist' => ['type' => 'STRING']], 'required' => ['title', 'artist']];
    $schema = ['type' => 'OBJECT', 'properties' => ['candidates' => ['type' => 'ARRAY', 'items' => $pair]], 'required' => ['candidates']];
    $json = gemini_audio_json($path, $mime, $cfg['key'], $cfg['model'], GEMINI_SETLIST_ONE_PROMPT . ($tail ? GEMINI_SETLIST_TAIL_PROMPT : ''), $schema, GEMINI_SETLIST_THINKING);
    return is_string($json) ? $json : gemini_setlist_one_candidates($json);
}

/*
 * 全体の検出の途中経過（Flex は返事まで数分かかるので、api_setlist_detect は画面に先に返して裏で待つ）
 *   バンドごとに1ファイル（公開フォルダの外 .abbey-ytdlp）。{state: running|done|error, at: 始めた時刻, songs|error}
 *   画面（assets/setlist_detect.js）が数秒ごとに見に来て、done なら確認画面へ。画面を閉じても裏の処理は続く
 */
require_once __DIR__ . '/ytdlp.php'; // ytdlp_dir

/** 裏で待つ上限。これを過ぎても running のままなら、裏の処理が止まったとみなす（Flex は最大 10 分 × 2 回 + やり直しの待ち） */
const SETLIST_JOB_STALE = 1800;

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

<?php
/**
 * lib/gemini_setlist.php — ライブ音源を Gemini に聴かせて、演奏した曲名を演奏順に取り出す（動画から曲を検出）
 *   バンド編集で選んだ動画から、ブラウザ（assets/setlist_detect.js）が音声だけ抜いた MP3 を api_setlist_detect.php が渡す。
 *   結果はセトリの確認画面（songs_edit.php?from_detect=1）に並べるだけで、保存は人が確かめてから
 */
declare(strict_types=1);

require_once __DIR__ . '/gemini_chapters.php'; // gemini_audio_json（音源を上げて聴かせる）

const GEMINI_SETLIST_PROMPT = <<<'TXT'
添付は学生バンドのライブ音源で、既存の曲のカバー演奏です。演奏された曲を、演奏した順にすべて挙げてください。

- 歌詞・メロディ・曲調から原曲を特定し、原曲の正式な曲名とアーティスト名を答える
- MC で曲名が言われたら、それも手がかりにする
- 曲を特定できる自信があれば confident を true、自信がなければ false にする
- confident が false の曲は、可能性のある曲を candidates に最大3曲、可能性の高い順に挙げ、title には最も可能性の高い曲を入れる。候補も思い当たらなければ title を空にする
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

/** 自信が低い曲に付ける候補の数 */
const GEMINI_SETLIST_MAX_CANDIDATES = 3;

/**
 * Gemini の返事 → 曲（演奏順）。曲名も候補も無い曲は除く
 *   自信が低い曲は candidates（[{title, artist}]、可能性の高い順）を付ける。曲名が空なら第1候補を曲名にする
 * @return array<int, array{title: string, candidates: array<int, array{title: string, artist: string}>}>
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
        if ($title === '') {
            $title = $cands[0]['title'] ?? '';
        }
        if ($title !== '') {
            $songs[] = ['title' => $title, 'candidates' => $cands];
        }
    }
    return $songs;
}

/** @return array|string gemini_setlist_songs の形。失敗したら画面に出せる理由 */
function gemini_setlist(string $path, array $cfg): array|string
{
    $pair = ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING'], 'artist' => ['type' => 'STRING']], 'required' => ['title', 'artist']];
    $json = gemini_audio_json($path, 'audio/mpeg', $cfg['key'], $cfg['model'], GEMINI_SETLIST_PROMPT, [
        'type' => 'OBJECT',
        'properties' => ['songs' => ['type' => 'ARRAY', 'items' => [
            'type' => 'OBJECT',
            'properties' => $pair['properties'] + [
                'confident' => ['type' => 'BOOLEAN'],
                'candidates' => ['type' => 'ARRAY', 'items' => $pair],
            ],
            'required' => ['title', 'artist', 'confident'],
        ]]],
        'required' => ['songs'],
    ]);
    return is_string($json) ? $json : gemini_setlist_songs($json);
}

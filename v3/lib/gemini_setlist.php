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
- 特定できない曲は title を空にする。推測で埋めない
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

/** Gemini の返事 → 曲名（演奏順）。特定できなかった曲は除く */
function gemini_setlist_titles(array $json): array
{
    $titles = [];
    foreach (is_array($json['songs'] ?? null) ? $json['songs'] : [] as $s) {
        $t = is_array($s) ? trim((string)($s['title'] ?? '')) : '';
        if ($t !== '') {
            $titles[] = mb_substr($t, 0, 100);
        }
    }
    return $titles;
}

/** @return string[]|string 曲名（演奏順）。失敗したら画面に出せる理由 */
function gemini_setlist(string $path, array $cfg): array|string
{
    $json = gemini_audio_json($path, 'audio/mpeg', $cfg['key'], $cfg['model'], GEMINI_SETLIST_PROMPT, [
        'type' => 'OBJECT',
        'properties' => ['songs' => ['type' => 'ARRAY', 'items' => [
            'type' => 'OBJECT',
            'properties' => ['title' => ['type' => 'STRING'], 'artist' => ['type' => 'STRING']],
            'required' => ['title', 'artist'],
        ]]],
        'required' => ['songs'],
    ]);
    return is_string($json) ? $json : gemini_setlist_titles($json);
}

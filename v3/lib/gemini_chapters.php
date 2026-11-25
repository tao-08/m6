<?php
/**
 * lib/gemini_chapters.php — ライブ音源を Gemini に聴かせて、曲の開始・MC の開始を取り出す（自動チャプター）
 *   音源は Files API に上げて、終わったら消す。前処理はしない（共有サーバーは ffmpeg が使えないため）
 *   結果は [['kind' => 'song'|'mc', 'ms' => int], ...]（時間順）。失敗したら文字列（画面に出せる理由）
 */
declare(strict_types=1);

const GEMINI_API = 'https://generativelanguage.googleapis.com';

const GEMINI_CHAPTER_PROMPT = <<<'TXT'
添付されたライブ音源を最初から最後まで聴き取り、「曲の開始（SONG_START）」と「MCの開始（MC_START）」のタイムスタンプ（MM:SS形式）を時系列順に抽出してください。

【判定ルール】
SONG_START（曲の開始）:
- ドラムのカウント（声やスティック音）が明確に聞こえる場合は、カウントが始まった瞬間をタイムスタンプとする。
- カウントが聞こえない場合は、推測せず楽器や歌の演奏（イントロ）が実際に始まった瞬間をタイムスタンプとする。

MC_START（MCの開始）:
- 演奏が完全に終わった後（または1曲目の演奏前）に、出演者がマイクを通して話し始めた瞬間をタイムスタンプとする。
- MCが存在しない場合（最初からいきなり曲が始まる場合や、MCを挟まず連続して次の曲が始まる場合）は、絶対に無理にMC_STARTを作らず、SONG_STARTのみを連続して出力すること。
- 演奏中の掛け声や煽り（「いくぞ！」「ありがとう！」など）はMC_STARTとして扱わないこと。

根拠の記載:
- trigger_clue には、その瞬間に聞こえた具体的な音（例：「スティックのカウント音」「ギターのアルペジオ開始」「『こんばんは』という話し声」など）を簡潔に記述すること。
TXT;

/**
 * curl で1回呼ぶ。返り値は [HTTP ステータス, ヘッダー（小文字の名前 => 値）, 本文]。つながらなければ null
 * @param array<int, mixed> $opts 追加の curl オプション（CURLOPT_HTTPHEADER は追加分だけ書く）
 */
function gemini_call(string $url, string $key, array $opts, int $timeout): ?array
{
    $headers = [];
    $extra = $opts[CURLOPT_HTTPHEADER] ?? [];
    unset($opts[CURLOPT_HTTPHEADER]);
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => array_merge(['x-goog-api-key: ' . $key], $extra),
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$n, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($n))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        error_log('Gemini chapters cURL failure: errno=' . curl_errno($ch));
        return null;
    }
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $headers, (string)$body];
}

/** "MM:SS"（または "H:MM:SS"）→ ミリ秒。読めなければ null */
function gemini_chapter_ms(string $t): ?int
{
    if (!preg_match('/^(?:(\d+):)?(\d{1,3}):(\d{2})$/', trim($t), $m)) {
        return null;
    }
    return (((int)$m[1] * 60 + (int)$m[2]) * 60 + (int)$m[3]) * 1000;
}

/** Gemini の返事（events の配列）→ チャプター（時間順。同じ時刻は1つ） */
function gemini_chapters_from_events(array $events): array
{
    $out = [];
    foreach ($events as $e) {
        $kind = ['SONG_START' => 'song', 'MC_START' => 'mc'][(string)($e['type'] ?? '')] ?? null;
        $ms = gemini_chapter_ms((string)($e['timestamp'] ?? ''));
        if ($kind !== null && $ms !== null) {
            $out[$ms] = ['kind' => $kind, 'ms' => $ms];
        }
    }
    ksort($out);
    return array_values($out);
}

/** @return array<int, array{kind: string, ms: int}>|string */
function gemini_audio_chapters(string $path, string $mime, string $key, string $model): array|string
{
    $json = gemini_audio_json($path, $mime, $key, $model, GEMINI_CHAPTER_PROMPT, ['type' => 'OBJECT', 'properties' => ['events' => ['type' => 'ARRAY', 'items' => [
        'type' => 'OBJECT',
        'properties' => [
            'type' => ['type' => 'STRING', 'enum' => ['SONG_START', 'MC_START']],
            'timestamp' => ['type' => 'STRING'],
            'trigger_clue' => ['type' => 'STRING'],
        ],
        'required' => ['type', 'timestamp', 'trigger_clue'],
    ]]], 'required' => ['events']]);
    if (is_string($json)) {
        return $json;
    }
    return is_array($json['events'] ?? null) ? gemini_chapters_from_events($json['events']) : 'Gemini API で解析できませんでした';
}

/**
 * 音源を Files API に上げて $prompt で聴かせ、$schema の形の JSON を返す（終わったら音源は消す）。失敗したら文字列（画面に出せる理由）
 *   自動チャプター（上）と、動画から曲を検出（lib/gemini_setlist.php）で使う
 *   $thinkingBudget: 考える量（トークン）の上限。null なら上限なし。考えた分は出力と同じ単価で請求される
 */
function gemini_audio_json(string $path, string $mime, string $key, string $model, string $prompt, array $schema, ?int $thinkingBudget = null): array|string
{
    $size = (int)filesize($path);
    $fail = 'Gemini API で解析できませんでした';
    $limit = 'Gemini API の利用上限に達しました。時間をおいて再試行してください';

    // 1. 上げる場所をもらう → 2. 上げる
    $start = gemini_call(GEMINI_API . '/upload/v1beta/files', $key, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{"file":{"display_name":"live"}}',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $size,
            'X-Goog-Upload-Header-Content-Type: ' . $mime,
        ],
    ], 30);
    $uploadUrl = $start[1]['x-goog-upload-url'] ?? '';
    if (!$start || $start[0] !== 200 || !str_starts_with($uploadUrl, GEMINI_API . '/')) {
        error_log('Gemini chapters: upload start HTTP ' . ($start[0] ?? 0));
        return ($start[0] ?? 0) === 429 ? $limit : $fail;
    }
    $fp = fopen($path, 'rb');
    $up = gemini_call($uploadUrl, $key, [
        CURLOPT_UPLOAD => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_INFILE => $fp,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_HTTPHEADER => ['X-Goog-Upload-Offset: 0', 'X-Goog-Upload-Command: upload, finalize', 'Expect:'],
    ], 280);
    fclose($fp);
    $file = json_decode($up[2] ?? '', true)['file'] ?? null;
    if (!$up || $up[0] !== 200 || !is_array($file) || !isset($file['name'], $file['uri'])) {
        error_log('Gemini chapters: upload HTTP ' . ($up[0] ?? 0));
        return $fail;
    }
    $result = $fail;
    try {
        // 3. 使える状態（ACTIVE）になるまで待つ
        for ($i = 0; $i < 30 && ($file['state'] ?? '') !== 'ACTIVE'; $i++) {
            if (($file['state'] ?? '') === 'FAILED') {
                return $fail;
            }
            sleep(2);
            $st = gemini_call(GEMINI_API . '/v1beta/' . $file['name'], $key, [], 20);
            $file = json_decode($st[2] ?? '', true) ?: $file;
        }
        // 4. 聴かせる
        $req = [
            'contents' => [['role' => 'user', 'parts' => [
                ['file_data' => ['mime_type' => $mime, 'file_uri' => $file['uri']]],
                ['text' => $prompt],
            ]]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ] + ($thinkingBudget === null ? [] : ['thinkingConfig' => ['thinkingBudget' => $thinkingBudget]]),
        ];
        $res = gemini_call(GEMINI_API . '/v1beta/models/' . rawurlencode($model) . ':generateContent', $key, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($req, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ], 280);
        if (!$res || $res[0] !== 200) {
            error_log('Gemini chapters: generate HTTP ' . ($res[0] ?? 0));
            return ($res[0] ?? 0) === 429 ? $limit : $fail;
        }
        $text = '';
        foreach (json_decode($res[2], true)['candidates'][0]['content']['parts'] ?? [] as $p) {
            $text .= (string)($p['text'] ?? '');
        }
        $json = json_decode($text, true);
        $result = is_array($json) ? $json : $fail;
    } finally {
        gemini_call(GEMINI_API . '/v1beta/' . $file['name'], $key, [CURLOPT_CUSTOMREQUEST => 'DELETE'], 20); // 預けた音源は消す
    }
    return $result;
}

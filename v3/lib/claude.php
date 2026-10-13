<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  claude.php — Claude（AI）の Messages API を呼ぶ共通の関数
 * =====================================================================
 *  画像の読み取り（lib/import/ai_reader.php）とアーティストのふりがな（api_artist_kana.php）で使う。
 *  composer（公式 SDK）が使えないレンタルサーバーでも動くように、cURL で直接 HTTP を送っている。
 *  返事は JSON スキーマで形を固定する（output_config.format）→ 必ずその形の JSON が返る。
 *
 *  ※ API キーは config.php の anthropic.api_key（パスワードと同じ。git に入れない）
 * =====================================================================
 */

/** AI が断ったとき（stop_reason = refusal）の例外コード。呼ぶ側で用途に合ったメッセージに変える */
const CLAUDE_ERR_REFUSAL = 1;
/** 返事が max_tokens で途中で切れたときの例外コード */
const CLAUDE_ERR_MAX_TOKENS = 2;

function claude_enabled(): bool
{
    return (string)(config('anthropic')['api_key'] ?? '') !== '';
}

/**
 * Claude に聞いて、スキーマどおりの JSON を連想配列で受け取る。
 * @param array $content  user メッセージの中身（text / image のブロックの配列）
 * @param array $schema   返事の形（JSON スキーマ）
 * @throws RuntimeException 失敗したとき（画面に出してよい日本語のメッセージ。code は CLAUDE_ERR_*）
 */
function claude_json(string $system, array $content, array $schema, int $maxTokens = 16000, int $timeoutSec = 180): array
{
    if (!claude_enabled()) {
        throw new RuntimeException('AI は設定されていません（config.php の anthropic.api_key）');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('サーバーで cURL が使えないため、AI を呼び出せません');
    }
    $cfg = config('anthropic') ?? [];
    $body = [
        'model'      => (string)($cfg['model'] ?? 'claude-opus-5-5'),
        'max_tokens' => $maxTokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $content]],
        'output_config' => [
            'effort' => 'medium',
            'format' => ['type' => 'json_schema', 'schema' => $schema],
        ],
    ];
    // AI が安全上の理由で断ったとき、自動で別のモデルに回してもらう
    // Haiku にはこの仕組みが無い（送るとエラーになる）ので、Haiku 以外のときだけ付ける
    $useFallback = !str_starts_with($body['model'], 'claude-haiku');
    if ($useFallback) {
        $body['fallbacks'] = 'default';
    }
    // 返事を待つ間に PHP の実行時間の上限（レンタルサーバーだと 30 秒など）で止められないようにする
    @set_time_limit($timeoutSec + 30);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . (string)$cfg['api_key'],
            'anthropic-version: 2023-06-01',
            ...($useFallback ? ['anthropic-beta: server-side-fallback-2026-07-01'] : []),
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) {
        throw new RuntimeException('AI に接続できませんでした（' . curl_error($ch) . '）。時間をおいて試してください');
    }
    $res = json_decode((string)$raw, true);
    if ($status !== 200 || !is_array($res)) {
        // エラーの中身（API キーの間違いなど）は管理者にしか意味がないので、画面にはざっくり出してログに残す
        error_log('Claude API error ' . $status . ': ' . substr((string)$raw, 0, 1000));
        $msg = match (true) {
            $status === 401 => 'AI の API キーが正しくありません（管理者に連絡してください）',
            $status === 429 => 'AI が混み合っているか、利用上限に達しました。時間をおいて試してください',
            $status >= 500  => 'AI 側で一時的なエラーが起きました。時間をおいて試してください',
            default         => "AI の呼び出しに失敗しました（{$status}）",
        };
        throw new RuntimeException($msg);
    }

    $stop = $res['stop_reason'] ?? '';
    if ($stop === 'refusal') {
        throw new RuntimeException('AI が回答を断りました', CLAUDE_ERR_REFUSAL);
    }
    if ($stop === 'max_tokens') {
        throw new RuntimeException('AI の返事が長すぎて途中で切れました', CLAUDE_ERR_MAX_TOKENS);
    }
    // content には思考（thinking）のブロックなども入るので、最後の text ブロックを答えとして使う
    $text = null;
    foreach ($res['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text = (string)$block['text'];
        }
    }
    $data = $text === null ? null : json_decode($text, true);
    if (!is_array($data)) {
        throw new RuntimeException('AI の返事を読み取れませんでした。もう一度試してください');
    }
    return $data;
}

/**
 * そのアーティスト名にどんなふりがなを付けるか。
 *   'none'     … ひらがな・カタカナだけの名前（ヨルシカ など）。読みはそのままなので付けない
 *   'katakana' … 英字が入っている名前（Oasis → オアシス）。漢字と日本語読みの数字の部分だけはひらがな、かなの部分はそのまま
 *                （ヤバイTシャツ屋さん → ヤバイティーシャツやさん、Official髭男dism → オフィシャルひげだんディズム）
 *   'hiragana' … それ以外（漢字が入っている名前。米津玄師 → よねづけんし）
 */
function artist_kana_style(string $name): string
{
    if (preg_match('/\p{Latin}/u', $name)) {
        return 'katakana';
    }
    // かな・長音と、記号・空白・数字（おたくフレンズ! など）だけなら付けない
    if (preg_match('/^[\p{Hiragana}\p{Katakana}ー\p{P}\p{S}\p{Z}\p{N}]+$/u', $name) && preg_match('/[\p{Hiragana}\p{Katakana}]/u', $name)) {
        return 'none';
    }
    return 'hiragana';
}

/**
 * アーティスト名のふりがなを AI に付けてもらう（英字の名前はカタカナ、それ以外はひらがな。かなだけの名前は送らない）。まとめて1回のリクエストで送る。
 * @param string[] $names アーティスト名
 * @return array<string, string> 名前 => ふりがな（AI が返さなかった名前は入らない）
 */
function claude_artist_kana(array $names): array
{
    $names = array_values(array_unique(array_filter($names, static fn($n) => is_string($n) && $n !== '' && artist_kana_style($n) !== 'none')));
    if (!$names) {
        return [];
    }
    $system = <<<'PROMPT'
あなたは日本の軽音サークルのデータベースで、アーティスト名（バンド名・歌手名）に「ふりがな」を付ける係です。
ふりがなは、サイトの一覧を名前順（あいうえお順）に並べることと、ひらがなで検索できるようにすることに使います。

- 英字（アルファベット）が入っている名前は、英字の部分をカタカナ、漢字の部分をひらがなにし、ひらがな・カタカナの部分は名前のとおりに書く
  （例: Oasis → オアシス、ヤバイTシャツ屋さん → ヤバイティーシャツやさん、Official髭男dism → オフィシャルひげだんディズム、銀杏BOYZ → ぎんなんボーイズ）
- 英字が入っていない名前は、全部ひらがなで書く（例: 米津玄師 → よねづけんし、緑黄色社会 → りょくおうしょくしゃかい）
- 伸ばす音は「ー」。記号・空白・句読点は入れない
- 公式の読みがあればそれを使う（例: [ALEXANDROS] → アレキサンドロス）
- 数字の部分はその名前での読み方にする。日本語読みならひらがな、英語読みならカタカナで書く（例: MONGOL800 → モンゴルはっぴゃく、171 → いちななーいち、10-FEET → テンフィート、AKB48 → エーケービーフォーティーエイト）
- 表記ゆれや書き間違いの名前も、正しいアーティストの読みにする（例: RADWINPS → ラッドウィンプス）
- 読みに自信がない名前は unsure を true にする
- 渡された名前は全部、name をそのまま（1文字も変えずに）返す
PROMPT;
    $schema = [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name'   => ['type' => 'string'],
                        'kana'   => ['type' => 'string'],
                        'unsure' => ['type' => 'boolean'],
                    ],
                    'required' => ['name', 'kana', 'unsure'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['items'],
        'additionalProperties' => false,
    ];
    $content = [['type' => 'text', 'text' => "次のアーティスト名にふりがなを付けてください。\n" . implode("\n", $names)]];
    try {
        $data = claude_json($system, $content, $schema, 16000, 120);
    } catch (RuntimeException $e) {
        if ($e->getCode() === CLAUDE_ERR_MAX_TOKENS) {
            throw new RuntimeException('一度に送った名前が多すぎました。少しずつ試してください');
        }
        throw $e;
    }
    $want = array_flip($names);
    $out = [];
    foreach ($data['items'] ?? [] as $it) {
        $name = (string)($it['name'] ?? '');
        // かな・長音だけにする（AI が記号を混ぜても並べ替えが崩れないように）
        $kana = preg_replace('/[^\p{Hiragana}\p{Katakana}ー]/u', '', (string)($it['kana'] ?? ''));
        if (artist_kana_style($name) === 'hiragana') {
            $kana = mb_convert_kana($kana, 'c');        // 英字が無い名前は全部ひらがな
        } elseif (!preg_match('/[\x{3041}-\x{309F}\x{3400}-\x{9FFF}0-9０-９]/u', $name)) { // ひらがな・漢字・数字の範囲（\p{Han} だと「・」まで当たる）
            $kana = mb_convert_kana($kana, 'C');        // 英字とカタカナだけの名前は全部カタカナ（漢字・ひらがな・数字が混ざる名前は AI の書き分けのまま）
        }
        if (isset($want[$name]) && $kana !== '' && mb_strlen($kana) <= 100) {
            $out[$name] = $kana;
        }
    }
    return $out;
}

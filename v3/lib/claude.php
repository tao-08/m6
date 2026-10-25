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

/**
 * 料金（ドル / 100万トークン。2026-10 時点の Anthropic の公式価格）。in = 入力、out = 出力、cache = キャッシュ読み込み
 *   ウェブ検索は1回 $0.01（1,000回 $10）。円は CLAUDE_YEN_PER_USD で換算（ざっくりの目安）
 */
const CLAUDE_PRICES = [
    'claude-opus-5-5'   => ['in' => 4.00, 'out' => 20.00, 'cache' => 0.20],
    'claude-sonnet-5-5' => ['in' => 2.00, 'out' => 10.00, 'cache' => 0.20],
    'claude-haiku-5-5'  => ['in' => 0.10, 'out' => 0.50,  'cache' => 0.01],
];
const CLAUDE_WEB_SEARCH_USD = 0.01;
const CLAUDE_YEN_PER_USD = 150;

/** 直前の claude_json() の使用量と実費（pause_turn で続けた分も合計）。まだ呼んでいなければ null */
function claude_last_usage(): ?array
{
    return $GLOBALS['claude_last_usage'] ?? null;
}

/** API の usage（1回分）から実費（ドル）を出す。値段の分からないモデルは Opus の値段で多めに見積もる */
function claude_usage_cost(string $model, array $u): float
{
    $p = CLAUDE_PRICES[$model] ?? CLAUDE_PRICES['claude-opus-5-5'];
    $in = (int)($u['input_tokens'] ?? 0);
    $write = (int)($u['cache_creation_input_tokens'] ?? 0); // キャッシュの書き込みは入力の 1.25 倍
    $read = (int)($u['cache_read_input_tokens'] ?? 0);
    $out = (int)($u['output_tokens'] ?? 0);
    $search = (int)($u['server_tool_use']['web_search_requests'] ?? 0);
    return ($in * $p['in'] + $write * $p['in'] * 1.25 + $read * $p['cache'] + $out * $p['out']) / 1e6 + $search * CLAUDE_WEB_SEARCH_USD;
}

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
function claude_json(string $system, array $content, array $schema, int $maxTokens = 16000, int $timeoutSec = 180, ?string $model = null, array $tools = []): array
{
    if (!claude_enabled()) {
        throw new RuntimeException('AI は設定されていません（config.php の anthropic.api_key）');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('サーバーで cURL が使えないため、AI を呼び出せません');
    }
    $cfg = config('anthropic') ?? [];
    $body = [
        'model'      => $model ?? (string)($cfg['model'] ?? 'claude-opus-5-5'), // $model = 用途ごとに変えたいとき（BPM の推定は軽いモデルで足りる）
        'max_tokens' => $maxTokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $content]],
        'output_config' => [
            'effort' => 'medium',
            'format' => ['type' => 'json_schema', 'schema' => $schema],
        ],
    ];
    // サーバー側のツール（ウェブ検索など）。検索は Anthropic のサーバーが実行して、結果を踏まえた返事が返ってくる
    if ($tools) {
        $body['tools'] = $tools;
    }
    // AI が安全上の理由で断ったとき、自動で別のモデルに回してもらう
    // Haiku にはこの仕組みが無い（送るとエラーになる）ので、Haiku 以外のときだけ付ける
    $useFallback = !str_starts_with($body['model'], 'claude-haiku');
    if ($useFallback) {
        $body['fallbacks'] = 'default';
    }
    // 返事を待つ間に PHP の実行時間の上限（レンタルサーバーだと 30 秒など）で止められないようにする
    @set_time_limit($timeoutSec + 30);
    $total = ['model' => $body['model'], 'input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'web_search_requests' => 0, 'usd' => 0.0];

    // pause_turn（サーバーツールの処理が長引いて一旦止まった）のときは、そこまでの返事を assistant として足して続きを頼む（最大3回）
    for ($round = 0; ; $round++) {
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
        // 使用量を足していく。断られて別のモデルに回ったときは、実際に答えたモデル（$res['model']）の値段で数える
        $u = is_array($res['usage'] ?? null) ? $res['usage'] : [];
        $served = (string)($res['model'] ?? $body['model']);
        $total['model'] = $served;
        $total['input_tokens'] += (int)($u['input_tokens'] ?? 0) + (int)($u['cache_creation_input_tokens'] ?? 0);
        $total['output_tokens'] += (int)($u['output_tokens'] ?? 0);
        $total['cache_read_input_tokens'] += (int)($u['cache_read_input_tokens'] ?? 0);
        $total['web_search_requests'] += (int)($u['server_tool_use']['web_search_requests'] ?? 0);
        $total['usd'] += claude_usage_cost($served, $u);
        $total['yen'] = round($total['usd'] * CLAUDE_YEN_PER_USD, 1);
        $GLOBALS['claude_last_usage'] = $total;
        if (($res['stop_reason'] ?? '') !== 'pause_turn' || $round >= 3) {
            break;
        }
        $body['messages'][] = ['role' => 'assistant', 'content' => $res['content'] ?? []];
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

<?php
/**
 * =====================================================================
 *  error_page.php — エラーページの表示
 * =====================================================================
 *  使う所
 *    ・error.php        … Apache が 404 / 403 / 500 などのときに呼ぶ（.htaccess の ErrorDocument）
 *    ・lib/bootstrap.php の error_page() … PHP の中で権限や存在をチェックして止めるとき
 *  bootstrap（DB・セッション）を読まなくても動くようにしてある。
 *  500 のときに DB が落ちていると bootstrap が読めないので、エラーページまで巻き込まれて真っ白にならないように。
 * =====================================================================
 */
declare(strict_types=1);

/**
 * エラーページを出して終わる。
 *   $code   : HTTP ステータス（404 など）
 *   $detail : 見出しに出す文言（空ならステータスごとの標準の文言）。「アルバムが見つかりません」など
 * 画面を開く人（Accept に text/html がある）にはページを、fetch() など JavaScript からの呼び出しには文字だけを返す
 */
function render_error_page(int $code, string $detail = ''): never
{
    $titles = [
        400 => '不正なリクエスト',
        403 => '権限がありません',
        404 => 'ページが見つかりません',
        405 => '不正なリクエスト',
        500 => 'サーバーエラー',
        502 => '一時的に利用できません',
        503 => '一時的に利用できません',
    ];
    $title = $titles[$code] ?? ($code >= 500 ? $titles[500] : $titles[400]);
    $headline = $detail !== '' ? $detail : $title;

    if (!headers_sent()) {
        http_response_code($code);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow');
    }

    if (!str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        exit($headline);
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> | AbbeyRoad.online</title>
    <!-- app.css は読まない: ページの置き場所（/ の下かどうか）に関係なく、これ1枚で完結させる -->
    <style>
        :root { --bg: #f6f5f1; --surface: #fff; --border: #e5e2da; --text: #1b1a1f; --muted: #6c6a75; --accent: #d90f0f; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0d0d11; --surface: #16161c; --border: #2b2b36; --text: #f1f0f5; --muted: #9b99a6; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text);
               font-family: "Noto Sans JP", "BIZ UDPGothic", "Hiragino Sans", "Yu Gothic", Meiryo, sans-serif; }
        .err { width: 100%; max-width: 420px; text-align: center; background: var(--surface); border: 1px solid var(--border);
               border-radius: 20px; padding: 36px 24px 32px; box-shadow: 0 8px 30px rgba(0, 0, 0, .06); }
        .err img { display: block; margin: 0 auto 24px; height: 40px; width: auto; }
        .err__code { margin: 0; font-size: 64px; font-weight: 900; line-height: 1; color: var(--accent); letter-spacing: .02em; }
        .err__title { margin: 14px 0 28px; font-size: 18px; font-weight: 700; line-height: 1.5; }
        .err a { display: inline-block; padding: 10px 28px; border-radius: 999px; background: var(--accent); color: #fff; font-weight: 700; text-decoration: none; }
        .err a:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }
    </style>
</head>
<body>
    <main class="err">
        <picture>
            <source srcset="/assets/logo-dark.png" media="(prefers-color-scheme: dark)">
            <img src="/assets/online.png" alt="AbbeyRoad.online" width="146" height="40">
        </picture>
        <p class="err__code"><?= (int)$code ?></p>
        <h1 class="err__title"><?= $e($headline) ?></h1>
        <a href="/">トップ</a>
    </main>
</body>
</html>
<?php
    exit;
}

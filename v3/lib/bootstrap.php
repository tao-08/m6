<?php
/**
 * =====================================================================
 *  bootstrap.php — 全ページの最初に require する「土台」
 * =====================================================================
 *  ここでやっていること
 *    1. 文字コード・タイムゾーンの設定
 *    2. config.php（DB接続情報など）の読み込み
 *    3. セッション開始（ログイン状態を覚えておく仕組み）
 *    4. DB接続を返す db()
 *    5. XSS対策の h()、CSRF対策の csrf_*()、ログイン判定の require_login() など
 *
 *  各ページはこう書くだけで準備完了になる:
 *      require __DIR__ . '/lib/bootstrap.php';
 *      $user = require_login();
 * =====================================================================
 */
declare(strict_types=1); // 型を厳しくチェックする（"1" と 1 をうっかり混ぜるバグを防ぐ）

mb_internal_encoding('UTF-8');          // mb_ 系関数の既定の文字コード
date_default_timezone_set('Asia/Tokyo'); // date() を日本時間にする

const APP_NAME = 'AbbeyRoad.online';
/** 本番の URL（最後は /）。SNS のカード（OGP）の画像は絶対 URL が必要なので使う。Host ヘッダーは信用しない（app_env() の説明と同じ理由） */
const APP_URL = 'https://abbeyroad.online/';
/** SNS のカードに出す説明文 */
const APP_DESCRIPTION = 'アビーロードのライブデータベース';

/**
 * いま動いている環境の名前（'local' か 'production'）を返す。
 *
 *   1. サーバーの環境変数 APP_ENV が 'local' / 'production' ならそれを使う
 *   2. 無ければ OS で決める: Windows（XAMPP）なら local、それ以外は production
 *
 * $_SERVER['HTTP_HOST'] は使わない。Host ヘッダーはブラウザが送ってくる値なので、
 * 本番に「Host: localhost」と送られると local 用の設定（debug ON など）に切り替わってしまう。
 * 迷ったときは安全側の production に倒す。
 */
function app_env(): string
{
    $env = getenv('APP_ENV');
    if ($env === 'local' || $env === 'production') {
        return $env;
    }
    return PHP_OS_FAMILY === 'Windows' ? 'local' : 'production';
}

/**
 * config.php の値を取り出す。
 *   config()          → 配列まるごと
 *   config('db')      → ['dsn' => ..., 'user' => ..., 'pass' => ...]
 *
 * config.php に 'local' / 'production' の塊があれば、
 * 'common' の値に「いまの環境の塊」を上書きしたものを使う。
 * （塊が無い昔の書き方の config.php もそのまま使える）
 *
 * static 変数を使って「最初の1回だけファイルを読む」ようにしている。
 * （static 変数は関数を抜けても値が残る）
 */
function config(?string $key = null): mixed
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/../config.php';
        if (!is_file($path)) {
            http_response_code(500);
            exit('config.php がありません。config.sample.php をコピーして作成してください。');
        }
        $raw = require $path; // config.php は return [...] しているので、その配列が入る
        if (isset($raw['local']) || isset($raw['production'])) {
            $env = app_env();
            if (!isset($raw[$env])) {
                http_response_code(500);
                exit("config.php に '{$env}' の設定がありません。");
            }
            // 'db' の中の 'pass' だけ上書き、のような入れ子の上書きもできるように _recursive を使う
            $config = array_replace_recursive($raw['common'] ?? [], $raw[$env]);
        } else {
            $config = $raw;
        }
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

// 本番でエラー内容を画面に出すと、DBのテーブル名などが見えてしまうので debug のときだけ表示
ini_set('display_errors', config('debug') ? '1' : '0');
error_reporting(E_ALL);

/* ---------------------------------------------------------------------
 * セッション開始
 *   httponly: JavaScript から Cookie を読めなくする（XSS されてもセッションを盗まれにくい）
 *   samesite: 他サイトからのリクエストに Cookie を付けにくくする（CSRF の軽減）
 *   secure  : https のときだけ Cookie を送る
 * ------------------------------------------------------------------- */
/*
 * ログインしたままでいられる日数（最後にサイトを開いてから）。
 *   これを書かないと PHP の初期設定のままで、Cookie は「ブラウザを閉じたら消える」、サーバー側は「24分さわらないと消えることがある」。
 *   スマホでブラウザのアプリをスワイプで消すだけでログアウトしてしまう。
 *   長くするほど楽だが、スマホをなくしたときに拾った人に見られる期間も長くなる
 */
const SESSION_DAYS = 30;

if (session_status() !== PHP_SESSION_ACTIVE) {
    // strict_mode: サーバーが発行していないセッションID（攻撃者が用意したもの）を受け付けない
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1'); // URL の ?PHPSESSID=… では受け付けない（URL ごと漏れると乗っ取られる）
    // サーバー側: SESSION_DAYS 日さわられなかったセッションだけ消す
    ini_set('session.gc_maxlifetime', (string)(SESSION_DAYS * 86400));
    // 保存場所をこのサイト専用にする。共用のフォルダのままだと、同じサーバーの別のサイト（もっと短い設定）の掃除で
    // こちらのセッションまで消されてしまう。storage/ は .htaccess で外から見えないようにしてある
    $sessionDir = __DIR__ . '/../storage/sessions';
    if (is_dir($sessionDir) || @mkdir($sessionDir, 0700, true)) {
        session_save_path($sessionDir);
        ini_set('session.gc_probability', '1'); // 専用の場所は誰も掃除してくれないので、PHP に掃除させる（1000回に1回くらい）
        ini_set('session.gc_divisor', '1000');
    }
    $cookie = [
        'lifetime' => SESSION_DAYS * 86400, // ブラウザを閉じても消えない
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ];
    session_set_cookie_params($cookie);
    session_start();
    // PHP は Cookie の期限を「作ったとき」にしか送らない → 開くたびに送り直して、期限を「最後に開いてから30日」にする
    if (!empty($_SESSION['user']) && !headers_sent()) {
        setcookie(session_name(), session_id(), ['expires' => time() + $cookie['lifetime']] + array_diff_key($cookie, ['lifetime' => 0]));
    }
}

/* ---------------------------------------------------------------------
 * セキュリティのヘッダー（全ページ共通。ブラウザに「こう守って」と伝える）
 *   nosniff           : 画像や CSV を HTML / JS と勘違いして実行させない
 *   frame-ancestors   : 他のサイトの <iframe> の中に表示させない（透明な枠を重ねてボタンを押させるクリックジャッキング対策）
 *   form-action 'self': フォームの送り先をこのサイトだけにする（XSS で偽のフォームを仕込まれても外へ送れない）
 *   Referrer-Policy   : 外のサイト（Spotify・YouTube など）へ移るとき、こちらの URL（member?id=… など）を渡さない。渡すのはドメインだけ
 *   X-Robots-Tag      : 検索エンジンに載せない（ログイン画面も含めて。サークル内向けのサイトなので）
 *   HSTS              : 一度 https で来たブラウザは、次から必ず https で来る（途中で http に書き換えられる攻撃を防ぐ）
 * ------------------------------------------------------------------- */
if (!headers_sent()) {
    header_remove('X-Powered-By'); // 「PHP/8.2.12」とバージョンを教えない（古いバージョンの弱点を狙われる手がかりになる）
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY'); // frame-ancestors を知らない古いブラウザ用
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('X-Robots-Tag: noindex, nofollow');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
    // ログイン中のページはブラウザに保存させない（共用 PC でログアウトしたあと「戻る」で名前などが見えないように）
    if (!empty($_SESSION['user'])) {
        header('Cache-Control: private, no-store');
    }
}

/**
 * DB接続（PDO）を返す。何回呼んでも接続は1本だけ（static で使い回す）。
 *
 *   ERRMODE_EXCEPTION   : SQL エラー時に例外を投げる（エラーを見逃さない）
 *   FETCH_ASSOC         : fetch() の結果を ['列名' => 値] の形にする
 *   EMULATE_PREPARES=false : プリペアドステートメントを MySQL 側で本当に使う
 *                         （値が SQL 文に埋め込まれないので SQL インジェクションに強い）
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * XSS対策。HTML に値を出すときは「必ず」これを通す。
 *   <script> → &lt;script&gt; に変換されるので、ブラウザがタグとして解釈しなくなる。
 *   ENT_QUOTES で ' と " も変換するので、value="..." の中に出しても安全。
 */
function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Google Fonts の Material Symbols のアイコンを出す。icon('edit') → ✎ の代わりの鉛筆。
 *   アイコン名の一覧: https://fonts.google.com/icons
 *   アイコン名を文字として書くと、フォントが絵に置き換えてくれる（リガチャ）。
 *   aria-hidden: 読み上げで「edit」と英単語が読まれないようにする。意味はボタンの文字や aria-label で伝える。
 *   $class で大きさなどを足せる（例: icon('star', 'icon--fill')）
 */
function icon(string $name, string $class = ''): string
{
    return '<span class="icon' . ($class !== '' ? ' ' . h($class) : '') . '" aria-hidden="true">' . h($name) . '</span>';
}

/**
 * YouTube のリンクとして使ってよい URL か。https で、ホストが YouTube（youtube.com / youtu.be）のものだけ OK。
 *   「javascript:alert(1)」や別サイトの URL を href に入れさせない（XSS・フィッシング対策）。
 *   parse_url で分解してホスト名を完全一致で見る（「youtube.com.example.com」のような偽物を通さない）
 */
function youtube_url_valid(string $url): bool
{
    if ($url === '' || strlen($url) > 500 || preg_match('/\s/', $url)) {
        return false;
    }
    $parts = parse_url($url);
    if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
        return false;
    }
    $host = strtolower($parts['host'] ?? '');
    return in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'], true);
}

/** YouTube のロゴ（赤い再生ボタン）。Material Symbols にブランドのロゴは無いので SVG で持つ */
function youtube_icon(): string
{
    return '<svg class="youtube-icon" viewBox="0 3.55 24 16.9" width="34" height="24" aria-hidden="true">'
        . '<path fill="#FF0000" d="M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19C0 8.07 0 12 0 12s0 3.93.5 5.81a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14C24 15.93 24 12 24 12s0-3.93-.5-5.81z"/>'
        . '<path fill="#FFFFFF" d="M9.55 15.57V8.43L15.82 12l-6.27 3.57z"/></svg>';
}

/**
 * live_day.label（日程名）のいつもの値。プルダウンの先頭にこの順で出す
 * これ以外の日程名も「＋ 新しい日程名を作る」で作れる（lib/repository.php の day_labels / resolve_day_label）
 */
const DAY_LABELS = ['1日目', '2日目', '3日目', '教室ライブ'];

/** プロフィールで選べる学部（member.faculty の CHECK 制約と同じ並び。変えるときは DB も一緒に） */
const FACULTIES = ['法学部', '政治経済学部', '商学部', '経営学部', '文学部', '情報コミュニケーション学部',
    '総合数理学部', '理工学部', '農学部', '国際日本学部'];

/** 別ページへ移動して処理を終える（exit を忘れると後ろの処理が動いてしまうので関数にまとめた） */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* =====================================================================
 *  招待コード（新規登録の合言葉）
 * ---------------------------------------------------------------------
 *  管理者が users.php から変えられるように、app_setting テーブルに置く。
 *  行が無ければ（まだ一度も変えていない・021 のマイグレーション前）config.php の invite_code を使う。
 *  '' なら誰でも登録できる。
 * ===================================================================== */
function invite_code(): string
{
    static $code = null;
    if ($code === null) {
        try {
            $st = db()->prepare('SELECT setting_value FROM app_setting WHERE setting_key = ?');
            $st->execute(['invite_code']);
            $v = $st->fetchColumn();
        } catch (PDOException $e) {
            $v = false; // app_setting がまだ無い（migrations/021 を流していない）DB でも止まらないように
        }
        $code = $v === false ? (string)config('invite_code') : (string)$v;
    }
    return $code;
}

/** 招待コードを変える（'' で「誰でも登録できる」） */
function set_invite_code(string $code, int $userId): void
{
    db()->prepare('INSERT INTO app_setting (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)')
        ->execute(['invite_code', $code, $userId]);
}

/* =====================================================================
 *  総当たり攻撃（ブルートフォース）対策
 * ---------------------------------------------------------------------
 *  何もしないと、プログラムでパスワード（招待コード）を何万回でも試せてしまう。
 *  失敗を login_attempt テーブルに記録し、15分の間に失敗が多すぎたら、しばらく受け付けない。
 *    同じログインID … 5回まで（1人のアカウントを狙い撃ちされるのを止める）
 *    同じ IP アドレス … 30回まで（いろいろなIDを順番に試されるのを止める。サークルの部室など
 *                         同じ回線から何人もログインすることがあるので、少し多めにしている）
 * ===================================================================== */
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_PER_ID = 5;
const LOGIN_MAX_PER_IP = 30;
const INVITE_MAX = 20; // 招待コードは「みんなの失敗の合計」なので多め（新歓で何人も同時に登録して打ち間違えても止まらないように）

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** このログインID（招待コードなら '#invite'）か、この IP の失敗が多すぎるか */
function too_many_failures(string $loginId, int $maxPerId = LOGIN_MAX_PER_ID): bool
{
    $st = db()->prepare('SELECT
            SUM(login_id = ?) AS by_id,
            SUM(ip = ?) AS by_ip
        FROM login_attempt
        WHERE attempted_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE AND (login_id = ? OR ip = ?)');
    $st->execute([$loginId, client_ip(), $loginId, client_ip()]);
    $row = $st->fetch();
    return (int)$row['by_id'] >= $maxPerId || (int)$row['by_ip'] >= LOGIN_MAX_PER_IP;
}

function record_failure(string $loginId): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO login_attempt (ip, login_id, attempted_at) VALUES (?, ?, NOW())')
        ->execute([client_ip(), mb_substr($loginId, 0, 25)]);
    $pdo->exec('DELETE FROM login_attempt WHERE attempted_at < NOW() - INTERVAL 1 DAY'); // 古い記録はためない
}

/** 成功したら、そのログインIDの失敗の記録を消す（次に1回間違えただけで止まらないように） */
function clear_failures(string $loginId): void
{
    db()->prepare('DELETE FROM login_attempt WHERE login_id = ?')->execute([$loginId]);
}

/* =====================================================================
 *  CSRF 対策
 * ---------------------------------------------------------------------
 *  CSRF = 悪いサイトに「このサイトへの POST フォーム」を仕込まれ、
 *         ログイン中のユーザーが知らないうちに削除などを実行させられる攻撃。
 *  対策 : セッションにランダムな合言葉（トークン）を保存し、自分のフォームにだけ埋め込む。
 *         POST を受け取ったら合言葉が一致するか確認する。悪いサイトは合言葉を知らないので失敗する。
 * ===================================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32)); // 推測できない64文字
    }
    return $_SESSION['csrf'];
}

/** フォームの中に置く hidden input */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** POST を処理する前に必ず呼ぶ。合言葉が違えばそこで止める */
function verify_csrf(): void
{
    // fetch() から送るときは X-CSRF-Token ヘッダーでも受け付ける
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    // hash_equals は「比較にかかる時間」から合言葉を推測されないようにする比較関数
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('不正なリクエストです（CSRFトークン不一致）。ページを再読み込みしてやり直してください。');
    }
}

/* =====================================================================
 *  ログイン関係
 * ---------------------------------------------------------------------
 *  ログインに成功すると $_SESSION['user'] に次の配列が入る（login.php 参照）
 *    ['user_id' => 13, 'login_id' => 'tao_08', 'name' => '垰田圭吾',
 *     'admin' => true/false, 'member_id' => 1 または null]
 * ===================================================================== */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return !empty($_SESSION['user']['admin']);
}

/**
 * ログインしていなければ login.php へ飛ばす。
 * ログイン後に元のページへ戻れるよう、今の URL を覚えておく。
 */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? './';
        redirect('login'); // ログイン画面なのは見ればわかるので「ログインしてください」のお知らせは出さない
    }
    // DB を作り直した・管理者に削除された等でアカウントが消えていたら、古いセッションを捨てる
    $st = db()->prepare('SELECT name, is_admin, member_id FROM user_account WHERE user_id = ?');
    $st->execute([$user['user_id']]);
    $row = $st->fetch();
    if (!$row) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('アカウントが見つかりません。もう一度ログインしてください', 'error');
        redirect('login');
    }
    // 名前・権限・メンバーの紐付けは、管理者が別の画面から変えることがある。
    // セッションはログインした瞬間の写しなので、毎回 DB の値で上書きして古いままにしない
    // （古いままだと、紐付けを外された人が前のメンバーのプロフィールを編集できてしまう）
    $_SESSION['user']['name'] = $row['name'];
    $_SESSION['user']['admin'] = (bool)$row['is_admin'];
    $_SESSION['user']['member_id'] = $row['member_id'] === null ? null : (int)$row['member_id'];
    return $_SESSION['user'];
}

/** 管理者専用ページで使う */
function require_admin(): array
{
    $user = require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('管理者のみ実行できます。');
    }
    return $user;
}

/* =====================================================================
 *  フラッシュメッセージ（「登録しました」など、次のページで1回だけ出すメッセージ）
 *  redirect() の前に flash() → 移動先の header.php で take_flashes() して表示。
 * ===================================================================== */

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']); // 1回出したら消す
    return $messages;
}

/* =====================================================================
 *  表示用の小さな関数
 * ===================================================================== */

/** '2025-10-30' → '10/30（木）'。NULL（日付不明）は空文字 */
function fmt_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    $w = ['日', '月', '火', '水', '木', '金', '土'];
    $ts = strtotime($date);
    return date('n/j', $ts) . '（' . $w[(int)date('w', $ts)] . '）';
}

/**
 * ランキングの順位。同じ値なら同じ順位にして、次はその人数分とばす（10, 8, 8, 5 → 1, 2, 2, 4）
 *   $rows は並べ済みの配列。$score は1行から「比べる値」を取り出す関数（[日数, 組数] のような配列でもいい）。
 *   戻り値は $rows と同じキーで順位を入れた配列。
 */
function tie_ranks(array $rows, callable $score): array
{
    $ranks = [];
    $i = 0;
    $rank = 0;
    $prev = null;
    foreach ($rows as $k => $r) {
        $i++;
        $s = $score($r);
        if ($i === 1 || $s !== $prev) {
            $rank = $i; // 前の行と値が違うときだけ、順位を「何番目か」に進める
        }
        $ranks[$k] = $rank;
        $prev = $s;
    }
    return $ranks;
}

/** live.fiscal_year → '2025年度' */
function fmt_year(mixed $year): string
{
    return ((int)$year) > 0 ? (int)$year . '年度' : '年度未設定';
}

/* ---------------------------------------------------------------------
 *  年度・学年
 *    年度は4月始まり。学年 = 今年度 - 入学年度 + 1（例: 2026年度に 2023入学 → 4年）
 *    集計（stats.php）とメンバー一覧（members.php）など、複数のページで使うのでここにまとめている。
 * ------------------------------------------------------------------- */

/** 今年度。1〜3月は前の年が今年度になる（2027年2月 → 2026年度） */
function current_fiscal_year(): int
{
    return (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
}

/** 4月始まりの年度。1〜3月は前の年の年度になる（2026年1月のライブ → 2025年度） */
function academic_year(int $month, int $year): int
{
    return $month >= 4 ? $year : $year - 1;
}

/** "2026-01-12" → 2025（年度）。日付として正しくなければ null */
function fiscal_year_from_date(string $date): ?int
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d) || !checkdate((int)$d[2], (int)$d[3], (int)$d[1])) {
        return null;
    }
    return academic_year((int)$d[2], (int)$d[1]);
}

/** 入学年度 → 学年（1, 2, …）。入学年度が無い・未来になっている（データの誤り）なら null */
function grade_of(?int $entryYear): ?int
{
    if ($entryYear === null) {
        return null;
    }
    $grade = current_fiscal_year() - $entryYear + 1;
    return $grade >= 1 ? $grade : null;
}

/** 学年 → 表示（1〜4年生は「3年」、それ以降は「OB1」「OB2」…） */
function grade_label(int $grade): string
{
    return $grade <= 4 ? $grade . '年' : 'OB' . ($grade - 4);
}

/**
 * ログイン中ユーザーの入学年度。アカウントがメンバーに紐付いていない・未登録なら null。
 * セッションには member_id しか入っていないので member テーブルから引く。
 * （セッションに entry_year を入れると、後で名簿を直したときに古い値が残るため毎回 DB を見る）
 */
function my_entry_year(): ?int
{
    $memberId = current_user()['member_id'] ?? null;
    if ($memberId === null) {
        return null;
    }
    $st = db()->prepare('SELECT entry_year FROM member WHERE member_id = ?');
    $st->execute([$memberId]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? null : (int)$v;
}

/** '13:30:00' → '13:30' */
function fmt_time(?string $time): string
{
    return $time ? substr($time, 0, 5) : '';
}

/**
 * 楽器 → 色分け用の CSS クラス名（short_name で判定）。
 */
function instrument_class(?string $short): string
{
    return match (strtolower((string)$short)) {
        'vo' => 'vo',
        'cho' => 'cho',
        'gt' => 'gt',
        'ba' => 'ba',
        'dr', 'perc' => 'dr',
        'key' => 'key',
        'vn' => 'vn',
        'sax' => 'sax',
        default => 'other',
    };
}

/**
 * instrument テーブルを全部取る（セレクトボックス用）。1リクエスト中は使い回す。
 * 表示順は DB の sort_order 列が持っている（PHP 側に並び順を書かなくて済む）。
 */
function instruments(): array
{
    static $list = null;
    $list ??= db()->query('SELECT instrument_id, name, short_name, sort_order FROM instrument ORDER BY sort_order')->fetchAll();
    return $list;
}

/**
 * 「誰がどのバンドにいたか」（楽器は問わない）を表すサブクエリ。
 * band_member は (バンド, 人, 楽器) で1行なので、兼任していると同じ人が2行ある。
 * DISTINCT で (バンド, 人) を1行にまとめてから数えないと、出演回数が2倍になる。
 *   使い方: FROM (" . MEMBERSHIP_SQL . ") bm
 */
const MEMBERSHIP_SQL = 'SELECT DISTINCT band_id, member_id FROM band_member';

/**
 * 日程ごとの「トリの出演順」を表すサブクエリ（live_day_id, max_order）。
 * トリ = その日程で出演順が一番うしろのバンド。
 * ただし総バンド数（live_day.total_bands）より登録済みが少ない日程は、本当の最後のバンドが
 * 登録されていない → 登録済みの最後はトリではないので、その日程の行を出さない（HAVING）。
 *   使い方: JOIN (" . HEADLINER_SQL . ") last ON last.live_day_id = b.live_day_id AND last.max_order = b.play_order
 */
const HEADLINER_SQL = 'SELECT b.live_day_id, MAX(b.play_order) AS max_order
    FROM band b JOIN live_day d ON d.live_day_id = b.live_day_id
    GROUP BY b.live_day_id, d.total_bands
    HAVING d.total_bands IS NULL OR COUNT(*) >= d.total_bands';

/**
 * 「アーティスト × そのアーティストをコピーしたバンド」の組を表すサブクエリ。
 *   ふつうのバンド    : band.artist_id
 *   オムニバスのバンド: band.artist_id は使わず、曲に付いたアーティスト（song.artist_id）。付いていない曲は数えない
 *   UNION（ALL なし）なので同じ組は1つにまとまる → オムニバスで同じアーティストを2曲やっても1回
 *   使い方: FROM (" . ARTIST_PLAYS_SQL . ") p   … p.artist_id, p.band_id
 */
const ARTIST_PLAYS_SQL = 'SELECT artist_id, band_id FROM band WHERE is_omnibus = 0 AND artist_id IS NOT NULL
    UNION
    SELECT s.artist_id, s.band_id FROM song s JOIN band b ON b.band_id = s.band_id
        WHERE b.is_omnibus = 1 AND s.artist_id IS NOT NULL';

/** ヘッダー（<html> 〜 <main>）を出す。$active はナビのどこを光らせるか */
function render_header(string $title, string $active = ''): void
{
    $pageTitle = $title;
    $activeNav = $active;
    require __DIR__ . '/../partials/header.php';
}

function render_footer(): void
{
    require __DIR__ . '/../partials/footer.php';
}


/**
 * 取り込みのプレビュー・タイムテーブルの編集のフォームは入力欄が多い（数百個）。
 * PHP には「1回の POST で受け取れる項目数」の上限（php.ini の max_input_vars、XAMPP では 1000）があり、
 * 超えた分は「エラーも出さずに捨てられる」。名簿が大きいと登録内容が欠けてしまう。
 *
 * 対策: JavaScript が送信直前に全項目を JSON 1個（payload）にまとめて送る（assets/app.js）。
 * ここで JSON を元の $_POST と同じ形の配列に戻す。
 * JavaScript が動かないときは payload が無いので、普通の $_POST をそのまま使う。
 */
function read_form_input(): array
{
    $payload = $_POST['payload'] ?? null;
    if (!is_string($payload) || $payload === '') {
        return $_POST;
    }
    $pairs = json_decode($payload, true);
    if (!is_array($pairs)) {
        return $_POST;
    }
    // $pairs は [["tt[0][s][3][name]", "King Gnu"], ["action", "commit"], ...] の形
    $input = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0]) || !is_string($pair[1])) {
            continue;
        }
        // "tt[0][s][3][name]" → ['tt', '0', 's', '3', 'name'] に分解
        if (!preg_match('/^([^\[\]]+)((?:\[[^\[\]]*\])*)$/', $pair[0], $m)) {
            continue;
        }
        preg_match_all('/\[([^\[\]]*)\]/', $m[2], $sub);
        $keys = array_merge([$m[1]], $sub[1]);

        // $input['tt']['0']['s']['3']['name'] = 'King Gnu' を、キーの数がいくつでも動くように書いたもの
        // $ref は「今いる場所」を指す参照。1段ずつ奥へ進んでいく
        $ref = &$input;
        foreach ($keys as $k) {
            if (!is_array($ref)) {
                $ref = [];
            }
            $ref = &$ref[$k];
        }
        $ref = $pair[1];
        unset($ref); // 参照を切っておかないと、次のループで上書き事故が起きる
    }
    return $input;
}

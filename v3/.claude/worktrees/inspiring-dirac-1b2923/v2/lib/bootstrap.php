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

/**
 * config.php の値を取り出す。
 *   config()          → 配列まるごと
 *   config('db')      → ['dsn' => ..., 'user' => ..., 'pass' => ...]
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
        $config = require $path; // config.php は return [...] しているので、その配列が入る
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
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
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
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        flash('ログインしてください', 'info');
        redirect('login.php');
    }
    return $user;
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

/** '2025-10-30' → '10/30（木）'。'0000-00-00'（日付未入力のデータ）は空文字 */
function fmt_date(?string $date): string
{
    if (!$date || str_starts_with($date, '0000')) {
        return '';
    }
    $w = ['日', '月', '火', '水', '木', '金', '土'];
    $ts = strtotime($date);
    return date('n/j', $ts) . '（' . $w[(int)date('w', $ts)] . '）';
}

/** live_master.year は YEAR 型。'0000' は「未設定」として扱う */
function fmt_year(mixed $year): string
{
    return ((int)$year) > 0 ? (int)$year . '年度' : '年度未設定';
}

/**
 * 楽器 → 色分け用の CSS クラス名。
 * instrument_short（'Vo.' 'Vo./Gt.' など）の先頭で判定する。
 * ギターボーカルはボーカル色にする（バンドの顔なので）。
 */
function instrument_class(?string $short): string
{
    $s = strtolower((string)$short);
    return match (true) {
        str_starts_with($s, 'vo') => 'vo',
        str_starts_with($s, 'gt') => 'gt',
        str_starts_with($s, 'ba') => 'ba',
        str_starts_with($s, 'dr') => 'dr',
        str_starts_with($s, 'key') => 'key',
        default => 'other',
    };
}

/**
 * 表示するときの楽器の並び順（instrument_id の順）。
 * ボーカル系 → ギター → ベース → ドラム → キーボード → その他
 */
const INSTRUMENT_DISPLAY_ORDER = [1 => 1, 8 => 2, 9 => 3, 2 => 4, 3 => 5, 4 => 6, 5 => 7, 6 => 8, 7 => 9];

function instrument_sort_key(?int $instrumentId): int
{
    return INSTRUMENT_DISPLAY_ORDER[$instrumentId ?? 0] ?? 99;
}

/** instrument テーブルを全部取る（セレクトボックス用）。1リクエスト中は使い回す */
function instruments(): array
{
    static $list = null;
    $list ??= db()->query('SELECT instrument_id, instrument_name, instrument_short FROM instrument ORDER BY instrument_id')->fetchAll();
    return $list;
}

/**
 * 「誰がどのバンドにいたか」を表すサブクエリ。
 *
 * DB には band_member（在籍）と band_member_instrument（楽器）の2つがあり、
 * どちらか片方にしか行が無いケースもあり得るので UNION（重複を消して合体）して使う。
 *   使い方: FROM (" . MEMBERSHIP_SQL . ") bm JOIN band b ON b.band_id = bm.band_id
 */
const MEMBERSHIP_SQL = 'SELECT band_id, member_id FROM band_member
                        UNION
                        SELECT band_id, member_id FROM band_member_instrument';

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

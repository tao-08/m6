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
    // DB を作り直した・管理者に削除された等でアカウントが消えていたら、古いセッションを捨てる
    $st = db()->prepare('SELECT 1 FROM user_account WHERE user_id = ?');
    $st->execute([$user['user_id']]);
    if (!$st->fetchColumn()) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('アカウントが見つかりません。もう一度ログインしてください', 'error');
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

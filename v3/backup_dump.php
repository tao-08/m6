<?php
/**
 * =====================================================================
 *  backup_dump.php — DB のバックアップ（SQL を gzip して返す）
 * =====================================================================
 *  人が開くページではない。GitHub Actions（.github/workflows/backup.yml）が3日おきに呼ぶ。
 *  ダンプにはパスワードのハッシュ・メールアドレス・通知の購読情報まで入るので、入口は厳しくしてある:
 *    ・POST だけ。合言葉（config.php の backup_token）をヘッダー X-Backup-Token で受け取る
 *        - URL に載せない（URL はアクセスログに残る）
 *        - Authorization ヘッダーにしない（レンタルサーバーの FastCGI は途中で捨てることがある）
 *    ・合言葉が config.php に無い・短い（32文字未満）ときは、何を送られても使えない
 *    ・合言葉が違う・POST じゃないときは、ここにページがあること自体を教えない（404）
 *    ・間違いが続いたら、合っていても受け付けない（総当たり対策。ログインと同じ仕組み）
 *    ・ログインの CSRF は使わない: ブラウザではなく機械が呼ぶので、Cookie に頼った攻撃は成り立たない
 *  合言葉は GitHub の Secrets（BACKUP_TOKEN）と config.php に同じ値を入れる（README の「バックアップ」）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/backup.php';

const BACKUP_MAX_FAILURES = 10;
const BACKUP_TOKEN_MIN = 32;

$expected = (string)config('backup_token');
if (!is_post() || strlen($expected) < BACKUP_TOKEN_MIN || too_many_failures('#backup', BACKUP_MAX_FAILURES)) {
    error_page(404);
}
if (!hash_equals($expected, (string)($_SERVER['HTTP_X_BACKUP_TOKEN'] ?? ''))) {
    record_failure('#backup');
    error_page(404);
}

session_write_close(); // セッションのロックを手放す（時間がかかるので、ほかのリクエストを待たせない）
set_time_limit(300);

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="abbey-' . date('Ymd-His') . '.sql.gz"');
header('Cache-Control: no-store');
while (ob_get_level() > 0) {
    ob_end_clean(); // 出力のバッファを切って、できた分から送る
}

// gzip しながら少しずつ送る（deflate_add は渡した分を圧縮して、できた分の文字列を返す）
$gz = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
backup_dump(db(), static function (string $sql) use ($gz): void {
    echo deflate_add($gz, $sql, ZLIB_NO_FLUSH);
});
echo deflate_add($gz, '', ZLIB_FINISH);

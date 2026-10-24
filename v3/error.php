<?php
/**
 * error.php — Apache が 400 / 403 / 404 / 500 などを返すときに出すページ（.htaccess の ErrorDocument）
 *  REDIRECT_STATUS: Apache が「元のエラーのステータス」を入れてくれる。URL を直接開いたときは無いので 404 にする
 *  bootstrap は読まない（DB やセッションが壊れていても出すため。lib/error_page.php の説明を参照）
 */
declare(strict_types=1);
require __DIR__ . '/lib/error_page.php';

$code = (int)($_SERVER['REDIRECT_STATUS'] ?? 404);
render_error_page($code >= 400 ? $code : 404);

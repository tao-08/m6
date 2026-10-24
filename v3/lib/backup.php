<?php
/**
 * =====================================================================
 *  backup.php — DB 全体を SQL として書き出す
 * =====================================================================
 *  mysqldump はレンタルサーバーで使えるか分からないので、PHP だけで同じことをする。
 *  出す中身: 各テーブルの DROP / CREATE と INSERT。そのまま mysql に流せば元に戻る:
 *      gunzip -c backup.sql.gz | mysql -u ユーザー -p データベース名
 *  呼び出し元は backup_dump.php（GitHub Actions が3日おきに呼ぶ）
 *
 *  ・外部キーがあるので、先頭で FOREIGN_KEY_CHECKS を切る（テーブルの順番を気にしなくてよい）
 *  ・START TRANSACTION WITH CONSISTENT SNAPSHOT: 書き出している間に誰かが保存しても、
 *    「ある瞬間の DB」がそのまま取れる（テーブルごとに時刻がずれて、親のいない子の行ができるのを防ぐ）
 *  ・BLOB 列（change_unit.snapshot）は文字として入れると壊れるので 0x…（16進）で出す
 *  ・login_attempt（ログイン失敗の記録）は入れない: 個人情報に近く、元に戻す価値もない
 *  ・最終行の「-- backup complete」は、途中で切れていないかを GitHub Actions が確かめる目印
 *  ・ファイル（掲示板などの添付画像・型紙の PDF）は DB の外にあるので、これには入らない
 * =====================================================================
 */
declare(strict_types=1);

/** バックアップに入れないテーブル */
const BACKUP_SKIP_TABLES = ['login_attempt'];
/** 1つの INSERT にまとめる行数（多すぎると mysql の max_allowed_packet を超える） */
const BACKUP_ROWS_PER_INSERT = 100;

/**
 * $out(文字列) に SQL を少しずつ渡す。全部を1つの文字列にためない（DB が大きくなってもメモリを使いすぎないため）
 */
function backup_dump(PDO $pdo, callable $out): void
{
    $quoteName = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';

    // 書き出し中は1行ずつ受け取る（バッファに全部ためない）。終わったら元に戻す
    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try {
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_values(array_diff($tables, BACKUP_SKIP_TABLES));
        sort($tables, SORT_STRING);

        $out("-- AbbeyRoad.online DB backup\n");
        $out('-- ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ")\n");
        $out('-- tables: ' . count($tables) . "\n\n");
        $out("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach ($tables as $table) {
            $t = $quoteName($table);
            // fetchAll で最後まで読む（1行でも fetch() で止めると、次のクエリが「読み途中の結果がある」とエラーになる）
            $create = $pdo->query("SHOW CREATE TABLE {$t}")->fetchAll(PDO::FETCH_NUM)[0][1];
            $columns = $pdo->query("SHOW COLUMNS FROM {$t}")->fetchAll(PDO::FETCH_ASSOC);
            $columnList = implode(', ', array_map(static fn(array $c): string => $quoteName($c['Field']), $columns));
            // blob / binary の列は 16進で出す
            $binary = array_map(static fn(array $c): bool => (bool)preg_match('/blob|binary/i', $c['Type']), $columns);

            $out("-- {$table}\nDROP TABLE IF EXISTS {$t};\n{$create};\n\n");

            $rows = [];
            $count = 0;
            $flush = static function () use (&$rows, $out, $t, $columnList): void {
                if ($rows) {
                    $out("INSERT INTO {$t} ({$columnList}) VALUES\n" . implode(",\n", $rows) . ";\n");
                    $rows = [];
                }
            };
            $st = $pdo->query("SELECT * FROM {$t}");
            while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                $values = [];
                foreach ($row as $i => $v) {
                    if ($v === null) {
                        $values[] = 'NULL';
                    } elseif ($binary[$i]) {
                        $values[] = $v === '' ? "''" : '0x' . bin2hex((string)$v);
                    } else {
                        $values[] = $pdo->quote((string)$v);
                    }
                }
                $rows[] = '(' . implode(', ', $values) . ')';
                $count++;
                if (count($rows) >= BACKUP_ROWS_PER_INSERT) {
                    $flush();
                }
            }
            $st->closeCursor();
            $flush();
            $out("-- rows: {$count}\n\n");
        }

        $out("SET FOREIGN_KEY_CHECKS = 1;\n-- backup complete\n");
    } finally {
        $pdo->exec('ROLLBACK'); // 読むだけ。スナップショットを手放す
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
    }
}

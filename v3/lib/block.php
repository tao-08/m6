<?php
/**
 * =====================================================================
 *  lib/block.php — ブロックパズルのランキング（block.php と api_block_score.php で共有）
 * =====================================================================
 *  表は block_best（部員ごとの自己ベスト = ベストスコア、1人1行。migrations/104）と
 *  block_month_best（月ごとの自己ベスト = 月間、1人1か月1行。migrations/126）。並べ方・順位は lib/ranking.php。
 *  `lines` は MySQL の予約語なので、SQL の中ではバッククォートで囲む。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/ranking.php';

const BLOCK_MAX_SCORE  = 99999999; // 点数の上限（INT UNSIGNED に十分収まる。ありえない値を弾く）
const BLOCK_MAX_LINES  = 9999;     // ライン数の上限（SMALLINT UNSIGNED に収まる）

/** ライン数から決まるレベル（10 ライン毎に +1、上限 20）。ゲーム側（assets/block.js）と同じ式 */
function block_level_for_lines(int $lines): int
{
    return min(20, 1 + intdiv($lines, 10));
}

/**
 * ランキングを取る（ベストスコア・今月・先月の1位。キーは lib/ranking.php の ranking_payload）。
 *   ranking / month_ranking = 記録のある全員 [['rank'=>1, 'name'=>'…', 'score'=>…, 'lines'=>…, 'level'=>…, 'me'=>bool, 'fold'=>bool], …]
 *   mine / month_mine = 自分の記録 ['rank'=>…, 'score'=>…, 'lines'=>…, 'level'=>…]。まだ記録が無ければ null
 */
function block_rankings(PDO $pdo, int $userId): array
{
    return ranking_payload($pdo, 'block_best', 'block_month_best', 'b.`lines`, b.level', $userId);
}

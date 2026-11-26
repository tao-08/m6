<?php
/**
 * =====================================================================
 *  setlist_jobs.php — 「YouTube から曲を検出」の途中経過の一覧（管理者のみ。tools.php のデータタブから入る）
 * =====================================================================
 *  api_setlist_detect.php が置く途中経過（lib/gemini_setlist.php の setlist_job_*）を全部並べる。
 *  解析中・終わって確認待ち・失敗。確認待ちはバンドの曲編集を開くと確認画面へ進み、ここから消える。
 *  表示するだけ（POST を受けない）。バンド名・エラー文は h() でエスケープする。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/gemini_setlist.php';
require_once __DIR__ . '/lib/repository.php';
require_admin();

$jobs = [];
foreach (glob(ytdlp_dir() . '/detect-*.json') ?: [] as $file) {
    if (preg_match('/detect-(\d+)\.json$/', $file, $m) && ($job = setlist_job_get((int)$m[1])) !== null) {
        $jobs[(int)$m[1]] = $job;
    }
}
$names = [];
if ($jobs) {
    $st = db()->prepare('SELECT band_id, name FROM band WHERE band_id IN (' . implode(',', array_fill(0, count($jobs), '?')) . ')');
    $st->execute(array_keys($jobs));
    $names = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
uasort($jobs, static fn($a, $b) => ((int)($b['at'] ?? 0)) <=> ((int)($a['at'] ?? 0)));

render_header('曲の検出の状況', 'tools');
?>
<h1>曲の検出の状況</h1>
<?php if (!$jobs): ?>
    <div class="empty card"><p class="empty__title">検出中のバンドはありません</p></div>
<?php else: ?>
    <table class="table">
        <thead><tr><th>バンド</th><th>ステータス</th><th>速度</th><th>開始</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $id => $job): ?>
            <tr>
                <td><a href="songs_edit?band=<?= $id ?>"><?= h((string)($names[$id] ?? '#' . $id)) ?></a></td>
                <td><?php if ($job['state'] === 'running'): ?>
                    解析中 <?= (int)floor((time() - (int)$job['at']) / 60) ?>分
                <?php elseif ($job['state'] === 'done'): ?>
                    確認待ち
                <?php else: ?>
                    <span <?= tip_attr((string)($job['error'] ?? '')) ?> style="cursor: help">失敗</span>
                <?php endif; ?></td>
                <td><?= isset($job['flex']) ? ($job['flex'] ? '低速' : '高速') : '' ?></td>
                <td><?= isset($job['at']) ? date('n/j H:i', (int)$job['at']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php render_footer();

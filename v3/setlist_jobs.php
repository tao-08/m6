<?php
/**
 * =====================================================================
 *  setlist_jobs.php — 「YouTube から曲を検出」の途中経過の一覧（管理者のみ。tools.php のデータタブから入る）
 * =====================================================================
 *  api_setlist_detect.php が置く途中経過（lib/gemini_setlist.php の setlist_job_*）を全部並べる。
 *  解析中・終わって確認待ち・失敗。確認待ちはバンドの曲編集を開くと確認画面へ進み、ここから消える。
 *  下に、曲の場面（song.video_start）が入ったバンドを並べる。開くとセットリスト編集の ▶ で秒が合っているか確かめられる
 *  「チャプター」タブ（?tab=chapter）: 年度→ライブ→動画で、YouTube のチャプターの状態（lib/chapter_job.php）を並べる。
 *    ライブの「未解析を解析」は api_chapter_bulk.php を fetch で呼ぶ（assets/app_admin.js の setupChapterBulk）。画面は移らない
 *    動画ごとに、YouTube の区分の MP3 があれば「MP3」の印（見出しは 全部の本数・解析済み・MP3 のある本数）
 *    上の「MP3 を紐付け」はドライブのフォルダの「動画ID.mp3」をバンドに紐付ける（api_yt_drive.php。setupYtDrive）
 *  表示するだけ（POST を受けない）。バンド名・エラー文は h() でエスケープする。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/gemini_setlist.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/youtube.php';
require_once __DIR__ . '/lib/chapter_job.php';
require_admin();

$tab = ($_GET['tab'] ?? '') === 'chapter' ? 'chapter' : 'detect';
$tabNav = static function () use ($tab): void { ?>
    <nav class="tabs">
        <a class="tab<?= $tab === 'detect' ? ' is-active' : '' ?>" href="setlist_jobs">曲の検出</a>
        <a class="tab<?= $tab === 'chapter' ? ' is-active' : '' ?>" href="setlist_jobs?tab=chapter">チャプター</a>
    </nav>
<?php };

if ($tab === 'chapter') {
    $pdo = db();
    $bands = $pdo->query('SELECT b.band_id, b.name, b.youtube_url, l.live_id, l.name AS live_name, l.fiscal_year
        FROM band b JOIN live_day d ON d.live_day_id = b.live_day_id JOIN live l ON l.live_id = d.live_id
        ORDER BY l.fiscal_year DESC, l.live_id DESC, d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order, b.band_id')->fetchAll();
    $urls = band_video_urls($pdo, $bands);
    $saved = []; // "band_id|y:動画ID" => true
    foreach ($pdo->query("SELECT DISTINCT band_id, audio_key FROM band_audio_chapter WHERE audio_key LIKE 'y:%'") as $r) {
        $saved[$r['band_id'] . '|' . $r['audio_key']] = true;
    }
    $mp3 = []; // "band_id|動画ID" => true（YouTube の区分の MP3 がある。migrations/153）
    foreach ($pdo->query('SELECT band_id, video_id FROM band_drive_file WHERE video_id IS NOT NULL UNION SELECT band_id, video_id FROM band_audio WHERE video_id IS NOT NULL') as $r) {
        $mp3[$r['band_id'] . '|' . $r['video_id']] = true;
    }
    $years = []; // 年度 => ライブID => [name, videos => [[band, no, count, state, error, MP3 があるか]], done, mp3]
    foreach ($bands as $b) {
        $list = $urls[(int)$b['band_id']] ?? [];
        foreach ($list as $no => $url) {
            $vid = youtube_video_id($url);
            if ($vid === null) {
                continue;
            }
            $job = chapter_job_get($vid);
            $state = $job['state'] ?? 'none';
            if (in_array($state, ['none', 'error'], true) && isset($saved[$b['band_id'] . '|y:' . $vid])) {
                $state = 'saved'; // 保存済みのやり直しの失敗は、まとめて解析の対象外（api_chapter_bulk.php と同じ）
            }
            $live = &$years[(int)$b['fiscal_year']][(int)$b['live_id']];
            $live['name'] = (string)$b['live_name'];
            $hasMp3 = isset($mp3[$b['band_id'] . '|' . $vid]);
            $live['videos'][] = [$b, $no, count($list), $state, (string)($job['error'] ?? ''), $hasMp3];
            $live['done'] = ($live['done'] ?? 0) + ($state === 'saved' ? 1 : 0);
            $live['mp3'] = ($live['mp3'] ?? 0) + ($hasMp3 ? 1 : 0);
            unset($live);
        }
    }
    // 見出しの数: 全部の本数・解析済み・MP3 のある本数
    $counts = static fn(int $all, int $done, int $mp3): string => '<span class="chapter-counts muted small"><span>' . $all . '本</span>'
        . '<span ' . tip_attr('解析済み') . '>' . icon('view_timeline', 'icon--sm') . $done . '</span>'
        . '<span ' . tip_attr('MP3 がある動画') . '><span class="tag label-inline">MP3</span>' . $mp3 . '</span></span>';
    $label = ['saved' => '解析済み', 'running' => '解析中', 'queued' => '順番待ち', 'error' => '失敗', 'none' => '未解析'];

    render_header('解析の状況', 'tools');
    ?>
    <h1>解析の状況</h1>
    <?php $tabNav(); ?>
    <form class="yt-drive" data-yt-drive>
        <input type="url" name="url" required maxlength="500" inputmode="url" autocomplete="off" placeholder="https://drive.google.com/drive/folders/…" aria-label="MP3 のフォルダのリンク">
        <button type="submit" class="btn btn--sm">MP3 を紐付け</button>
        <details class="yt-drive__miss small" data-yt-drive-miss hidden><summary></summary><ul></ul></details>
    </form>
    <?php if (!$years): ?>
        <div class="empty card"><p class="empty__title">YouTube のあるバンドはありません</p></div>
    <?php endif; ?>
    <div class="chapter-years">
    <?php $first = true; foreach ($years as $fy => $lives): ?>
        <details<?= $first ? ' open' : '' ?>>
            <?php $first = false; $all = array_sum(array_map(static fn($l) => count($l['videos']), $lives)); ?>
            <summary><?= h(fmt_year($fy)) ?> <?= $counts($all, array_sum(array_column($lives, 'done')), array_sum(array_column($lives, 'mp3'))) ?><?= icon('expand_more') ?></summary>
            <?php foreach ($lives as $liveId => $l): ?>
                <details class="chapter-live" data-chapter-live="<?= $liveId ?>">
                    <summary><?= h($l['name']) ?> <?= $counts(count($l['videos']), $l['done'], $l['mp3'] ?? 0) ?><?= icon('expand_more') ?></summary>
                    <?php if (array_filter($l['videos'], static fn($v) => in_array($v[3], ['none', 'error'], true))): ?>
                        <button type="button" class="btn btn--sm" data-chapter-bulk>未解析を解析</button>
                    <?php endif; ?>
                    <ul>
                    <?php foreach ($l['videos'] as [$b, $no, $count, $state, $error, $hasMp3]): ?>
                        <li>
                            <a href="video_chapters?band=<?= (int)$b['band_id'] ?>&amp;v=<?= $no ?>"><?= h((string)$b['name']) ?><?= $count > 1 ? ' ' . ($no + 1) . '本目' : '' ?></a>
                            <?php if ($hasMp3): ?><span class="tag">MP3</span><?php endif; ?>
                            <span class="muted small" data-chapter-state="<?= $state ?>"<?= $error !== '' ? ' ' . tip_attr($error) . ' style="cursor: help"' : '' ?>><?= $label[$state] ?? '' ?></span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </details>
            <?php endforeach; ?>
        </details>
    <?php endforeach; ?>
    </div>
    <?php
    render_footer();
    exit;
}

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
// 曲の場面が入ったバンド（場面のある曲数 / SE を除く曲数）
$seeks = db()->query('SELECT b.band_id, b.name, SUM(s.video_start IS NOT NULL) AS seek, SUM(s.is_se = 0) AS songs
    FROM song s JOIN band b ON b.band_id = s.band_id GROUP BY b.band_id, b.name HAVING seek > 0 ORDER BY b.name')->fetchAll();
uasort($jobs, static fn($a, $b) => ((int)($b['at'] ?? 0)) <=> ((int)($a['at'] ?? 0)));

render_header('解析の状況', 'tools');
?>
<h1>解析の状況</h1>
<?php $tabNav(); ?>
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
                    <span <?= tip_attr(trim(($job['error'] ?? '') . ' / ' . ($job['detail'] ?? ''), ' /')) ?> style="cursor: help">失敗</span>
                <?php endif; ?></td>
                <td><?= isset($job['flex']) ? ($job['flex'] ? '低速' : '高速') : '' ?></td>
                <td><?= isset($job['at']) ? date('n/j H:i', (int)$job['at']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php if ($seeks): ?>
    <h2 class="section-title">曲の場面があるバンド</h2>
    <table class="table">
        <thead><tr><th>バンド</th><th>曲</th></tr></thead>
        <tbody>
        <?php foreach ($seeks as $b): ?>
            <tr>
                <td><a href="songs_edit?band=<?= (int)$b['band_id'] ?>"><?= h((string)$b['name']) ?></a></td>
                <td><?= (int)$b['seek'] ?> / <?= (int)$b['songs'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php render_footer();

<?php
/**
 * =====================================================================
 *  setlist_jobs.php — 「YouTube から曲を検出」の途中経過の一覧（管理者のみ。tools.php のデータタブから入る）
 * =====================================================================
 *  api_setlist_detect.php が置く途中経過（lib/gemini_setlist.php の setlist_job_*）を全部並べる。
 *  順番待ち（cron の lib/cron_worker.php が動かす）・解析中・終わって確認待ち・失敗。確認待ちはバンドの曲編集を開くと確認画面へ進み、ここから消える。
 *  下に、曲の場面（song.video_start）が入ったバンドを並べる。開くとセットリスト編集の ▶ で秒が合っているか確かめられる
 *  「チャプター」タブ（?tab=chapter）: 年度→ライブ→動画で、YouTube のチャプターの状態（lib/chapter_job.php）を並べる。
 *    ライブの「未解析を解析」は api_chapter_bulk.php を fetch で呼ぶ（assets/app_admin.js の setupChapterBulk）。画面は移らない
 *    解析のあと人がチャプターを直した動画は「解析済み」の代わりに緑の ✓「編集済み」（check_badge）
 *    動画ごとに、YouTube の区分の MP3 があれば「MP3」の印（見出しは 全部の本数・解析済み・MP3 のある本数）
 *    上の「MP3 を紐付け」はドライブのフォルダの「動画ID.mp3」をバンドに紐付ける（api_yt_drive.php。setupYtDrive）
 *    紐付けたフォルダ（yt_drive_folder）は下に並べ、URL を直して「紐付け直す」・「紐付けを解除」ができる（migrations/158）
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

$tab = in_array($_GET['tab'] ?? '', ['chapter', 'usage'], true) ? (string)$_GET['tab'] : 'detect';
$tabNav = static function () use ($tab): void { ?>
    <nav class="tabs">
        <a class="tab<?= $tab === 'detect' ? ' is-active' : '' ?>" href="setlist_jobs">曲の検出</a>
        <a class="tab<?= $tab === 'chapter' ? ' is-active' : '' ?>" href="setlist_jobs?tab=chapter">チャプター</a>
        <a class="tab<?= $tab === 'usage' ? ' is-active' : '' ?>" href="setlist_jobs?tab=usage">使用量</a>
    </nav>
<?php };

// 「使用量」タブ: Gemini に送った回数とトークン数（lib/gemini_chapters.php の gemini_usage_log）。30日分を処理ごとに
if ($tab === 'usage') {
    $names = ['gemini_setlist' => '曲の検出', 'gemini_setlist_one' => '1曲の検出し直し', 'gemini_setlist_compare' => '聴き比べ',
        'gemini_setlist_check' => '歌詞で確認', 'gemini_setlist_lyrics' => '歌詞で確認', 'gemini_audio_chapters' => 'チャプター検出', 'api_lyrics_search' => '歌詞検索'];
    $sum = [];
    $since = time() - 30 * 86400;
    foreach (is_file($path = gemini_usage_path()) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        $r = json_decode($line, true);
        if (!is_array($r) || (int)($r['t'] ?? 0) < $since) {
            continue;
        }
        $k = ($names[$r['f'] ?? ''] ?? (string)($r['f'] ?? '')) . "	" . ($r['m'] ?? '') . "	" . (int)($r['x'] ?? 0);
        $sum[$k] ??= ['n' => 0, 'a' => 0, 'p' => 0, 'o' => 0, 'th' => 0];
        $sum[$k]['n']++;
        foreach (['a', 'p', 'o', 'th'] as $c) $sum[$k][$c] += (int)($r[$c] ?? 0);
    }
    ksort($sum);
    render_header('解析の状況', 'tools');
    ?>
    <h1 class="display display--sm">解析の状況</h1>
    <?php $tabNav(); ?>
    <?php if (!$sum): ?>
        <div class="empty card"><p class="empty__title">30日以内の記録はありません</p></div>
    <?php else: ?>
        <p class="muted small">30日分。トークン数は1回あたりの平均</p>
        <div class="table-scroll table-scroll--flush"><table class="table">
            <thead><tr><th>処理</th><th>モデル</th><th>速度</th><th>回数</th><th>音声</th><th>文</th><th>出力</th><th>思考</th></tr></thead>
            <tbody>
            <?php foreach ($sum as $k => $s): [$label, $model, $flex] = explode("	", $k); ?>
                <tr>
                    <td><?= h($label) ?></td><td><?= h($model) ?></td><td><?= $flex ? '通常' : '高速' ?></td><td><?= $s['n'] ?></td>
                    <?php foreach (['a', 'p', 'o', 'th'] as $c): ?><td><?= number_format(intdiv($s[$c], $s['n'])) ?></td><?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?php
    render_footer();
    exit;
}

if ($tab === 'chapter') {
    chapter_unconfirmed_migrate();
    $pdo = db();
    $bands = $pdo->query('SELECT b.band_id, b.name, b.youtube_url, l.live_id, l.name AS live_name, l.fiscal_year
        FROM band b JOIN live_day d ON d.live_day_id = b.live_day_id JOIN live l ON l.live_id = d.live_id
        ORDER BY l.fiscal_year DESC, (SELECT MIN(x.held_on) FROM live_day x WHERE x.live_id = l.live_id) IS NULL, (SELECT MIN(x.held_on) FROM live_day x WHERE x.live_id = l.live_id) DESC, l.live_id DESC, d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order, b.band_id')->fetchAll();
    $urls = band_video_urls($pdo, $bands);
    $saved = []; // "band_id|y:動画ID" => true
    foreach ($pdo->query("SELECT DISTINCT band_id, audio_key FROM band_audio_chapter WHERE audio_key LIKE 'y:%'") as $r) {
        $saved[$r['band_id'] . '|' . $r['audio_key']] = true;
    }
    $mp3 = []; // "band_id|動画ID" => true（YouTube の区分の MP3 がある。migrations/153）
    foreach ($pdo->query('SELECT band_id, video_id FROM band_drive_file WHERE video_id IS NOT NULL UNION SELECT band_id, video_id FROM band_audio WHERE video_id IS NOT NULL') as $r) {
        $mp3[$r['band_id'] . '|' . $r['video_id']] = true;
    }
    $hasSongs = array_flip($pdo->query('SELECT DISTINCT band_id FROM song')->fetchAll(PDO::FETCH_COLUMN));
    $years = []; // 年度 => ライブID => [name, videos => [[band, no, count, state, error, MP3 があるか]], done, mp3]
    foreach ($bands as $b) {
        $list = $urls[(int)$b['band_id']] ?? [];
        // セトリが無く、動画が全部 MP3 を持つ: 「未解析を解析」で曲も検出する（api_setlist_bulk.php と同じ条件）
        if ($list && !isset($hasSongs[$b['band_id']]) && (setlist_job_get((int)$b['band_id'])['state'] ?? 'error') === 'error'
            && !array_filter($list, static fn($u) => !isset($mp3[$b['band_id'] . '|' . youtube_video_id($u)]))) {
            $years[(int)$b['fiscal_year']][(int)$b['live_id']]['detect'] = true;
        }
        foreach ($list as $no => $url) {
            $vid = youtube_video_id($url);
            if ($vid === null) {
                continue;
            }
            $job = chapter_job_get($vid);
            $state = $job['state'] ?? 'none';
            if ($state === 'edited' && !isset($saved[$b['band_id'] . '|y:' . $vid])) {
                $state = 'none'; // 全部消した
            } elseif (in_array($state, ['none', 'error'], true) && isset($saved[$b['band_id'] . '|y:' . $vid])) {
                $state = 'saved'; // 保存済みのやり直しの失敗は、まとめて解析の対象外（api_chapter_bulk.php と同じ）
            }
            $live = &$years[(int)$b['fiscal_year']][(int)$b['live_id']];
            $live['name'] = (string)$b['live_name'];
            $hasMp3 = isset($mp3[$b['band_id'] . '|' . $vid]);
            if (!$hasMp3 && in_array($state, ['none', 'error'], true)) {
                $state = 'nomp3'; // 聴かせる音源が無い（まとめて解析の対象外）
            }
            $live['videos'][] = [$b, $no, count($list), $state, (string)($job['error'] ?? ''), $hasMp3];
            $live['done'] = ($live['done'] ?? 0) + ($state === 'edited' ? 1 : 0);
            $live['mp3'] = ($live['mp3'] ?? 0) + ($hasMp3 ? 1 : 0);
            unset($live);
        }
    }
    // 見出しの数: 全部の本数・編集済み（人が直した本数。進み具合のバー）・MP3 のある本数
    $counts = static fn(int $all, int $done, int $mp3): string => '<span class="chapter-counts muted small"><span>' . $all . '本</span>'
        . '<span ' . tip_attr('編集済み') . '><span class="job-progress"><span style="width: ' . ($all ? round($done / $all * 100) : 0) . '%"></span></span>' . $done . '</span>'
        . '<span ' . tip_attr('MP3 がある動画') . '><span class="tag label-inline">MP3</span>' . $mp3 . '</span></span>';
    $label = ['saved' => '解析済み', 'running' => '解析中', 'queued' => '順番待ち', 'error' => '失敗', 'none' => '未解析', 'nomp3' => 'MP3 なし'];

    render_header('解析の状況', 'tools');
    ?>
    <h1 class="display display--sm">解析の状況</h1>
    <?php $tabNav(); ?>
    <?php // 「MP3 を紐付け」のフォーム。$folder = 紐付けたフォルダの ID（下の一覧で URL の編集・解除）。'' = 新しく紐付ける
    $ytDriveForm = static function (string $folder): void { ?>
        <form class="yt-drive" data-yt-drive="<?= h($folder) ?>">
            <input type="url" name="url" required maxlength="500" inputmode="url" autocomplete="off" placeholder="https://drive.google.com/drive/folders/…" aria-label="MP3 のフォルダのリンク"<?= $folder !== '' ? ' value="https://drive.google.com/drive/folders/' . h($folder) . '"' : '' ?>>
            <button type="submit" class="btn btn--sm"><?= $folder !== '' ? '紐付け直す' : 'MP3 を紐付け' ?></button>
            <?php if ($folder !== ''): ?><button type="button" class="btn btn--sm btn--ghost" data-yt-drive-remove>紐付けを解除</button><?php endif; ?>
            <details class="yt-drive__miss small" data-yt-drive-miss hidden><summary></summary><ul></ul></details>
        </form>
    <?php };
    $ytDriveForm('');
    ?>
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
                    <?php if (!empty($l['detect']) || array_filter($l['videos'], static fn($v) => in_array($v[3], ['none', 'error'], true))): ?>
                        <button type="button" class="btn btn--sm" data-chapter-bulk>未解析を解析</button>
                    <?php endif; ?>
                    <ul>
                    <?php foreach ($l['videos'] as [$b, $no, $count, $state, $error, $hasMp3]): ?>
                        <li<?= $state === 'nomp3' ? ' class="is-dim"' : '' ?>>
                            <a href="video_chapters?band=<?= (int)$b['band_id'] ?>&amp;v=<?= $no ?>"><?= h((string)$b['name']) ?><?= $count > 1 ? ' ' . ($no + 1) . '本目' : '' ?></a>
                            <?php if ($state === 'edited'): ?><?= check_badge('編集済み', '編集済み') ?><?php else: ?>
                            <span class="job-state" data-chapter-state="<?= $state ?>"<?= $error !== '' ? ' ' . tip_attr($error) . ' style="cursor: help"' : '' ?>><?= $label[$state] ?? '' ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </details>
            <?php endforeach; ?>
        </details>
    <?php endforeach; ?>
    </div>
    <?php $folders = $pdo->query('SELECT folder_id FROM yt_drive_folder ORDER BY created_at, folder_id')->fetchAll(PDO::FETCH_COLUMN); ?>
    <section class="yt-drive-folders" data-yt-drive-folders<?= $folders ? '' : ' hidden' ?>>
        <h2>紐付けたフォルダ</h2>
        <div data-yt-drive-list>
            <?php foreach ($folders as $f) { $ytDriveForm((string)$f); } ?>
        </div>
        <template data-yt-drive-row><?php $ytDriveForm('_'); ?></template>
    </section>
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
<h1 class="display display--sm">解析の状況</h1>
<?php $tabNav(); ?>
<?php if (!$jobs): ?>
    <div class="empty card"><p class="empty__title">検出中のバンドはありません</p></div>
<?php else: ?>
    <div class="table-scroll table-scroll--flush"><table class="table">
        <thead><tr><th>バンド</th><th>ステータス</th><th>速度</th><th>開始</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $id => $job): ?>
            <tr>
                <td><a href="songs_edit?band=<?= $id ?>"><?= h((string)($names[$id] ?? '#' . $id)) ?></a></td>
                <td><?php if ($job['state'] === 'running'): ?>
                    <span class="job-state" data-chapter-state="running">解析中 <?= (int)floor((time() - (int)$job['at']) / 60) ?>分</span>
                <?php elseif ($job['state'] === 'queued'): ?>
                    <span class="job-state" data-chapter-state="queued">順番待ち</span>
                <?php elseif ($job['state'] === 'done'): ?>
                    <span class="job-state" data-chapter-state="review">確認待ち</span>
                <?php else: ?>
                    <span class="job-state" data-chapter-state="error" <?= tip_attr(trim(($job['error'] ?? '') . ' / ' . ($job['detail'] ?? ''), ' /')) ?>>失敗</span>
                    <?php // 再試行: 通常の速度で検出し直す（assets/app_admin.js の setupDetectRetry） ?>
                    <button type="button" class="btn btn--sm btn--ghost" data-detect-retry="<?= $id ?>" data-src="<?= h((string)($job['src'] ?? '')) ?>" aria-label="再試行" title="再試行"><?= icon('refresh') ?></button>
                <?php endif; ?></td>
                <td data-detect-flex><?= isset($job['flex']) ? ($job['flex'] ? '通常' : '高速') : '' ?></td>
                <td><?= isset($job['at']) ? date('n/j H:i', (int)$job['at']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
<?php if ($seeks): ?>
    <details class="job-fold">
    <summary>曲の場面があるバンド <span class="muted small"><?= count($seeks) ?>組</span><?= icon('expand_more') ?></summary>
    <div class="table-scroll table-scroll--flush"><table class="table">
        <thead><tr><th>バンド</th><th>曲</th></tr></thead>
        <tbody>
        <?php foreach ($seeks as $b): ?>
            <tr>
                <td><a href="songs_edit?band=<?= (int)$b['band_id'] ?>"><?= h((string)$b['name']) ?></a></td>
                <td><?= (int)$b['seek'] ?> / <?= (int)$b['songs'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    </details>
<?php endif; ?>
<?php render_footer();

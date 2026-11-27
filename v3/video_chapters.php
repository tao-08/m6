<?php
/**
 * =====================================================================
 *  video_chapters.php — バンドの YouTube のチャプター編集（?band=バンドID&v=何本目。0 = 1本目）
 * =====================================================================
 *  上に動画、下に MIX と同じシークバー・「MC」「曲」「終わり」「自動で付ける」（lib/audio_player.php・assets/app_player.js）。
 *  保存は MIX と同じ band_audio_chapter（audio_key = 'y:動画ID'。api_audio_chapter.php）。シャッフル再生の区切りにも使う（api_shuffle.php）
 *  曲の番号に当てる曲は video_chapter_songs（動画が2本以上なら、その動画に割り当てた曲だけ）
 *  入口はバンド詳細の「チャプター編集」。開けるのはセットリストを編集できる人（songs_edit.php と同じ）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/youtube.php';
require_once __DIR__ . '/lib/drive.php'; // audio_player_html・audio_chapters
require_once __DIR__ . '/lib/chapter_job.php'; // 裏の解析
require_login();

$pdo = db();
$bandId = (int)($_GET['band'] ?? 0);
$st = $pdo->prepare('SELECT b.band_id, b.name, b.youtube_url, d.live_id, d.live_day_id, d.label, l.name AS live_name FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id JOIN live l ON l.live_id = d.live_id WHERE b.band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch();
if (!$band) {
    error_page(404, 'バンドが見つかりません');
}
if (band_setlist_visibility($pdo, $bandId)['hidden']) {
    error_page(403, 'セットリストは出演まで非公開です');
}
$urls = band_video_urls($pdo, [$band])[$bandId] ?? [];
$no = (int)($_GET['v'] ?? 0);
$videoId = isset($urls[$no]) ? youtube_video_id($urls[$no]) : null;
if ($videoId === null) {
    error_page(404, '動画が見つかりません');
}
$st = $pdo->prepare('SELECT title, is_se, video_no FROM song WHERE band_id = ? ORDER BY track_no');
$st->execute([$bandId]);
$rows = $st->fetchAll();
$songs = array_column(video_chapter_songs($rows, $no, count($urls)), 'title');
$ses = array_column(array_filter($rows, static fn($s) => $s['is_se'] && (count($urls) < 2 || (int)$s['video_no'] === $no)), 'title'); // SE も曲と同じく、その動画の分だけ
$key = 'y:' . $videoId;
$titles = count($urls) > 1 ? youtube_titles($pdo, $urls) : [];

render_header('チャプター編集', 'lives');
?>
<nav class="crumbs"><a href="live?id=<?= (int)$band['live_id'] ?>#day-<?= (int)$band['live_day_id'] ?>"><?= h($band['live_name']) ?> <?= h($band['label']) ?></a><span>/</span><a href="band?id=<?= $bandId ?>"><?= h($band['name']) ?></a></nav>
<h1 class="display display--sm">チャプター編集</h1>
<?php if (count($urls) > 1): ?>
    <nav class="tabs">
        <?php foreach ($urls as $i => $u): ?>
            <a class="tab<?= $i === $no ? ' is-active' : '' ?>" href="video_chapters?band=<?= $bandId ?>&amp;v=<?= $i ?>"><?= h($titles[youtube_video_id($u) ?? ''] ?? ($i + 1) . '本目') ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<section class="card">
    <?= audio_player_html('', '', null, ['band_id' => $bandId, 'key' => $key, 'chapters' => audio_chapters($pdo, $bandId)[$key] ?? [], 'songs' => $songs, 'ses' => $ses, 'job' => chapter_job_get(chapter_job_id($key))]) ?>
</section>
<?php render_footer(); ?>

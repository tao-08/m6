<?php
/**
 * =====================================================================
 *  live_youtube.php?id=ライブID — プレイリストの動画を、そのライブのバンドに割り当てる
 * =====================================================================
 *  ライブの YouTube のリンク（live.youtube_url）がプレイリストのとき、
 *  中の動画をタイトルでバンドに結び付けて（lib/youtube.php）、確認画面を出す。
 *  いきなり保存はしない（間違って上書きされても気づけないので）。人がプルダウンで確かめてから保存する。
 *
 *  選択肢の初期値:
 *    1. 今入っているリンク（前に人が選んだものを勝手に変えない）
 *    2. 迷わず決まった動画（候補が1本だけ）
 *    3. どちらも無ければ「なし」（候補が2本以上のバンドは黄色くして、人に選ばせる）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/youtube.php';
require_login();

$pdo = db();
$liveId = (int)($_GET['id'] ?? $_POST['live_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM live WHERE live_id = ?');
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    http_response_code(404);
    exit('ライブが見つかりません');
}

// このライブのバンド（日程順 → 出演順）
$st = $pdo->prepare('SELECT b.band_id, b.name, b.play_order, b.youtube_url, b.artist_id, a.name AS artist_name, d.label
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id
    WHERE d.live_id = ? ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order');
$st->execute([$liveId]);
$bands = [];
foreach ($st as $b) {
    $bands[(int)$b['band_id']] = $b;
}

// ---------- 保存 ----------
if (is_post()) {
    verify_csrf();
    $picks = is_array($_POST['v'] ?? null) ? $_POST['v'] : [];
    $update = $pdo->prepare('UPDATE band SET youtube_url = ? WHERE band_id = ?');
    $changed = 0;
    $pdo->beginTransaction();
    try {
        // 回すのは DB から取った「このライブのバンド」だけ（POST の band_id を書き換えて他のライブのバンドを変えさせない）
        foreach ($bands as $id => $b) {
            $pick = $picks[$id] ?? 'keep';
            if (!is_string($pick) || $pick === 'keep') {
                continue;
            }
            if ($pick === '') {
                $url = null;
            } elseif (youtube_video_id_valid($pick)) {
                $url = youtube_watch_url($pick); // URL はこちらで組み立てる（送られてきた文字列をそのまま保存しない）
            } else {
                continue; // 改造されたリクエスト。黙って無視する
            }
            if ($url !== $b['youtube_url']) {
                $update->execute([$url, $id]);
                $changed++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    flash($changed ? "{$changed} バンドの YouTube のリンクを更新しました" : 'YouTube のリンクは変わっていません');
    redirect('live?id=' . $liveId);
}

// ---------- プレイリストを読んで割り当てる ----------
$error = null;
$videos = [];  // video_id => タイトル
$match = ['bands' => [], 'auto' => [], 'unmatched' => []];
$playlistId = youtube_playlist_id((string)$live['youtube_url']);
if ($playlistId === null) {
    $error = 'このライブの YouTube のリンクがプレイリスト（…/playlist?list=…）ではありません。ライブを編集でプレイリストのリンクを入れてください';
} elseif (!$bands) {
    $error = 'このライブにはまだバンドが登録されていません';
} else {
    try {
        foreach (youtube_playlist_items($playlistId) as $v) {
            $videos[$v['video_id']] = $v['title'];
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

if ($error === null) {
    // タイトルの中で探す名前: バンド名（lib 側で括弧の前を使う）・アーティスト名・アーティストの別名（エルレ → ELLEGARDEN など）
    $st = $pdo->prepare('SELECT DISTINCT al.artist_id, al.name FROM artist_alias al
        JOIN band b ON b.artist_id = al.artist_id
        JOIN live_day d ON d.live_day_id = b.live_day_id
        WHERE d.live_id = ?');
    $st->execute([$liveId]);
    $aliases = [];
    foreach ($st as $r) {
        $aliases[(int)$r['artist_id']][] = $r['name'];
    }
    $input = [];
    foreach ($bands as $id => $b) {
        $names = $b['artist_id'] ? [(string)$b['artist_name'], ...($aliases[(int)$b['artist_id']] ?? [])] : [];
        $input[$id] = ['name' => $b['name'], 'names' => $names];
    }
    $list = [];
    foreach ($videos as $id => $title) {
        $list[] = ['video_id' => $id, 'title' => $title];
    }
    $match = youtube_match_bands($list, $input);
}

// バンドごとの初期値と状態を決める
$rows = [];
$used = []; // 初期値でどこかのバンドに入った動画
foreach ($bands as $id => $b) {
    $current = $b['youtube_url'] !== null ? youtube_video_id($b['youtube_url']) : null;
    $candidates = $match['bands'][$id] ?? [];
    if ($b['youtube_url'] !== null && $b['youtube_url'] !== '') {
        // 今のリンクを優先。プレイリストの動画ならその動画を選んだ状態、違えば「今のまま」
        $selected = $current !== null && isset($videos[$current]) ? $current : 'keep';
        $status = 'current';
    } elseif (isset($match['auto'][$id])) {
        $selected = $match['auto'][$id];
        $status = 'auto';
    } else {
        $selected = '';
        $status = $candidates ? 'pick' : 'none';
    }
    if (isset($videos[$selected])) {
        $used[$selected] = true;
    }
    $rows[$id] = ['band' => $b, 'candidates' => $candidates, 'selected' => $selected, 'status' => $status];
}
$leftover = array_diff_key($videos, $used); // どのバンドにも入っていない動画（確認用に下に並べる）
$autoCount = count(array_filter($rows, fn($r) => $r['status'] === 'auto'));
$pickCount = count(array_filter($rows, fn($r) => $r['status'] === 'pick'));

const YT_STATUS = [
    'current' => ['今のまま', 'pill'],
    'auto'    => ['自動で選択', 'pill pill--ok'],
    'pick'    => ['候補が複数。選んでください', 'pill pill--warn'],
    'none'    => ['見つからない', 'pill muted'],
];

render_header('YouTube の動画を割り当て', 'lives'); ?>
<nav class="crumbs"><a href="live?id=<?= $liveId ?>"><?= h($live['name']) ?></a><span>/</span><a href="live_edit?id=<?= $liveId ?>">編集</a><span>/</span>YouTube</nav>
<h1 class="display display--sm">YouTube の動画をバンドに割り当て</h1>

<?php if ($error !== null): ?>
    <div class="flash flash--error"><?= h($error) ?></div>
    <p><a class="btn btn--ghost" href="live_edit?id=<?= $liveId ?>">ライブを編集に戻る</a></p>
<?php else: ?>
    <div class="flash flash--info">
        プレイリストの動画 <?= count($videos) ?> 本を読み込み、<?= $autoCount ?> バンドに自動で割り当てました。
        <?php if ($pickCount): ?><strong><?= $pickCount ?> バンドは候補が複数あるので選んでください。</strong><?php endif; ?>
        確認してから「保存する」を押してください。
    </div>

    <form method="post" class="card form-card" data-yt-form>
        <?= csrf_field() ?>
        <input type="hidden" name="live_id" value="<?= $liveId ?>">
        <div class="table-scroll table-scroll--yt">
            <table class="table table--yt">
                <thead><tr><th>日程</th><th>順</th><th>バンド名</th><th>動画</th><th>状態</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $id => $r): $b = $r['band']; [$statusText, $statusClass] = YT_STATUS[$r['status']]; ?>
                    <tr<?= $r['status'] === 'pick' ? ' class="is-warn"' : '' ?>>
                        <td class="muted"><?= h($b['label']) ?></td>
                        <td class="num"><?= (int)$b['play_order'] ?></td>
                        <td><?= h($b['name']) ?></td>
                        <td>
                            <select name="v[<?= $id ?>]" aria-label="「<?= h($b['name']) ?>」の動画">
                                <option value="">なし</option>
                                <?php if ($r['selected'] === 'keep'): ?>
                                    <option value="keep" selected>今のリンクのまま（<?= h($b['youtube_url']) ?>）</option>
                                <?php endif; ?>
                                <?php if ($r['candidates']): ?>
                                    <optgroup label="候補">
                                        <?php foreach ($r['candidates'] as $vid): ?>
                                            <option value="<?= h($vid) ?>"<?= $vid === $r['selected'] ? ' selected' : '' ?>><?= h($videos[$vid]) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <optgroup label="プレイリストのほかの動画">
                                    <?php foreach ($videos as $vid => $title): if (in_array($vid, $r['candidates'], true)) continue; ?>
                                        <option value="<?= h($vid) ?>"<?= $vid === $r['selected'] ? ' selected' : '' ?>><?= h($title) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </td>
                        <td><span class="<?= $statusClass ?>"><?= h($statusText) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- どのバンドにも選ばれていない動画。全部の動画を出しておき、選ばれているものは hidden にする
             プルダウンを変えるたびに JS（assets/app.js の setupYoutubeLeftover）が出し入れ・本数を数え直す -->
        <section data-yt-leftover<?= $leftover ? '' : ' hidden' ?>>
            <h2 class="section-title">どのバンドにも入っていない動画（<span data-yt-left-count><?= count($leftover) ?></span> 本）</h2>
            <p class="muted">略称のタイトル（例: エルレ）は、アーティストのページで別名を登録すると次から自動で当たります。</p>
            <ul class="yt-leftover">
                <?php foreach ($videos as $vid => $title): ?>
                    <li data-yt-video="<?= h($vid) ?>"<?= isset($leftover[$vid]) ? '' : ' hidden' ?>><a href="<?= h(youtube_watch_url($vid)) ?>" target="_blank" rel="noopener noreferrer"><?= h($title) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <div class="form-actions">
            <a class="btn btn--ghost" href="live?id=<?= $liveId ?>">キャンセル</a>
            <button class="btn btn--primary" type="submit">保存する</button>
        </div>
    </form>
<?php endif; ?>
<?php render_footer();

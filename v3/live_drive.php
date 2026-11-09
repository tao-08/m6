<?php
/**
 * =====================================================================
 *  live_drive.php?id=ライブID — ドライブのフォルダのファイルを、そのライブのバンドに割り当てる
 * =====================================================================
 *  live_youtube.php（プレイリスト → バンド）のドライブ版。流れは同じで、違いは「1バンドに何個でも」なこと。
 *    1. ライブのフォルダのリンク（live.drive_url）からフォルダ ID を取り出す
 *    2. フォルダの中のファイルを、サブフォルダの中まで全部取ってくる（lib/drive.php の drive_folder_tree）
 *         12月ライブ/スピッツ.wav       … 直下のファイル
 *         12月ライブ/写真/スピッツ.jpg  … サブフォルダの中のファイル
 *    3. 「フォルダ名 ＋ ファイル名」にバンド名が入っているかで結び付ける（drive_match_files）
 *    4. 確認画面を出す。バンドごとに候補のファイルをチェックボックスで並べ、人が確かめてから保存
 *         （いきなり保存しない。間違って上書きされても気づけないので）
 *
 *  チェックの初期値:
 *    ・すでに紐付けてあるファイル → チェックあり（前に人が選んだものを勝手に外さない）
 *    ・そのバンドにだけ当たったファイル → チェックあり（自動）
 *    ・2組以上のバンドに当たったファイル → チェックなし（行を黄色くして、人に選ばせる）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/drive.php';
require_once __DIR__ . '/lib/history.php'; // 変更履歴（元に戻すための「変更前の状態」を残す）
require_login();

$pdo = db();
// (int) で型をそろえる。数字以外が来ても 0 になり、下の SELECT で「見つからない」になる
$liveId = (int)($_GET['id'] ?? $_POST['live_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM live WHERE live_id = ?'); // プリペアドステートメント（SQL インジェクション対策）
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    error_page(404, 'ライブが見つかりません');
}

// このライブのバンド（日程順 → 出演順）。キーを band_id にしておくと、あとで $bands[$id] で引ける
$st = $pdo->prepare('SELECT b.band_id, b.name, b.play_order, b.artist_id, a.name AS artist_name, d.label
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id
    WHERE d.live_id = ? ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order');
$st->execute([$liveId]);
$bands = [];
foreach ($st as $b) {
    $bands[(int)$b['band_id']] = $b;
}
$current = drive_load_files($pdo, array_keys($bands)); // band_id => 紐付け済みのファイル

// ---------- フォルダを読む（表示でも保存でも使う） ----------
$error = null;
$files = []; // file_id => ['file_id', 'name', 'mime_type', 'folder']
$folderId = drive_folder_id((string)$live['drive_url']);
if ($folderId === null) {
    $error = 'このライブにドライブのフォルダのリンク（…/drive/folders/…）が入っていません。ライブを編集で追加してください';
} elseif (!$bands) {
    $error = 'このライブにはまだバンドが登録されていません';
} else {
    try {
        foreach (drive_folder_tree($folderId) as $f) {
            $files[$f['file_id']] = $f;
        }
        if (!$files) {
            $error = 'フォルダにファイルがありません';
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

// ---------- 保存 ----------
if (is_post()) {
    verify_csrf(); // CSRF 対策: フォームに埋めた合言葉（トークン）が合っているか
    if ($error !== null) {
        flash($error, 'error');
        redirect('live_drive?id=' . $liveId);
    }
    // f[band_id][] = チェックしたファイル ID。チェックが1つも無いバンドは送られてこないので、
    // 画面に出したバンドは shown[] で別に送ってもらう（「全部外した」と「画面に無かった」を区別するため）
    $picks = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    $shown = array_map('intval', (array)($_POST['shown'] ?? []));
    $changed = 0;
    $op = null; // 変更履歴。実際に変わるバンドがあったときだけ作る
    // トランザクション: 全部成功したら確定（commit）、途中で失敗したら全部取り消し（rollBack）
    //   ドライブとの通信は上で済ませてある（トランザクション中に通信で待つと、ほかの人の保存を待たせるため）
    $pdo->beginTransaction();
    try {
        // 回すのは DB から取った「このライブのバンド」だけ（POST の band_id を書き換えて他のライブのバンドを変えさせない）
        foreach ($bands as $id => $b) {
            if (!in_array($id, $shown, true)) {
                continue;
            }
            // 受け付けるのは「フォルダにあるファイル」か「もう紐付けてあるファイル」だけ（ID を書き換えて他人のファイルを入れさせない）
            $mine = array_column($current[$id] ?? [], null, 'file_id'); // array_column(…, null, キー): 行をまるごと file_id をキーにして並べ直す
            $next = [];
            foreach ((array)($picks[$id] ?? []) as $fid) {
                $fid = (string)$fid;
                $row = $files[$fid] ?? $mine[$fid] ?? null;
                if ($row !== null && !isset($next[$fid])) {
                    $next[$fid] = $row;
                }
            }
            // 変わらないなら何もしない（file_id と名前で比べる。順番だけの違いは無視）
            $key = static fn(array $rows) => array_map(fn($f) => $f['file_id'] . '|' . $f['name'], $rows);
            $before = $key($current[$id] ?? []);
            $after = $key(array_values($next));
            sort($before);
            sort($after);
            if ($before === $after) {
                continue;
            }
            $op ??= change_begin($pdo, 'drive', 'バンドのドライブのファイル', $liveId); // ??= は「null のときだけ代入」
            change_capture($pdo, $op, 'band', $id); // 変更前の状態（紐付けていたファイルも）を履歴に残す
            // 並び: フォルダ → ファイル名の順（usort: 自分で決めた比べ方で並べ替える。<=> は大小を -1/0/1 で返す）
            $rows = array_values($next);
            usort($rows, fn($x, $y) => [$x['folder'] ?? '', $x['name']] <=> [$y['folder'] ?? '', $y['name']]);
            drive_save_files($pdo, $id, $rows);
            $changed++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    flash($changed ? "{$changed} バンドのファイルを更新しました" : 'ファイルは変更なし');
    redirect('live?id=' . $liveId);
}

// ---------- 割り当てる ----------
$match = ['bands' => [], 'unmatched' => []];
$owners = []; // file_id => 当たったバンドの数（2以上なら自動でチェックしない）
if ($error === null) {
    // 名前の中で探す名前: バンド名（lib 側で括弧の前を使う）・アーティスト名・アーティストの別称（エルレ → ELLEGARDEN など）
    //   live_youtube.php と同じ。別称は全バンド分を1回の SQL で取る（バンドごとに SQL を投げない = N+1 問題の回避）
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
        // ...（スプレッド構文）で別称の配列を展開して、アーティスト名の後ろに並べる
        $names = $b['artist_id'] ? [(string)$b['artist_name'], ...($aliases[(int)$b['artist_id']] ?? [])] : [];
        $input[$id] = ['name' => $b['name'], 'names' => $names];
    }
    $match = drive_match_files(array_values($files), $input);
    foreach ($match['bands'] as $ids) {
        foreach ($ids as $fid) {
            $owners[$fid] = ($owners[$fid] ?? 0) + 1;
        }
    }
}

// バンドごとに「並べるファイル」と「チェックの初期値」を決める
$rows = [];
foreach ($bands as $id => $b) {
    $list = [];   // file_id => ['file' => 行, 'checked' => bool, 'shared' => 2組以上に当たったか]
    foreach ($current[$id] ?? [] as $f) { // 紐付け済み（フォルダに無いものも出して、外せるようにする）
        $list[$f['file_id']] = ['file' => $files[$f['file_id']] ?? $f, 'checked' => true, 'shared' => false];
    }
    foreach ($match['bands'][$id] ?? [] as $fid) {
        $shared = ($owners[$fid] ?? 0) > 1;
        $list[$fid] ??= ['file' => $files[$fid], 'checked' => !$shared, 'shared' => $shared];
    }
    $hasShared = (bool)array_filter($list, fn($x) => $x['shared'] && !$x['checked']);
    $rows[$id] = ['band' => $b, 'list' => $list, 'warn' => $hasShared];
}
$unmatched = array_intersect_key($files, array_flip($match['unmatched'])); // どのバンドにも当たらなかったファイル
$autoCount = count(array_filter($rows, fn($r) => $r['list'] && !isset($current[(int)$r['band']['band_id']])));
$warnCount = count(array_filter($rows, fn($r) => $r['warn']));

/** チェックボックス1つ（既存の .pick-chip。種類のアイコン（色付き）＋ フォルダ名/ファイル名） */
$fileChip = static function (int $bandId, array $f, bool $checked): string {
    $label = (($f['folder'] ?? '') !== '' ? $f['folder'] . '/' : '') . drive_strip_ext((string)$f['name']);
    return '<label class="pick-chip pick-chip--who"><input type="checkbox" name="f[' . $bandId . '][]" value="' . h($f['file_id']) . '"'
        . ($checked ? ' checked' : '') . '>' . drive_type_icon((string)$f['mime_type']) . h($label) . '</label>';
};

render_header('ドライブのファイルを割り当て', 'lives'); ?>
<nav class="crumbs"><a href="live?id=<?= $liveId ?>"><?= h($live['name']) ?></a><span>/</span><a href="live_edit?id=<?= $liveId ?>">編集</a><span>/</span>ドライブ</nav>
<h1 class="display display--sm">ドライブのファイルをバンドに割り当て</h1>

<?php if ($error !== null): ?>
    <div class="flash flash--error"><?= h($error) ?></div>
    <p><a class="btn btn--ghost" href="live_edit?id=<?= $liveId ?>">ライブを編集に戻る</a></p>
<?php else: ?>
    <div class="flash flash--info">
        <?= count($files) ?> ファイルを読み込み、<?= $autoCount ?> バンドに自動で割り当て。
        <?php if ($warnCount): ?><strong><?= $warnCount ?> バンドは複数のバンドに当たるファイルがあるので選択してください。</strong><?php endif; ?>
    </div>

    <form method="post" class="card form-card"<?= unsaved_attr() ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="live_id" value="<?= $liveId ?>">
        <div class="table-scroll table-scroll--yt">
            <table class="table table--yt">
                <thead><tr><th>日程</th><th>順</th><th>バンド名</th><th>ファイル</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $id => $r): $b = $r['band']; ?>
                    <tr<?= $r['warn'] ? ' class="is-warn"' : '' ?>>
                        <td class="muted"><?= h($b['label']) ?></td>
                        <td class="num"><?= (int)$b['play_order'] ?></td>
                        <td><?= h($b['name']) ?><input type="hidden" name="shown[]" value="<?= $id ?>"></td>
                        <td>
                            <?php if ($r['list']): ?>
                                <div class="drive-picks">
                                    <?php foreach ($r['list'] as $x): ?><?= $fileChip($id, $x['file'], $x['checked']) ?><?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="pill muted">該当なし</span>
                            <?php endif; ?>
                            <?php // 手動で選ぶ: 候補に出なかったファイルも、フォルダの全部から自由にチェックできる（最初は閉じておく）
                            //   <details> は HTML だけで開け閉めできる部品（JS が要らない）。閉じていても中のチェックは送信される
                            $others = array_diff_key($files, $r['list']); ?>
                            <?php if ($others): ?>
                                <details class="drive-more">
                                    <summary class="btn btn--ghost btn--sm"><?= icon('add') ?> ほかのファイル（<?= count($others) ?>）</summary>
                                    <div class="drive-picks">
                                        <?php foreach ($others as $f): ?><?= $fileChip($id, $f, false) ?><?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($unmatched): ?>
            <!-- どのバンドにも当たらなかったファイル（自動で当たらなかっただけ。手動でチェックした分はここにも残る）。
                 ファイル名を直すか、アーティストの別称を登録すると次から自動で当たる -->
            <section>
                <h2 class="section-title">未割り当てのファイル（<?= count($unmatched) ?>）</h2>
                <p class="muted small">各バンドの「＋ ほかのファイル」から手動で割り当てできます。</p>
                <ul class="yt-leftover">
                    <?php foreach ($unmatched as $f): ?>
                        <li><a href="<?= h(drive_file_url($f['file_id'])) ?>" target="_blank" rel="noopener noreferrer"><?= drive_type_icon((string)$f['mime_type']) ?><?= h(($f['folder'] !== '' ? $f['folder'] . '/' : '') . $f['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <div class="form-actions">
            <a class="btn btn--ghost" href="live?id=<?= $liveId ?>">キャンセル</a>
            <button class="btn btn--primary" type="submit">保存する</button>
        </div>
    </form>
<?php endif; ?>
<?php render_footer();

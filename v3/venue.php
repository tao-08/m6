<?php
/**
 * =====================================================================
 *  venue.php?id= — 会場の詳細（venue_list.php から入る）
 * =====================================================================
 *  表示: サイト・住所・最寄り駅（全部）・機材（区分ごと）・アクセスマップ・ここで演奏したライブ
 *  編集: ログインしている部員なら誰でも（サイトの URL・住所・駅・機材）。会場名の変更は masters.php（管理者）
 *
 *  POST は4種類（action）。どれも CSRF 検証 → 入力検証 → プリペアドステートメント → リダイレクト（PRG）。
 *    save_info … website_url / address
 *    save_images … 画像（7枚まで。削除にチェックした分を引いて、選んだ分を足す。lib/attach_image.php の kind = venue）
 *    save_stations / save_equipment … 行ごとの入力欄をまとめて保存。名前（機種名）を空にした行は削除、id=0 の行は追加
 *  行の id は必ず「この会場の行か」を確かめ、WHERE にも venue_id を入れる（他の会場の行を書き換えさせない）
 *
 *  変更履歴（lib/history.php）には残していない: あれは live / 日程 / バンドなどの「元に戻す」用スナップショットで、
 *  会場の子テーブル（駅・機材）は対象外のため。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/attach_image.php';
require_login();
$pdo = db();

/** 機材の区分（この順に表示する。DB には文字列で入れるので、ここにある名前以外は受け付けない） */
const VENUE_EQUIP_CATEGORIES = ['ギターアンプ', 'ベースアンプ', 'ドラム', 'キーボード', 'PA', 'その他'];

$venueId = (int)($_GET['id'] ?? $_POST['venue_id'] ?? 0);
$st = $pdo->prepare('SELECT venue_id, name, address, website_url FROM venue WHERE venue_id = ?');
$st->execute([$venueId]);
$venue = $st->fetch();
if (!$venue) {
    error_page(404, '会場が見つかりません');
}
$self = 'venue?id=' . $venueId;

// =====================================================================
//  POST
// =====================================================================
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_info') {
        $url = trim((string)($_POST['website_url'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        // href に入れるので http(s) だけ許す（javascript: などで XSS させない）
        if ($url !== '' && (mb_strlen($url) > 255 || !preg_match('#^https?://[^\s<>"]+$#i', $url))) {
            flash('サイトの URL が正しくありません', 'error');
        } elseif (mb_strlen($address) > 255) {
            flash('住所が長すぎます', 'error');
        } else {
            $pdo->prepare('UPDATE venue SET website_url = ?, address = ? WHERE venue_id = ?')
                ->execute([$url === '' ? null : $url, $address === '' ? null : $address, $venueId]);
            flash('保存しました');
        }
        redirect($self);
    }

    if ($action === 'save_images') {
        $images = attach_images($pdo, 'venue', [$venueId])[$venueId] ?? [];
        // 削除にチェックされた画像は、この会場のものだけ有効
        $removeIds = array_values(array_intersect(array_map('intval', array_column($images, 'image_id')), array_map('intval', (array)($_POST['image_remove'] ?? []))));
        $uploads = live_image_uploads($_FILES['images'] ?? null);
        $max = ATTACH_KINDS['venue']['max'];
        if (count($images) - count($removeIds) + count($uploads) > $max) {
            flash('画像は ' . $max . ' 枚までです', 'error');
            redirect($self);
        }
        $newFiles = [];
        $removeFiles = [];
        try {
            $pdo->beginTransaction();
            $removeFiles = attach_remove($pdo, 'venue', $venueId, $removeIds);
            attach_add($pdo, 'venue', $venueId, $uploads, null, $newFiles);
            $pdo->commit();
        } catch (RuntimeException $e) { // 画像のエラー（形式・大きさなど）。取り消して理由を出す
            $pdo->rollBack();
            array_map('live_image_unlink', $newFiles);
            flash($e->getMessage(), 'error');
            redirect($self);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            array_map('live_image_unlink', $newFiles);
            throw $e;
        }
        array_map('live_image_unlink', $removeFiles); // コミットのあとにファイルを消す
        flash('保存しました');
        redirect($self);
    }

    if ($action === 'save_stations') {
        $ids = (array)($_POST['id'] ?? []);
        $names = (array)($_POST['name'] ?? []);
        $mins = (array)($_POST['minutes'] ?? []);
        $own = $pdo->prepare('SELECT venue_station_id FROM venue_station WHERE venue_id = ?');
        $own->execute([$venueId]);
        $own = array_map('intval', $own->fetchAll(PDO::FETCH_COLUMN));
        $rows = [];
        $ok = true;
        foreach ($ids as $i => $rawId) {
            $id = (int)$rawId;
            if ($id !== 0 && !in_array($id, $own, true)) {
                continue; // 他の会場の行は触らない
            }
            $name = preg_replace('/駅$/u', '', trim((string)($names[$i] ?? ''))); // 「駅」は画面で付けるので保存しない
            $m = trim((string)($mins[$i] ?? ''));
            if ($name === '') {
                $rows[] = [$id, null, 0]; // 空 = 削除（新規行なら何もしない）
                continue;
            }
            if (mb_strlen($name) > 50 || !ctype_digit($m) || (int)$m > 999) {
                $ok = false;
                break;
            }
            $rows[] = [$id, $name, (int)$m];
        }
        if (!$ok) {
            flash('駅名または徒歩の分数が正しくありません', 'error');
            redirect($self);
        }
        $pdo->beginTransaction();
        try {
            $order = 0;
            foreach ($rows as [$id, $name, $m]) {
                if ($id !== 0 && $name === null) {
                    $pdo->prepare('DELETE FROM venue_station WHERE venue_station_id = ? AND venue_id = ?')->execute([$id, $venueId]);
                } elseif ($id !== 0) {
                    $pdo->prepare('UPDATE venue_station SET station_name = ?, walk_minutes = ?, sort_order = ? WHERE venue_station_id = ? AND venue_id = ?')
                        ->execute([$name, $m, ++$order, $id, $venueId]);
                } elseif ($name !== null) {
                    $pdo->prepare('INSERT INTO venue_station (venue_id, station_name, walk_minutes, sort_order) VALUES (?, ?, ?, ?)')
                        ->execute([$venueId, $name, $m, ++$order]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('保存しました');
        redirect($self);
    }

    if ($action === 'save_equipment') {
        $ids = (array)($_POST['id'] ?? []);
        $cats = (array)($_POST['category'] ?? []);
        $models = (array)($_POST['model'] ?? []);
        $qtys = (array)($_POST['quantity'] ?? []);
        $own = $pdo->prepare('SELECT venue_equipment_id FROM venue_equipment WHERE venue_id = ?');
        $own->execute([$venueId]);
        $own = array_map('intval', $own->fetchAll(PDO::FETCH_COLUMN));
        $rows = [];
        $ok = true;
        foreach ($ids as $i => $rawId) {
            $id = (int)$rawId;
            if ($id !== 0 && !in_array($id, $own, true)) {
                continue;
            }
            $cat = (string)($cats[$i] ?? '');
            $model = trim((string)($models[$i] ?? ''));
            $q = trim((string)($qtys[$i] ?? ''));
            if ($model === '') {
                $rows[] = [$id, '', null, 0];
                continue;
            }
            if (!in_array($cat, VENUE_EQUIP_CATEGORIES, true) || mb_strlen($model) > 100 || !ctype_digit($q) || (int)$q < 1 || (int)$q > 99) {
                $ok = false;
                break;
            }
            $rows[] = [$id, $cat, $model, (int)$q];
        }
        if (!$ok) {
            flash('区分・機種名・台数が正しくありません', 'error');
            redirect($self);
        }
        $pdo->beginTransaction();
        try {
            $order = 0;
            foreach ($rows as [$id, $cat, $model, $q]) {
                if ($id !== 0 && $model === null) {
                    $pdo->prepare('DELETE FROM venue_equipment WHERE venue_equipment_id = ? AND venue_id = ?')->execute([$id, $venueId]);
                } elseif ($id !== 0) {
                    $pdo->prepare('UPDATE venue_equipment SET category = ?, model_name = ?, quantity = ?, sort_order = ? WHERE venue_equipment_id = ? AND venue_id = ?')
                        ->execute([$cat, $model, $q, ++$order, $id, $venueId]);
                } elseif ($model !== null) {
                    $pdo->prepare('INSERT INTO venue_equipment (venue_id, category, model_name, quantity, sort_order) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$venueId, $cat, $model, $q, ++$order]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('保存しました');
        redirect($self);
    }

    error_page(400, '不正なリクエストです');
}

// =====================================================================
//  表示用のデータ
// =====================================================================
$st = $pdo->prepare('SELECT venue_station_id, station_name, walk_minutes FROM venue_station
    WHERE venue_id = ? ORDER BY walk_minutes, sort_order, venue_station_id');
$st->execute([$venueId]);
$stations = $st->fetchAll();

$st = $pdo->prepare('SELECT venue_equipment_id, category, model_name, quantity FROM venue_equipment
    WHERE venue_id = ? ORDER BY sort_order, venue_equipment_id');
$st->execute([$venueId]);
$equipment = $st->fetchAll();
$equipByCat = [];
foreach ($equipment as $e) {
    $equipByCat[$e['category']][] = $e;
}

$images = attach_images($pdo, 'venue', [$venueId])[$venueId] ?? [];

// ここで演奏したライブ（新しい順。開催日が NULL の日程は最後）
$st = $pdo->prepare('SELECT d.live_day_id, d.label, d.held_on, l.live_id, l.fiscal_year, l.name AS live_name
    FROM live_day d JOIN live l ON l.live_id = d.live_id
    WHERE d.venue_id = ?
    ORDER BY d.held_on IS NULL, d.held_on DESC, d.live_day_id DESC');
$st->execute([$venueId]);
$days = $st->fetchAll();

// 各日程のバンドは1回の SQL でまとめて取る（live.php と同じ play_order 順）
$bandsByDay = [];
if ($days) {
    $in = implode(',', array_fill(0, count($days), '?'));
    $st = $pdo->prepare("SELECT live_day_id, band_id, name FROM band WHERE live_day_id IN ($in) ORDER BY play_order");
    $st->execute(array_column($days, 'live_day_id'));
    foreach ($st as $b) {
        $bandsByDay[$b['live_day_id']][] = $b;
    }
}

// マップの検索語: 住所があれば住所、無ければ会場名
$mapQuery = (string)($venue['address'] ?: $venue['name']);

render_header($venue['name'], 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/venue.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a> / <a href="venue_list">会場リスト</a></nav>
<h1 class="display display--sm"><?= h($venue['name']) ?></h1>

<!-- 基本情報 -->
<section class="card venue-section">
    <p class="venue-info">
        <span><?= icon('language') ?>
            <?php if ($venue['website_url']): ?><a href="<?= h($venue['website_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($venue['website_url']) ?></a><?php else: ?><span class="muted">-</span><?php endif; ?></span>
        <span><?= icon('location_on') ?> <?= $venue['address'] ? h($venue['address']) : '<span class="muted">-</span>' ?></span>
        <?php foreach ($stations as $s): ?>
            <span><?= icon('train') ?> <?= h($s['station_name']) ?>駅 徒歩<?= (int)$s['walk_minutes'] ?>分</span>
        <?php endforeach; ?>
    </p>
    <details class="venue-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="venue-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_info">
            <input type="hidden" name="venue_id" value="<?= $venueId ?>">
            <div class="venue-edit__row">
                <input type="url" class="grow" name="website_url" maxlength="255" value="<?= h($venue['website_url']) ?>" placeholder="https://" aria-label="サイト">
            </div>
            <div class="venue-edit__row">
                <input type="text" class="grow" name="address" maxlength="255" value="<?= h($venue['address']) ?>" placeholder="住所" aria-label="住所">
            </div>
            <div><button type="submit" class="btn btn--primary btn--sm"><?= icon('save') ?> 保存</button></div>
        </form>
        <form method="post" class="venue-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_stations">
            <input type="hidden" name="venue_id" value="<?= $venueId ?>">
            <?php foreach (array_merge($stations, [['venue_station_id' => 0, 'station_name' => '', 'walk_minutes' => '']]) as $s): ?>
                <div class="venue-edit__row">
                    <input type="hidden" name="id[]" value="<?= (int)$s['venue_station_id'] ?>">
                    <input type="text" class="grow" name="name[]" maxlength="50" value="<?= h($s['station_name']) ?>" placeholder="駅名" aria-label="駅名">
                    <input type="number" name="minutes[]" min="0" max="999" value="<?= h($s['walk_minutes']) ?>" placeholder="徒歩(分)" aria-label="徒歩の分数">
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="駅名を空にした行は削除"><?= icon('save') ?> 駅を保存</button></div>
        </form>
    </details>
</section>

<!-- 画像（7枚まで） -->
<section class="card venue-section">
    <h2><?= icon('photo_library') ?> 画像 <span class="muted small"><?= count($images) ?>/<?= ATTACH_KINDS['venue']['max'] ?></span></h2>
    <?php if (!$images): ?><p class="muted small">-</p><?php endif; ?>
    <?= attach_thumbs_html('venue', $images) ?>
    <details class="venue-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" enctype="multipart/form-data" class="venue-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_images">
            <input type="hidden" name="venue_id" value="<?= $venueId ?>">
            <?= attach_field_html('venue', $images) ?>
            <div><button type="submit" class="btn btn--primary btn--sm"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
</section>

<!-- 機材 -->
<section class="card venue-section">
    <h2><?= icon('speaker') ?> 機材</h2>
    <?php if (!$equipment): ?><p class="muted small">-</p><?php endif; ?>
    <?php foreach (VENUE_EQUIP_CATEGORIES as $cat): if (empty($equipByCat[$cat])) continue; ?>
        <div>
            <h3 class="venue-equip__cat"><?= h($cat) ?></h3>
            <ul class="venue-equip__list">
                <?php foreach ($equipByCat[$cat] as $e): ?>
                    <li><?= h($e['model_name']) ?> <span class="muted">× <?= (int)$e['quantity'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
    <details class="venue-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="venue-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_equipment">
            <input type="hidden" name="venue_id" value="<?= $venueId ?>">
            <?php foreach (array_merge($equipment, [['venue_equipment_id' => 0, 'category' => '', 'model_name' => '', 'quantity' => 1]]) as $e): ?>
                <div class="venue-edit__row">
                    <input type="hidden" name="id[]" value="<?= (int)$e['venue_equipment_id'] ?>">
                    <select name="category[]" aria-label="区分">
                        <?php foreach (VENUE_EQUIP_CATEGORIES as $cat): ?>
                            <option value="<?= h($cat) ?>"<?= $cat === $e['category'] ? ' selected' : '' ?>><?= h($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="grow" name="model[]" maxlength="100" value="<?= h($e['model_name']) ?>" placeholder="機種名" aria-label="機種名">
                    <input type="number" name="quantity[]" min="1" max="99" value="<?= (int)$e['quantity'] ?>" aria-label="台数">
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="機種名を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
</section>

<!-- アクセスマップ（API キー不要の埋め込み。CSP に frame-src / default-src は無いので追加設定は不要） -->
<section class="card venue-section">
    <h2><?= icon('map') ?> アクセス</h2>
    <iframe class="venue-map" loading="lazy" referrerpolicy="no-referrer" title="アクセスマップ"
        src="https://maps.google.com/maps?q=<?= h(rawurlencode($mapQuery)) ?>&amp;output=embed"></iframe>
</section>

<!-- 演奏したライブ -->
<section class="card venue-section">
    <h2><?= icon('event') ?> ライブ <span class="muted small"><?= count($days) ?></span></h2>
    <?php if (!$days): ?><p class="muted small">-</p><?php else: ?>
    <ul class="venue-lives">
        <?php foreach ($days as $d): $bands = $bandsByDay[$d['live_day_id']] ?? []; ?>
            <li>
                <details>
                    <summary>
                        <a href="live?id=<?= (int)$d['live_id'] ?>"><?= (int)$d['fiscal_year'] ?>年度 <?= h($d['live_name']) ?></a>
                        <span><?= h($d['label']) ?></span>
                        <span class="muted small"><?= h(fmt_date($d['held_on'])) ?></span>
                    </summary>
                    <?php if ($bands): ?>
                        <ol>
                            <?php foreach ($bands as $b): ?>
                                <li><a href="band?id=<?= (int)$b['band_id'] ?>"><?= h($b['name']) ?></a></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?><p class="muted small">-</p><?php endif; ?>
                </details>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php render_footer();

<?php
/**
 * =====================================================================
 *  sched_settings.php — 日程調整の設定（幹部・管理者）
 * =====================================================================
 *  ほぼ固定で運用する値。年度替わり（学期の日付）と、スタジオが増えたときに触る想定
 *    periods  … 時限の時刻
 *    terms    … 学期の授業期間・休業期間（時間割が効く日）
 *    jo       … 1人あたりの帖数の目安（部屋の定員が未登録のときの判定）
 *    studios  … 日程調整の初期候補・部屋の定員
 *    travel   … 移動時間の上書き（ふだんは住所の座標から自動）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // tip_attr（ポップアップの説明）
require_once __DIR__ . '/lib/sched.php';
$user = require_executive();
$pdo = db();
$settings = sched_settings($pdo);
$uid = (int)$user['user_id'];

/** "9:00" → 540（読めなければ null） */
$toMin = static function (string $v): ?int {
    return preg_match('/^(\d{1,2}):(\d{2})$/', trim($v), $m) && (int)$m[1] <= 24 && (int)$m[2] < 60 ? (int)$m[1] * 60 + (int)$m[2] : null;
};
$isDate = static fn(string $d): bool => (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'periods') {
        $out = [];
        for ($p = 1; $p <= 6; $p++) {
            $s = $toMin((string)($_POST['ps'][$p] ?? ''));
            $e = $toMin((string)($_POST['pe'][$p] ?? ''));
            if ($s === null || $e === null || $e <= $s) {
                flash($p . '限の時刻が正しくありません', 'error');
                redirect('sched_settings#periods');
            }
            $out[] = [$s, $e];
        }
        sched_setting_save($pdo, 'sched_periods', json_encode($out), $uid);
        flash('時限を保存しました');
        redirect('sched_settings#periods');
    }

    if ($action === 'terms') {
        $terms = [];
        foreach ([1, 2] as $t) {
            $f = (string)($_POST['tf'][$t] ?? '');
            $to = (string)($_POST['tt'][$t] ?? '');
            if (!$isDate($f) || !$isDate($to) || $to < $f) {
                flash(SCHED_TERMS[$t] . 'の期間が正しくありません', 'error');
                redirect('sched_settings#terms');
            }
            $terms[$t] = [$f, $to];
        }
        $breaks = [];
        foreach ((array)($_POST['bf'] ?? []) as $i => $f) {
            $to = (string)($_POST['bt'][$i] ?? '');
            if ($isDate((string)$f) && $isDate($to) && $to >= $f) {
                $breaks[] = [(string)$f, $to];
            }
        }
        $json = json_encode(['terms' => $terms, 'breaks' => array_slice($breaks, 0, 4)]);
        sched_setting_save($pdo, 'sched_terms', $json, $uid); // 255文字に収まる（日付 10文字 × 12 ＋ 記号）
        flash('学期を保存しました');
        redirect('sched_settings#terms');
    }

    if ($action === 'jo') {
        $jo = (float)($_POST['jo'] ?? 0);
        if ($jo < 0.5 || $jo > 20) {
            flash('0.5〜20 の数で入力してください', 'error');
            redirect('sched_settings#studios');
        }
        sched_setting_save($pdo, 'sched_jo', (string)$jo, $uid);
        flash('保存しました');
        redirect('sched_settings#studios');
    }

    if ($action === 'studios') {
        $defaults = array_map('intval', (array)($_POST['default'] ?? []));
        $pdo->beginTransaction();
        $pdo->exec('UPDATE studio SET sched_default = 0');
        if ($defaults) {
            $ph = implode(',', array_fill(0, count($defaults), '?'));
            $pdo->prepare("UPDATE studio SET sched_default = 1 WHERE studio_id IN ($ph)")->execute($defaults);
        }
        $upd = $pdo->prepare('UPDATE studio_room SET capacity = ? WHERE studio_room_id = ?');
        foreach ((array)($_POST['cap'] ?? []) as $roomId => $cap) {
            $cap = trim((string)$cap);
            $upd->execute([$cap === '' ? null : max(1, min(255, (int)$cap)), (int)$roomId]);
        }
        $pdo->commit();
        flash('スタジオを保存しました');
        redirect('sched_settings#studios');
    }

    if ($action === 'travel_add') {
        $from = (string)($_POST['from'] ?? '');
        $to = (string)($_POST['to'] ?? '');
        $min = (int)($_POST['minutes'] ?? -1);
        $valid = array_keys(sched_place_names($pdo));
        if (!in_array($from, $valid, true) || !in_array($to, $valid, true) || $from === $to || $min < 0 || $min > 300) {
            flash('場所と分を正しく選んでください', 'error');
            redirect('sched_settings#travel');
        }
        // 向きは問わないので、逆向きの行があれば消してから入れる（同じ組み合わせが2行にならないように）
        $pdo->prepare('DELETE FROM sched_travel WHERE from_place = ? AND to_place = ?')->execute([$to, $from]);
        $pdo->prepare('REPLACE INTO sched_travel (from_place, to_place, minutes) VALUES (?, ?, ?)')->execute([$from, $to, $min]);
        flash('移動時間を保存しました');
        redirect('sched_settings#travel');
    }

    if ($action === 'travel_delete') {
        $pdo->prepare('DELETE FROM sched_travel WHERE from_place = ? AND to_place = ?')->execute([(string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? '')]);
        flash('上書きを削除しました（自動の見積もりに戻ります）');
        redirect('sched_settings#travel');
    }
    redirect('sched_settings');
}

$studios = $pdo->query('SELECT studio_id, name, area, sched_default, lat FROM studio ORDER BY sort_order, studio_id')->fetchAll();
$rooms = [];
foreach ($pdo->query('SELECT studio_room_id, studio_id, name, area_jo, capacity FROM studio_room ORDER BY sort_order, studio_room_id') as $r) {
    $rooms[(int)$r['studio_id']][] = $r;
}
$places = sched_place_names($pdo);
$overrides = $pdo->query('SELECT from_place, to_place, minutes FROM sched_travel ORDER BY from_place, to_place')->fetchAll();
$travel = sched_travel_fn(sched_place_coords($pdo), sched_travel_overrides($pdo));
$defaultStudios = array_filter($studios, static fn($s) => (int)$s['sched_default'] === 1);

render_header('日程調整の設定', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/sched.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a><span>/</span><a href="sched">スタ連の日程調整</a></nav>
<h1 class="display display--sm">日程調整の設定</h1>

<section class="card sch-sec" id="travel">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">移動時間</h2>
        <span class="info-tip" <?= tip_attr('住所の座標の直線距離から自動で見積もる（1.2km 以内は徒歩、それ以上は電車。10分単位）。ズレる組み合わせだけ上書きする') ?>><?= icon('info', 'icon--sm') ?></span></div>
    <?php if ($defaultStudios): ?>
        <div class="table-scroll"><table class="table sch-travel">
            <thead><tr><th></th><?php foreach ($defaultStudios as $s): ?><th><?= h($s['name']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ([...array_map(static fn($c) => 'campus:' . $c, SCHED_CAMPUSES), ...array_map(static fn($s) => 'studio:' . (int)$s['studio_id'], $defaultStudios)] as $from): ?>
                    <tr><th><?= h($places[$from] ?? $from) ?></th>
                        <?php foreach ($defaultStudios as $s): $to = 'studio:' . (int)$s['studio_id']; ?>
                            <td><?= $from === $to ? '—' : $travel($from, $to) . '分' ?></td>
                        <?php endforeach; ?></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?php foreach ($overrides as $o): ?>
        <form method="post" class="sch-row" data-confirm="上書きを削除します。よろしいですか？">
            <?= csrf_field() ?><input type="hidden" name="action" value="travel_delete">
            <input type="hidden" name="from" value="<?= h($o['from_place']) ?>"><input type="hidden" name="to" value="<?= h($o['to_place']) ?>">
            <span><?= h(($places[$o['from_place']] ?? $o['from_place']) . ' ⇄ ' . ($places[$o['to_place']] ?? $o['to_place'])) ?> <strong><?= (int)$o['minutes'] ?>分</strong></span>
            <button class="icon-btn icon-btn--sm" type="submit" aria-label="削除" title="削除"><?= icon('close') ?></button>
        </form>
    <?php endforeach; ?>
    <form method="post" class="sch-row sch-row--wrap">
        <?= csrf_field() ?><input type="hidden" name="action" value="travel_add">
        <select name="from" class="select-sm" aria-label="出発"><?php foreach ($places as $k => $n): ?><option value="<?= h($k) ?>"><?= h($n) ?></option><?php endforeach; ?></select>
        <span>⇄</span>
        <?php $firstStudio = array_values(array_filter(array_keys($places), static fn($k) => str_starts_with($k, 'studio:')))[0] ?? ''; // 到着の初期値 = 最初のスタジオ ?>
        <select name="to" class="select-sm" aria-label="到着"><?php foreach ($places as $k => $n): ?><option value="<?= h($k) ?>"<?= $k === $firstStudio ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
        <input type="number" name="minutes" min="0" max="300" step="5" required class="input-num" aria-label="分"> 分
        <button class="btn btn--sm" type="submit">上書き</button>
    </form>
</section>

<section class="card sch-sec" id="studios">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">スタジオ</h2>
        <span class="info-tip" <?= tip_attr('✓ = 日程調整を作るとき最初から選ばれる。定員が空の部屋は、広さ ÷ 1人あたりの帖数で判定') ?>><?= icon('info', 'icon--sm') ?></span></div>
    <form method="post" class="sch-row">
        <?= csrf_field() ?><input type="hidden" name="action" value="jo">
        <label>1人あたり <input type="number" name="jo" value="<?= h((string)$settings['jo']) ?>" min="0.5" max="20" step="0.5" class="input-num"> 帖</label>
        <button class="btn btn--sm" type="submit">保存</button>
    </form>
    <form method="post"<?= unsaved_attr() ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="studios">
        <div class="sch-studio-list">
            <?php foreach ($studios as $s): ?>
                <div class="sch-studio">
                    <label class="check"><input type="checkbox" name="default[]" value="<?= (int)$s['studio_id'] ?>"<?= (int)$s['sched_default'] === 1 ? ' checked' : '' ?>>
                        <strong><?= h($s['name']) ?></strong> <span class="muted small"><?= h((string)$s['area']) ?></span>
                        <?php if ($s['lat'] === null): ?><span class="pill pill--warn" <?= tip_attr('座標が未登録。移動時間は一律' . SCHED_TRAVEL_UNKNOWN . '分') ?>>座標なし</span><?php endif; ?></label>
                    <?php foreach ($rooms[(int)$s['studio_id']] ?? [] as $r): ?>
                        <label class="sch-room"><span><?= h($r['name']) ?> <small class="muted"><?= h(studio_fmt_jo($r['area_jo'])) ?></small></span>
                            <input type="number" name="cap[<?= (int)$r['studio_room_id'] ?>]" value="<?= h((string)$r['capacity']) ?>" min="1" max="255" class="input-num" placeholder="定員" aria-label="定員"> 人</label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-actions"><button class="btn btn--primary" type="submit">保存</button></div>
    </form>
</section>

<section class="card sch-sec" id="terms">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">学期</h2>
        <span class="info-tip" <?= tip_attr('時間割が効く授業期間。年度が替わったら学年暦に合わせて直す') ?>><?= icon('info', 'icon--sm') ?></span></div>
    <form method="post" class="sch-terms"<?= unsaved_attr() ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="terms">
        <?php foreach (SCHED_TERMS as $t => $label): ?>
            <div class="sch-row sch-row--wrap"><span class="sch-label"><?= h($label) ?></span>
                <input type="date" name="tf[<?= $t ?>]" value="<?= h($settings['terms'][$t][0]) ?>" required> 〜 <input type="date" name="tt[<?= $t ?>]" value="<?= h($settings['terms'][$t][1]) ?>" required></div>
        <?php endforeach; ?>
        <?php foreach ([...$settings['breaks'], ['', ''], ['', '']] as $i => [$bf, $bt]): if ($i >= 4) { break; } ?>
            <div class="sch-row sch-row--wrap"><span class="sch-label">休業</span>
                <input type="date" name="bf[<?= $i ?>]" value="<?= h($bf) ?>"> 〜 <input type="date" name="bt[<?= $i ?>]" value="<?= h($bt) ?>"></div>
        <?php endforeach; ?>
        <div class="form-actions"><button class="btn btn--primary" type="submit">保存</button></div>
    </form>
</section>

<section class="card sch-sec" id="periods">
    <div class="sch-sec__head"><h2 class="section-title section-title--card">時限</h2></div>
    <form method="post" class="sch-periods"<?= unsaved_attr() ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="periods">
        <?php foreach ($settings['periods'] as $p => [$s, $e]): ?>
            <div class="sch-row"><span class="sch-label"><?= $p ?>限</span>
                <input type="time" name="ps[<?= $p ?>]" value="<?= h(sprintf('%02d:%02d', intdiv((int)$s, 60), (int)$s % 60)) ?>" required> 〜
                <input type="time" name="pe[<?= $p ?>]" value="<?= h(sprintf('%02d:%02d', intdiv((int)$e, 60), (int)$e % 60)) ?>" required></div>
        <?php endforeach; ?>
        <div class="form-actions"><button class="btn btn--primary" type="submit">保存</button></div>
    </form>
</section>
<?php render_footer();

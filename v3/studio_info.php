<?php
/**
 * =====================================================================
 *  studio_info.php?id= — スタジオの詳細（studio_list.php から入る）
 * =====================================================================
 *  表示（ログインしていれば誰でも）:
 *    基本情報（サイト・予約ページ・住所・最寄り駅・電話）・地図
 *    料金シミュレーション（JS: assets/studio_info.js。部屋・日にち・時間・人数・学割・レンタルから合計と1人あたりを出す）
 *    部屋と料金表（バンド・個人練習。学割の料金・条件も）・パック・レンタル・機材
 *    シミュレーションは「パックの自動適用（安ければ）」と「+1時間延長したらいくら増えるか」も出す
 *  編集（幹部と管理者だけ）: 各カードの「編集」を開くとフォームが出る
 *
 *  POST は7種類（action）。どれも 権限確認 → CSRF 検証 → 入力検証 → プリペアドステートメント → リダイレクト。
 *    save_info / save_rooms / save_rates / save_packs / save_rentals / save_equipment / delete_studio
 *    ・料金表とパックには「対象」がある: バンド（people = NULL）か、個人練習 n人（people = n）
 *    ・行の一覧（部屋・料金・レンタル・機材）は venue.php と同じ「行ごとの入力欄をまとめて保存」方式:
 *        id=0 の行 → 追加 / 主な欄を空にした行 → 削除 / それ以外 → 更新
 *    ・行の id は必ず「このスタジオの行か」を確かめ、WHERE にも studio_id を入れる（他のスタジオの行を書き換えさせない）
 *
 *  ■ PRG パターン（Post/Redirect/Get）
 *    POST で保存したら、画面を直接出さずに redirect() で GET し直す。
 *    こうしないと、保存後にブラウザの「再読み込み」を押したとき、同じ POST がもう一度送られて二重登録になる。
 *    Web 開発ではほぼ必ず使う定番の形。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/studio_info.php';
$user = require_login();
$pdo = db();
$canEdit = is_executive();

$studioId = (int)($_GET['id'] ?? $_POST['studio_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM studio WHERE studio_id = ?');
$st->execute([$studioId]);
$studio = $st->fetch();
if (!$studio) {
    error_page(404, 'スタジオが見つかりません');
}
$self = 'studio_info?id=' . $studioId;

/**
 * このスタジオの部屋の id 一覧（入力された部屋 id が「このスタジオの部屋か」を確かめるのに使う）
 *   PDO::FETCH_COLUMN = 1列目だけを配列で取る → [3, 5, 8]
 *   array_map('intval', …) = 全部を整数にする（DB からは文字列で返ることがあるため。in_array の厳密比較用）
 */
function studio_room_ids(PDO $pdo, int $studioId): array
{
    $st = $pdo->prepare('SELECT studio_room_id FROM studio_room WHERE studio_id = ?');
    $st->execute([$studioId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 行の一覧をまとめて保存する（部屋・料金・レンタル・機材で共通）
 *   $table / $pk … テーブル名と主キーの列名。★ プログラムに書いた固定の文字だけを渡すこと（利用者の入力は絶対に渡さない）
 *                  SQL の「値」は ? で安全に渡せるが、テーブル名・列名は ? にできないので、文字列でつなぐしかないため
 *   $rows … [[id, ['列名' => 値, …]], …]。値の配列が null の行は削除（id=0 なら何もしない）
 *   並び順（sort_order）は画面の上から 1, 2, 3 … と振り直す
 *
 *  ■ トランザクション
 *    beginTransaction() 〜 commit() の間の SQL は「全部成功」か「全部取り消し（rollBack）」のどちらかになる。
 *    途中でエラーが起きて「半分だけ保存された」状態を防ぐ。
 */
function studio_save_rows(PDO $pdo, string $table, string $pk, int $studioId, array $rows): void
{
    // このスタジオの行の id だけを集める（他のスタジオの行の id が送られてきても無視するため）
    $st = $pdo->prepare("SELECT $pk FROM $table WHERE studio_id = ?");
    $st->execute([$studioId]);
    $own = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();
    try {
        $order = 0;
        foreach ($rows as [$id, $data]) {
            if ($id !== 0 && !in_array($id, $own, true)) {
                continue; // 他のスタジオの行は触らない
            }
            if ($data === null) {
                if ($id !== 0) {
                    $pdo->prepare("DELETE FROM $table WHERE $pk = ? AND studio_id = ?")->execute([$id, $studioId]);
                }
                continue;
            }
            $data['sort_order'] = ++$order;
            $cols = array_keys($data);
            if ($id !== 0) {
                // "name = ?, price = ?, sort_order = ?" のような SET 句を列名から組み立てる
                $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
                $pdo->prepare("UPDATE $table SET $set WHERE $pk = ? AND studio_id = ?")
                    ->execute([...array_values($data), $id, $studioId]); // ... = 配列を展開して引数に並べる（スプレッド構文）
            } else {
                $data = ['studio_id' => $studioId] + $data; // + = 配列の結合（左にあるキーが優先）
                $cols = array_keys($data);
                $marks = implode(', ', array_fill(0, count($cols), '?')); // "?, ?, ?"
                $pdo->prepare("INSERT INTO $table (" . implode(', ', $cols) . ") VALUES ($marks)")
                    ->execute(array_values($data));
            }
        }
        $pdo->prepare('UPDATE studio SET updated_by = ? WHERE studio_id = ?')->execute([(int)current_user()['user_id'], $studioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e; // 取り消したうえで、エラーはそのまま上に投げる（エラーページが出る）
    }
}

/** 0 以上の整数の文字列か（料金・台数の入力チェック用）。ctype_digit = 全部が 0〜9 の文字か */
function studio_uint(string $s, int $max): bool
{
    return ctype_digit($s) && (int)$s <= $max;
}

// =====================================================================
//  POST
// =====================================================================
if (is_post()) {
    require_executive();
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $uid = (int)$user['user_id'];

    // ---- 基本情報 -------------------------------------------------------
    if ($action === 'save_info') {
        // ?? '' = 送られてこなかったら空文字。trim = 前後の空白を取る
        $f = [];
        foreach (['name', 'website_url', 'reserve_url', 'address', 'access', 'phone', 'student_note', 'note'] as $k) {
            $f[$k] = trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));
        }
        $coords = studio_parse_coords((string)($_POST['coords'] ?? ''));
        $error = match (true) { // match(true) = 上から順に条件を見て、最初に true になった値を返す
            $f['name'] === '' || mb_strlen($f['name']) > 100 => 'スタジオ名を100文字以内で入力してください',
            !studio_url_ok($f['website_url']) => 'サイトの URL が正しくありません',
            !studio_url_ok($f['reserve_url']) => '予約ページの URL が正しくありません',
            mb_strlen($f['address']) > 255 => '住所が長すぎます',
            mb_strlen($f['access']) > 100 => '最寄り駅が長すぎます',
            $f['phone'] !== '' && !preg_match('/^[0-9０-９+\-() ]{1,30}$/u', $f['phone']) => '電話番号が正しくありません',
            $coords === false => '座標は「35.6812, 139.7671」の形で入力してください',
            mb_strlen($f['student_note']) > 500 => '学割の条件が長すぎます',
            mb_strlen($f['note']) > 1000 => 'メモが長すぎます',
            default => null,
        };
        if ($error !== null) {
            flash($error, 'error');
            redirect($self);
        }
        // 空の欄は NULL で保存（「未登録」を空文字ではなく NULL で表すのが DB の定番。IS NULL で探せる）
        $nul = fn(string $v) => $v === '' ? null : $v;
        $pdo->prepare('UPDATE studio SET name = ?, website_url = ?, reserve_url = ?, address = ?, access = ?, phone = ?,
                lat = ?, lng = ?, student_note = ?, note = ?, updated_by = ? WHERE studio_id = ?')
            ->execute([$f['name'], $nul($f['website_url']), $nul($f['reserve_url']), $nul($f['address']), $nul($f['access']),
                $nul(mb_convert_kana($f['phone'], 'n')), $coords[0], $coords[1], $nul($f['student_note']), $nul($f['note']), $uid, $studioId]);
        flash('保存しました');
        redirect($self);
    }

    // ---- 部屋 -----------------------------------------------------------
    if ($action === 'save_rooms') {
        $rows = [];
        foreach ((array)($_POST['id'] ?? []) as $i => $rawId) {
            $name = trim((string)($_POST['name'][$i] ?? ''));
            $size = trim((string)($_POST['size_note'][$i] ?? ''));
            if ($name === '') {
                $rows[] = [(int)$rawId, null]; // 部屋名が空 = 削除（その部屋だけの料金・機材も CASCADE で消える）
                continue;
            }
            if (mb_strlen($name) > 50 || mb_strlen($size) > 50) {
                flash('部屋名・広さは50文字以内で入力してください', 'error');
                redirect($self);
            }
            $rows[] = [(int)$rawId, ['name' => $name, 'size_note' => $size === '' ? null : $size]];
        }
        studio_save_rows($pdo, 'studio_room', 'studio_room_id', $studioId, $rows);
        flash('保存しました');
        redirect($self . '#rooms');
    }

    // ---- 料金表・パック（入力欄の形がほぼ同じなので、1つの処理で両方受ける）-----------------
    //   共通: 対象（バンド / 個人n人）・部屋・曜日・時間帯・料金・学割
    //   パックだけ: 名前・使える時間（minutes。空 = 時間帯まるごと）
    if ($action === 'save_rates' || $action === 'save_packs') {
        $isPack = $action === 'save_packs';
        $anchor = $isPack ? '#packs' : '#rates';
        $roomIds = studio_room_ids($pdo, $studioId);
        $rows = [];
        foreach ((array)($_POST['id'] ?? []) as $i => $rawId) {
            $price = trim((string)($_POST['price'][$i] ?? ''));
            if ($price === '') {
                $rows[] = [(int)$rawId, null]; // 料金が空 = 削除
                continue;
            }
            $room = (int)($_POST['room'][$i] ?? 0);       // 0 = 全部屋共通
            $people = (int)($_POST['people'][$i] ?? 0);   // 0 = バンド
            $day = (int)($_POST['day'][$i] ?? 0);
            $start = (string)($_POST['start'][$i] ?? '');
            $end = (string)($_POST['end'][$i] ?? '');
            $student = trim((string)($_POST['student_price'][$i] ?? ''));
            $ok = ($room === 0 || in_array($room, $roomIds, true))
                && $people >= 0 && $people <= STUDIO_SOLO_MAX
                && array_key_exists($day, STUDIO_DAY_TYPES)
                && studio_uint($start, STUDIO_MAX_MIN) && studio_uint($end, STUDIO_MAX_MIN) && (int)$start < (int)$end
                && studio_uint($price, 999999) && ($student === '' || studio_uint($student, 999999));
            $data = [
                'studio_room_id' => $room === 0 ? null : $room,
                'people' => $people === 0 ? null : $people, // NULL = バンド
                'day_type' => $day,
                'start_min' => (int)$start,
                'end_min' => (int)$end,
                'price' => (int)$price,
                'student_price' => $student === '' ? null : (int)$student,
            ];
            if ($isPack) {
                $name = trim((string)($_POST['name'][$i] ?? ''));
                $minutes = trim((string)($_POST['minutes'][$i] ?? ''));
                // 使える時間は 30分きざみで、時間帯の長さ以内（時間帯より長いパックはありえない）
                $ok = $ok && $name !== '' && mb_strlen($name) <= 50
                    && ($minutes === '' || (studio_uint($minutes, STUDIO_MAX_MIN) && (int)$minutes > 0 && (int)$minutes <= (int)$end - (int)$start));
                $data = ['name' => $name] + $data + ['minutes' => $minutes === '' ? null : (int)$minutes];
            }
            if (!$ok) {
                flash($isPack ? 'パックの入力が正しくありません（名前・時間帯・使える時間を確認）' : '料金表の入力が正しくありません（終了は開始より後に）', 'error');
                redirect($self . $anchor);
            }
            $rows[] = [(int)$rawId, $data];
        }
        if ($isPack) {
            studio_save_rows($pdo, 'studio_pack', 'studio_pack_id', $studioId, $rows);
        } else {
            studio_save_rows($pdo, 'studio_rate', 'studio_rate_id', $studioId, $rows);
        }
        flash('保存しました');
        redirect($self . $anchor);
    }

    // ---- レンタル -------------------------------------------------------
    if ($action === 'save_rentals') {
        $rows = [];
        foreach ((array)($_POST['id'] ?? []) as $i => $rawId) {
            $name = trim((string)($_POST['name'][$i] ?? ''));
            $price = trim((string)($_POST['price'][$i] ?? ''));
            if ($name === '') {
                $rows[] = [(int)$rawId, null];
                continue;
            }
            if (mb_strlen($name) > 100 || !studio_uint($price, 999999)) {
                flash('レンタルの名前・料金が正しくありません', 'error');
                redirect($self . '#rentals');
            }
            $rows[] = [(int)$rawId, [
                'name' => $name,
                'price' => (int)$price,
                'per_hour' => ($_POST['per_hour'][$i] ?? '0') === '1' ? 1 : 0,
            ]];
        }
        studio_save_rows($pdo, 'studio_rental', 'studio_rental_id', $studioId, $rows);
        flash('保存しました');
        redirect($self . '#rentals');
    }

    // ---- 機材 -----------------------------------------------------------
    if ($action === 'save_equipment') {
        $roomIds = studio_room_ids($pdo, $studioId);
        $rows = [];
        foreach ((array)($_POST['id'] ?? []) as $i => $rawId) {
            $model = trim((string)($_POST['model'][$i] ?? ''));
            if ($model === '') {
                $rows[] = [(int)$rawId, null];
                continue;
            }
            $room = (int)($_POST['room'][$i] ?? 0);
            $cat = (string)($_POST['category'][$i] ?? '');
            $q = trim((string)($_POST['quantity'][$i] ?? ''));
            if (!($room === 0 || in_array($room, $roomIds, true)) || !in_array($cat, STUDIO_EQUIP_CATEGORIES, true)
                || mb_strlen($model) > 100 || !studio_uint($q, 99)) {
                flash('区分・機種名・台数が正しくありません', 'error');
                redirect($self . '#equipment');
            }
            $rows[] = [(int)$rawId, [
                'studio_room_id' => $room === 0 ? null : $room,
                'category' => $cat,
                'model_name' => $model,
                'quantity' => (int)$q,
            ]];
        }
        studio_save_rows($pdo, 'studio_equipment', 'studio_equipment_id', $studioId, $rows);
        flash('保存しました');
        redirect($self . '#equipment');
    }

    // ---- スタジオの削除（部屋・料金・レンタル・機材は外部キーの CASCADE で一緒に消える）----
    if ($action === 'delete_studio') {
        $pdo->prepare('DELETE FROM studio WHERE studio_id = ?')->execute([$studioId]);
        flash("「{$studio['name']}」を削除しました");
        redirect('studio_list');
    }

    error_page(400, '不正なリクエストです');
}

// =====================================================================
//  表示用のデータ
// =====================================================================
$st = $pdo->prepare('SELECT studio_room_id, name, size_note FROM studio_room WHERE studio_id = ? ORDER BY sort_order, studio_room_id');
$st->execute([$studioId]);
$rooms = $st->fetchAll();
$roomName = array_column($rooms, 'name', 'studio_room_id'); // [部屋id => 部屋名]（array_column の第3引数 = キーにする列）

// 料金表: 表示は「バンド → 個人1人 → 個人2人 …」の順にしたいので、people の NULL を先頭に並べる
//   ORDER BY people IS NULL DESC = 「NULL か？」が真（1）の行を先に。そのあと人数の小さい順
$st = $pdo->prepare('SELECT studio_rate_id, studio_room_id, people, day_type, start_min, end_min, price, student_price
    FROM studio_rate WHERE studio_id = ? ORDER BY people IS NULL DESC, people, sort_order, studio_rate_id');
$st->execute([$studioId]);
$rates = $st->fetchAll();

$st = $pdo->prepare('SELECT studio_pack_id, studio_room_id, people, name, day_type, start_min, end_min, minutes, price, student_price
    FROM studio_pack WHERE studio_id = ? ORDER BY sort_order, studio_pack_id');
$st->execute([$studioId]);
$packs = $st->fetchAll();

$st = $pdo->prepare('SELECT studio_rental_id, name, price, per_hour FROM studio_rental WHERE studio_id = ? ORDER BY sort_order, studio_rental_id');
$st->execute([$studioId]);
$rentals = $st->fetchAll();

$st = $pdo->prepare('SELECT studio_equipment_id, studio_room_id, category, model_name, quantity
    FROM studio_equipment WHERE studio_id = ? ORDER BY sort_order, studio_equipment_id');
$st->execute([$studioId]);
$equipment = $st->fetchAll();

// 機材を「部屋（0 = 共通）→ 区分」の2段に分ける。??= は「まだ無ければ入れる」
$equipByRoom = [];
foreach ($equipment as $e) {
    $equipByRoom[(int)($e['studio_room_id'] ?? 0)][$e['category']][] = $e;
}

// 料金表を部屋ごとに分ける（0 = 全部屋共通）
$ratesByRoom = [];
foreach ($rates as $r) {
    $ratesByRoom[(int)($r['studio_room_id'] ?? 0)][] = $r;
}
// 学割の列・チェックを出すか（料金表かパックのどこかに学割の料金があれば）
$hasStudent = array_filter(array_merge(array_column($rates, 'student_price'), array_column($packs, 'student_price')), fn($v) => $v !== null) !== [];
// 個人練習の料金があるか（あれば料金表に「対象」の列と、シミュレーションに「バンド / 個人」の切り替えを出す）
$hasSolo = array_filter(array_merge(array_column($rates, 'people'), array_column($packs, 'people')), fn($v) => $v !== null) !== [];
$hasBand = in_array(null, array_column($rates, 'people'), true) || in_array(null, array_column($packs, 'people'), true);

/** パックの「使える時間」の表示（NULL = 時間帯まるごと → 時間帯の長さを出す） */
$packLen = fn(array $p) => studio_fmt_min((int)($p['minutes'] ?? ((int)$p['end_min'] - (int)$p['start_min'])));

// 料金シミュレーション用のデータ（JS へ渡す）。キーは短く、値は数値にしておく
$simData = [
    'rooms' => array_map(fn($r) => ['id' => (int)$r['studio_room_id'], 'name' => $r['name']], $rooms),
    'rates' => array_map(fn($r) => [
        'room' => $r['studio_room_id'] === null ? 0 : (int)$r['studio_room_id'],
        'ppl' => $r['people'] === null ? 0 : (int)$r['people'], // 0 = バンド
        'day' => (int)$r['day_type'],
        's' => (int)$r['start_min'],
        'e' => (int)$r['end_min'],
        'p' => (int)$r['price'],
        'sp' => $r['student_price'] === null ? null : (int)$r['student_price'],
    ], $rates),
    'packs' => array_map(fn($r) => [
        'name' => $r['name'],
        'room' => $r['studio_room_id'] === null ? 0 : (int)$r['studio_room_id'],
        'ppl' => $r['people'] === null ? 0 : (int)$r['people'],
        'day' => (int)$r['day_type'],
        's' => (int)$r['start_min'],
        'e' => (int)$r['end_min'],
        'min' => $r['minutes'] === null ? null : (int)$r['minutes'],
        'p' => (int)$r['price'],
        'sp' => $r['student_price'] === null ? null : (int)$r['student_price'],
    ], $packs),
    'rentals' => array_map(fn($r) => [
        'id' => (int)$r['studio_rental_id'], 'name' => $r['name'], 'p' => (int)$r['price'], 'hour' => (bool)$r['per_hour'],
    ], $rentals),
];

// 地図: 座標があれば Leaflet でピン1本。Google マップのリンクは「経路検索」に使える（座標が無ければ住所 → 名前で検索）
$hasPin = $studio['lat'] !== null && $studio['lng'] !== null;
$gmapQuery = $hasPin ? $studio['lat'] . ',' . $studio['lng'] : (string)($studio['address'] ?: $studio['name']);
$gmapUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($gmapQuery);
$coordsValue = $hasPin ? rtrim(rtrim((string)$studio['lat'], '0'), '.') . ', ' . rtrim(rtrim((string)$studio['lng'], '0'), '.') : '';

/** 部屋の <select> の選択肢（0 = 全部屋共通） */
$roomOptions = function (?int $selected) use ($rooms): string {
    $html = '<option value="0">全部屋</option>';
    foreach ($rooms as $r) {
        $id = (int)$r['studio_room_id'];
        $html .= '<option value="' . $id . '"' . ($id === $selected ? ' selected' : '') . '>' . h($r['name']) . '</option>';
    }
    return $html;
};

render_header($studio['name'], 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/vendor/leaflet/leaflet.css') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/studio_info.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a> / <a href="studio_list">スタジオ情報</a></nav>
<h1 class="display display--sm"><?= h($studio['name']) ?></h1>

<!-- ===== 基本情報 ===== -->
<section class="card sinfo-section">
    <div class="sinfo-links">
        <?php if ($studio['reserve_url']): ?>
            <a class="btn btn--primary btn--sm" href="<?= h($studio['reserve_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('event_available') ?> 予約</a>
        <?php endif; ?>
        <?php if ($studio['website_url']): ?>
            <a class="btn btn--sm" href="<?= h($studio['website_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('language') ?> サイト</a>
        <?php endif; ?>
        <?php if ($studio['phone']): ?>
            <!-- tel: リンク = スマホで押すと電話アプリが開く -->
            <a class="btn btn--sm" href="tel:<?= h(preg_replace('/[^0-9+]/', '', $studio['phone'])) ?>"><?= icon('call') ?> <?= h($studio['phone']) ?></a>
        <?php endif; ?>
    </div>
    <p class="sinfo-info">
        <?php if ($studio['address']): ?><span><?= icon('location_on') ?> <?= h($studio['address']) ?></span><?php endif; ?>
        <?php if ($studio['access']): ?><span><?= icon('train') ?> <?= h($studio['access']) ?></span><?php endif; ?>
    </p>
    <?php if ($studio['note']): ?><p class="sinfo-note"><?= nl2br(h($studio['note'])) ?></p><?php endif; ?>

    <?php if ($hasPin): ?>
        <div class="sinfo-map sinfo-map--one" id="studio-map" role="region" aria-label="地図"></div>
        <script type="application/json" id="studio-pins"><?= json_encode([[
            'id' => $studioId, 'name' => $studio['name'], 'lat' => (float)$studio['lat'], 'lng' => (float)$studio['lng'], 'sub' => $studio['access'] ?? '',
        ]], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>
    <a class="small" href="<?= h($gmapUrl) ?>" target="_blank" rel="noopener noreferrer"><?= icon('directions') ?> Googleマップで開く</a>

    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_info">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <label class="sinfo-field"><span>スタジオ名</span>
                <input autocomplete="off" type="text" name="name" maxlength="100" required value="<?= h($studio['name']) ?>"></label>
            <label class="sinfo-field"><span>予約ページ</span>
                <input autocomplete="off" type="url" name="reserve_url" maxlength="255" value="<?= h($studio['reserve_url']) ?>" placeholder="https://"></label>
            <label class="sinfo-field"><span>サイト</span>
                <input autocomplete="off" type="url" name="website_url" maxlength="255" value="<?= h($studio['website_url']) ?>" placeholder="https://"></label>
            <label class="sinfo-field"><span>住所</span>
                <input autocomplete="off" type="text" name="address" maxlength="255" value="<?= h($studio['address']) ?>" data-geo-address></label>
            <label class="sinfo-field"><span>座標</span>
                <span class="sinfo-edit__row">
                    <input autocomplete="off" type="text" class="grow" name="coords" maxlength="60" value="<?= h($coordsValue) ?>" placeholder="35.6812, 139.7671" inputmode="decimal" data-geo-coords>
                    <!-- 住所から座標を取る（国土地理院の住所検索 API。JS: assets/studio_info.js） -->
                    <button type="button" class="btn btn--sm" data-geo-lookup title="住所から取得"><?= icon('my_location') ?></button>
                </span></label>
            <label class="sinfo-field"><span>最寄り駅</span>
                <input autocomplete="off" type="text" name="access" maxlength="100" value="<?= h($studio['access']) ?>" placeholder="立川駅 北口 徒歩5分"></label>
            <label class="sinfo-field"><span>電話</span>
                <input autocomplete="off" type="tel" name="phone" maxlength="30" value="<?= h($studio['phone']) ?>"></label>
            <label class="sinfo-field"><span>学割の条件</span>
                <textarea name="student_note" rows="2" maxlength="500" placeholder="学生証の提示が必要 など"><?= h($studio['student_note']) ?></textarea></label>
            <label class="sinfo-field"><span>メモ</span>
                <textarea name="note" rows="3" maxlength="1000"><?= h($studio['note']) ?></textarea></label>
            <div><button type="submit" class="btn btn--primary btn--sm"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>

<!-- ===== 料金シミュレーション（料金表が1行でもあれば出す。中身は JS が作る） ===== -->
<?php if ($rates || $packs): ?>
<section class="card sinfo-section" id="sim">
    <h2><?= icon('calculate') ?> 料金シミュレーション</h2>
    <form class="sinfo-sim" id="studio-sim" onsubmit="return false">
        <?php if ($hasSolo && $hasBand): ?>
            <!-- バンド / 個人練習の切り替え（両方の料金があるときだけ出す） -->
            <div class="sinfo-seg sinfo-seg--mode" role="radiogroup" aria-label="対象">
                <label><input type="radio" name="mode" value="band" checked> <?= icon('groups') ?> バンド</label>
                <label><input type="radio" name="mode" value="solo"> <?= icon('person') ?> 個人練習</label>
            </div>
        <?php else: ?>
            <!-- 片方しか無いときは選ばせず、hidden で固定 -->
            <input type="hidden" name="mode" value="<?= $hasBand ? 'band' : 'solo' ?>">
        <?php endif; ?>
        <div class="sinfo-sim__grid">
            <?php if ($rooms): ?>
            <label class="sinfo-field"><span>部屋</span>
                <select name="room">
                    <?php foreach ($rooms as $r): ?><option value="<?= (int)$r['studio_room_id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?>
                </select></label>
            <?php endif; ?>
            <label class="sinfo-field"><span>日付</span><input type="date" name="date"></label>
            <div class="sinfo-field"><span>曜日</span>
                <div class="sinfo-seg" role="radiogroup">
                    <label><input type="radio" name="day" value="1" checked> 平日</label>
                    <label><input type="radio" name="day" value="2"> 土日祝</label>
                </div></div>
            <label class="sinfo-field"><span>開始</span><select name="start"><?= studio_time_options(18 * 60) ?></select></label>
            <label class="sinfo-field"><span>時間</span>
                <select name="dur">
                    <?php for ($m = 60; $m <= 600; $m += 30): ?>
                        <option value="<?= $m ?>"<?= $m === 120 ? ' selected' : '' ?>><?= h(studio_fmt_min($m)) ?></option>
                    <?php endfor; ?>
                </select></label>
            <label class="sinfo-field"><span>人数</span><input type="number" name="people" min="1" max="30" value="5" inputmode="numeric"></label>
        </div>
        <?php if ($hasStudent): ?>
            <label class="sinfo-check"><input type="checkbox" name="student" checked> 学割</label>
        <?php endif; ?>
        <?php if ($rentals): ?>
            <div class="sinfo-sim__rentals">
                <?php foreach ($rentals as $r): ?>
                    <label class="sinfo-rental">
                        <span><?= h($r['name']) ?> <span class="muted small"><?= h(studio_yen((int)$r['price'])) ?><?= $r['per_hour'] ? '/h' : '' ?></span></span>
                        <input type="number" name="rental_<?= (int)$r['studio_rental_id'] ?>" min="0" max="20" value="0" inputmode="numeric" aria-label="<?= h($r['name']) ?>の数">
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <!-- 結果（JS が書き込む）。aria-live = 中身が変わったら読み上げソフトが読む -->
        <div class="sinfo-sim__result" id="studio-sim-result" aria-live="polite"></div>
    </form>
    <script type="application/json" id="studio-sim-data"><?= json_encode($simData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</section>
<?php endif; ?>

<!-- ===== 部屋と料金表 ===== -->
<section class="card sinfo-section" id="rates">
    <h2><?= icon('payments') ?> 料金 <span class="muted small">1時間あたり</span></h2>
    <?php if (!$rates): ?><p class="muted small">-</p><?php endif; ?>
    <?php foreach ([0 => '全部屋'] + $roomName as $rid => $rname): if (empty($ratesByRoom[$rid])) continue; ?>
        <div>
            <?php if ($rooms): ?><h3 class="sinfo-sub"><?= h($rname) ?></h3><?php endif; ?>
            <div class="sinfo-table-wrap">
            <table class="sinfo-table">
                <thead><tr><?php if ($hasSolo): ?><th>対象</th><?php endif; ?><th>曜日</th><th>時間</th><th class="num">料金</th><?php if ($hasStudent): ?><th class="num">学割</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($ratesByRoom[$rid] as $r): ?>
                    <tr>
                        <?php if ($hasSolo): ?><td><?= h(studio_target_label($r['people'] === null ? null : (int)$r['people'])) ?></td><?php endif; ?>
                        <td><?= h(STUDIO_DAY_TYPES[(int)$r['day_type']] ?? '') ?></td>
                        <td><?= h(studio_fmt_min((int)$r['start_min'])) ?>〜<?= h(studio_fmt_min((int)$r['end_min'])) ?></td>
                        <td class="num"><?= h(studio_yen((int)$r['price'])) ?></td>
                        <?php if ($hasStudent): ?>
                            <td class="num sinfo-student"><?= $r['student_price'] === null ? '<span class="muted">-</span>' : h(studio_yen((int)$r['student_price'])) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($studio['student_note']): ?>
        <p class="sinfo-note"><span class="tag tag--plan">学割</span> <?= nl2br(h($studio['student_note'])) ?></p>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 料金を編集</summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_rates">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <!-- 末尾に空の行を2つ足す（id=0 = 新規）。料金を空にした行は削除。個人練習の料金は「その人数での1時間の合計」 -->
            <?php foreach (array_merge($rates, array_fill(0, 2, ['studio_rate_id' => 0, 'studio_room_id' => null, 'people' => null, 'day_type' => 0, 'start_min' => 600, 'end_min' => 1380, 'price' => '', 'student_price' => null])) as $r): ?>
                <div class="sinfo-edit__row sinfo-edit__rate">
                    <input type="hidden" name="id[]" value="<?= (int)$r['studio_rate_id'] ?>">
                    <select name="people[]" aria-label="対象"><?= studio_target_options($r['people'] === null ? null : (int)$r['people']) ?></select>
                    <?php if ($rooms): ?>
                        <select name="room[]" aria-label="部屋"><?= $roomOptions($r['studio_room_id'] === null ? 0 : (int)$r['studio_room_id']) ?></select>
                    <?php else: ?><input type="hidden" name="room[]" value="0"><?php endif; ?>
                    <select name="day[]" aria-label="曜日">
                        <?php foreach (STUDIO_DAY_TYPES as $k => $label): ?>
                            <option value="<?= $k ?>"<?= $k === (int)$r['day_type'] ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="start[]" aria-label="開始"><?= studio_time_options((int)$r['start_min']) ?></select>
                    <span class="muted">〜</span>
                    <select name="end[]" aria-label="終了"><?= studio_time_options((int)$r['end_min']) ?></select>
                    <input type="number" name="price[]" min="0" max="999999" value="<?= h($r['price']) ?>" placeholder="料金" aria-label="1時間の料金" inputmode="numeric">
                    <input type="number" name="student_price[]" min="0" max="999999" value="<?= h($r['student_price']) ?>" placeholder="学割" aria-label="学割の1時間の料金" inputmode="numeric">
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="料金を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>

<!-- ===== パック（時間帯の中の決まった時間を一律料金）===== -->
<?php if ($packs || $canEdit): ?>
<section class="card sinfo-section" id="packs">
    <h2><?= icon('inventory_2') ?> パック</h2>
    <?php if (!$packs): ?><p class="muted small">-</p><?php else: ?>
        <ul class="sinfo-plain sinfo-packs">
            <?php foreach ($packs as $p): ?>
                <li>
                    <div class="sinfo-packs__head">
                        <strong><?= h($p['name']) ?></strong>
                        <?php if ($hasSolo): ?><span class="tag"><?= h(studio_target_label($p['people'] === null ? null : (int)$p['people'])) ?></span><?php endif; ?>
                        <?php if ($p['studio_room_id'] !== null && isset($roomName[(int)$p['studio_room_id']])): ?><span class="tag"><?= h($roomName[(int)$p['studio_room_id']]) ?></span><?php endif; ?>
                    </div>
                    <div class="sinfo-packs__body">
                        <span class="muted small">
                            <?= h(STUDIO_DAY_TYPES[(int)$p['day_type']] ?? '') ?>
                            <?= h(studio_fmt_min((int)$p['start_min'])) ?>〜<?= h(studio_fmt_min((int)$p['end_min'])) ?>
                            <?php if ($p['minutes'] !== null): ?>のうち <?= h($packLen($p)) ?><?php endif; ?>
                        </span>
                        <span>
                            <?= h(studio_yen((int)$p['price'])) ?>
                            <?php if ($p['student_price'] !== null): ?><span class="sinfo-student">学割 <?= h(studio_yen((int)$p['student_price'])) ?></span><?php endif; ?>
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_packs">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <!-- 使える時間を「時間帯まるごと」にするときは、時間の欄を空にする。料金を空にした行は削除 -->
            <?php foreach (array_merge($packs, [['studio_pack_id' => 0, 'studio_room_id' => null, 'people' => null, 'name' => '', 'day_type' => 0, 'start_min' => 1440, 'end_min' => 1800, 'minutes' => null, 'price' => '', 'student_price' => null]]) as $p): ?>
                <div class="sinfo-edit__row sinfo-edit__rate">
                    <input type="hidden" name="id[]" value="<?= (int)$p['studio_pack_id'] ?>">
                    <input autocomplete="off" type="text" class="grow" name="name[]" maxlength="50" value="<?= h($p['name']) ?>" placeholder="名前（例: 深夜パック）" aria-label="パック名">
                    <select name="people[]" aria-label="対象"><?= studio_target_options($p['people'] === null ? null : (int)$p['people']) ?></select>
                    <?php if ($rooms): ?>
                        <select name="room[]" aria-label="部屋"><?= $roomOptions($p['studio_room_id'] === null ? 0 : (int)$p['studio_room_id']) ?></select>
                    <?php else: ?><input type="hidden" name="room[]" value="0"><?php endif; ?>
                    <select name="day[]" aria-label="曜日">
                        <?php foreach (STUDIO_DAY_TYPES as $k => $label): ?>
                            <option value="<?= $k ?>"<?= $k === (int)$p['day_type'] ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="start[]" aria-label="時間帯の開始"><?= studio_time_options((int)$p['start_min']) ?></select>
                    <span class="muted">〜</span>
                    <select name="end[]" aria-label="時間帯の終了"><?= studio_time_options((int)$p['end_min']) ?></select>
                    <select name="minutes[]" aria-label="使える時間">
                        <option value="">時間帯まるごと</option>
                        <?php for ($m = 60; $m <= 720; $m += 30): ?>
                            <option value="<?= $m ?>"<?= $p['minutes'] !== null && (int)$p['minutes'] === $m ? ' selected' : '' ?>><?= h(studio_fmt_min($m)) ?></option>
                        <?php endfor; ?>
                    </select>
                    <input type="number" name="price[]" min="0" max="999999" value="<?= h($p['price']) ?>" placeholder="料金" aria-label="パックの料金" inputmode="numeric">
                    <input type="number" name="student_price[]" min="0" max="999999" value="<?= h($p['student_price']) ?>" placeholder="学割" aria-label="学割のパックの料金" inputmode="numeric">
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="料金を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- ===== 部屋 ===== -->
<?php if ($rooms || $canEdit): ?>
<section class="card sinfo-section" id="rooms">
    <h2><?= icon('meeting_room') ?> 部屋 <span class="muted small"><?= count($rooms) ?></span></h2>
    <?php if (!$rooms): ?><p class="muted small">-</p><?php else: ?>
        <ul class="sinfo-plain">
            <?php foreach ($rooms as $r): ?>
                <li><?= h($r['name']) ?><?php if ($r['size_note']): ?> <span class="muted small"><?= h($r['size_note']) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="sinfo-edit__form" data-confirm="部屋名を空にした部屋は、その部屋の料金・パック・機材も削除されます。保存しますか？">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_rooms">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <?php foreach (array_merge($rooms, [['studio_room_id' => 0, 'name' => '', 'size_note' => '']]) as $r): ?>
                <div class="sinfo-edit__row">
                    <input type="hidden" name="id[]" value="<?= (int)$r['studio_room_id'] ?>">
                    <input autocomplete="off" type="text" class="grow" name="name[]" maxlength="50" value="<?= h($r['name']) ?>" placeholder="部屋名" aria-label="部屋名">
                    <input autocomplete="off" type="text" class="grow" name="size_note[]" maxlength="50" value="<?= h($r['size_note']) ?>" placeholder="広さ・定員" aria-label="広さ・定員">
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="部屋名を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- ===== レンタル ===== -->
<section class="card sinfo-section" id="rentals">
    <h2><?= icon('mic') ?> レンタル</h2>
    <?php if (!$rentals): ?><p class="muted small">-</p><?php else: ?>
        <ul class="sinfo-plain sinfo-price-list">
            <?php foreach ($rentals as $r): ?>
                <li><span><?= h($r['name']) ?></span><span><?= h(studio_yen((int)$r['price'])) ?><span class="muted small"><?= $r['per_hour'] ? '/h' : '/回' ?></span></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_rentals">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <?php foreach (array_merge($rentals, array_fill(0, 2, ['studio_rental_id' => 0, 'name' => '', 'price' => '', 'per_hour' => 0])) as $r): ?>
                <div class="sinfo-edit__row">
                    <input type="hidden" name="id[]" value="<?= (int)$r['studio_rental_id'] ?>">
                    <input autocomplete="off" type="text" class="grow" name="name[]" maxlength="100" value="<?= h($r['name']) ?>" placeholder="名前（例: SM58）" aria-label="名前">
                    <input type="number" name="price[]" min="0" max="999999" value="<?= h($r['price']) ?>" placeholder="料金" aria-label="料金" inputmode="numeric">
                    <!-- チェックボックスは「外れていると何も送られない」ので、行の番号がずれる。select にして必ず値を送る -->
                    <select name="per_hour[]" aria-label="単位">
                        <option value="0"<?= (int)$r['per_hour'] === 0 ? ' selected' : '' ?>>/回</option>
                        <option value="1"<?= (int)$r['per_hour'] === 1 ? ' selected' : '' ?>>/h</option>
                    </select>
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="名前を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>

<!-- ===== 機材（部屋ごと → 区分ごと） ===== -->
<section class="card sinfo-section" id="equipment">
    <h2><?= icon('speaker') ?> 機材</h2>
    <?php if (!$equipment): ?><p class="muted small">-</p><?php endif; ?>
    <?php foreach ([0 => '全部屋'] + $roomName as $rid => $rname): if (empty($equipByRoom[$rid])) continue; ?>
        <div>
            <?php if ($rooms): ?><h3 class="sinfo-sub"><?= h($rname) ?></h3><?php endif; ?>
            <?php foreach (STUDIO_EQUIP_CATEGORIES as $cat): if (empty($equipByRoom[$rid][$cat])) continue; ?>
                <div class="sinfo-equip">
                    <span class="sinfo-equip__cat"><?= h($cat) ?></span>
                    <ul class="sinfo-plain">
                        <?php foreach ($equipByRoom[$rid][$cat] as $e): ?>
                            <li><?= h($e['model_name']) ?><?php if ((int)$e['quantity'] !== 1): ?> <span class="muted">× <?= (int)$e['quantity'] ?></span><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_equipment">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <?php foreach (array_merge($equipment, array_fill(0, 2, ['studio_equipment_id' => 0, 'studio_room_id' => null, 'category' => '', 'model_name' => '', 'quantity' => 1])) as $e): ?>
                <div class="sinfo-edit__row">
                    <input type="hidden" name="id[]" value="<?= (int)$e['studio_equipment_id'] ?>">
                    <?php if ($rooms): ?>
                        <select name="room[]" aria-label="部屋"><?= $roomOptions($e['studio_room_id'] === null ? 0 : (int)$e['studio_room_id']) ?></select>
                    <?php else: ?><input type="hidden" name="room[]" value="0"><?php endif; ?>
                    <select name="category[]" aria-label="区分">
                        <?php foreach (STUDIO_EQUIP_CATEGORIES as $cat): ?>
                            <option value="<?= h($cat) ?>"<?= $cat === $e['category'] ? ' selected' : '' ?>><?= h($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input autocomplete="off" type="text" class="grow" name="model[]" maxlength="100" value="<?= h($e['model_name']) ?>" placeholder="機種名" aria-label="機種名">
                    <input type="number" name="quantity[]" min="0" max="99" value="<?= (int)$e['quantity'] ?>" aria-label="台数" inputmode="numeric">
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="機種名を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>

<?php if ($canEdit): ?>
    <form method="post" class="sinfo-delete" data-confirm="「<?= h($studio['name']) ?>」を削除します（料金・パック・レンタル・機材も削除）。元に戻せません。よろしいですか？">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_studio">
        <input type="hidden" name="studio_id" value="<?= $studioId ?>">
        <button type="submit" class="btn btn--danger btn--sm"><?= icon('delete') ?> スタジオを削除</button>
    </form>
<?php endif; ?>

<script src="<?= asset_url('assets/vendor/leaflet/leaflet.js') ?>"></script>
<script src="<?= asset_url('assets/studio_info.js') ?>"></script>
<?php render_footer();

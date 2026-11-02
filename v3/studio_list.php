<?php
/**
 * =====================================================================
 *  studio_list.php — スタジオ情報の一覧＋スタジオマップ（tools.php から入る）
 * =====================================================================
 *  表示: 全スタジオのピンを1枚の地図（Leaflet + OpenStreetMap）に出し、その下にカードの一覧
 *        カードには 最寄り駅・バンドのいちばん安い1時間の料金・学割／個人練習／パックの有無 を出す
 *  追加: 幹部と管理者だけ（名前だけ入れて作り、詳しい情報は studio_info.php で編集）
 *
 *  ■ Leaflet（リーフレット）とは
 *    地図を表示する JavaScript ライブラリ。地図の「絵」（タイル画像）は別の所から借りてくる。
 *    今回は OpenStreetMap（みんなで作る無料の地図）のタイルを使う → API キー・課金設定が要らない。
 *    ライブラリ本体は CDN ではなく assets/vendor/leaflet に置いた（外部サービスが止まっても動くように）
 *
 *  セキュリティ:
 *    ・POST（追加）は require_executive() で権限を確認 → verify_csrf() で CSRF を確認
 *    ・SQL はプリペアドステートメント、画面の文字は h() で XSS 対策
 *    ・地図用のデータは json_encode(JSON_HEX_TAG …) で <script> の中に安全に埋め込む
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/studio_info.php';
require_login();
$pdo = db();
$canEdit = is_executive(); // 幹部（と管理者）だけ追加・編集できる

// =====================================================================
//  POST（スタジオの追加）
// =====================================================================
if (is_post()) {
    require_executive(); // 画面にフォームを出していなくても、送られてきたら必ずサーバー側で権限を確かめる
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        flash('スタジオ名を100文字以内で入力してください', 'error');
        redirect('studio_list');
    }
    // 並び順は「今ある最大 + 1」= いちばん最後。COALESCE(x, 0) = x が NULL（1行も無い）なら 0
    $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM studio')->fetchColumn();
    $pdo->prepare('INSERT INTO studio (name, sort_order, updated_by) VALUES (?, ?, ?)')
        ->execute([$name, $next, (int)current_user()['user_id']]);
    // lastInsertId() = 今 INSERT した行の AUTO_INCREMENT の番号。そのまま詳細ページへ飛んで続きを入力してもらう
    $id = (int)$pdo->lastInsertId();
    flash('追加しました');
    redirect('studio_info?id=' . $id);
}

// =====================================================================
//  表示用のデータ
// =====================================================================
// MIN() = 最小値。相関サブクエリでスタジオごとに「バンドのいちばん安い1時間の料金」「学割の行があるか」をまとめて取る
// EXISTS (SELECT 1 …) = 1行でもあれば 1。個人練習の料金・パックがあるかのタグに使う（件数は要らないので COUNT より速い）
//   max_area = そのスタジオでいちばん広い部屋の帖数（広さの絞り込み用。部屋の広さが未入力なら NULL）
//   （スタジオの数だけ SQL を投げる「N+1 問題」を避けるため、1回の SQL で済ませる）
$studios = $pdo->query('SELECT s.studio_id, s.name, s.access, s.lat, s.lng, s.reserve_url,
        (SELECT MIN(r.price) FROM studio_rate r WHERE r.studio_id = s.studio_id AND r.people IS NULL) AS min_price,
        (SELECT MIN(r.student_price) FROM studio_rate r WHERE r.studio_id = s.studio_id) AS min_student,
        (SELECT MAX(m.area_jo) FROM studio_room m WHERE m.studio_id = s.studio_id) AS max_area,
        EXISTS (SELECT 1 FROM studio_rate r WHERE r.studio_id = s.studio_id AND r.people IS NOT NULL) AS has_solo,
        EXISTS (SELECT 1 FROM studio_pack p WHERE p.studio_id = s.studio_id) AS has_pack
    FROM studio s ORDER BY s.sort_order, s.studio_id')->fetchAll();

// 地図に渡すのは座標が入っているスタジオだけ
$pins = [];
foreach ($studios as $s) {
    if ($s['lat'] !== null && $s['lng'] !== null) {
        $pins[] = [
            'id' => (int)$s['studio_id'],
            'name' => $s['name'],
            'lat' => (float)$s['lat'],
            'lng' => (float)$s['lng'],
            'sub' => $s['access'] ?? '',
        ];
    }
}

// 絞り込み欄は、広さが入っているスタジオが1つでもあるときだけ出す（全部不明なら絞り込む意味がない）
$hasArea = array_filter(array_column($studios, 'max_area'), fn($v) => $v !== null) !== [];

render_header('スタジオ情報', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/vendor/leaflet/leaflet.css') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/studio_info.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a></nav>
<h1 class="display display--sm">スタジオ情報</h1>

<?php if ($pins): ?>
    <!-- スタジオマップ。data-pins の中身は JS（assets/studio_info.js）が読んでピンを立てる -->
    <div class="card sinfo-mapcard">
        <div class="sinfo-map sinfo-map--list" id="studio-map" role="region" aria-label="スタジオマップ"></div>
    </div>
    <script type="application/json" id="studio-pins"><?= json_encode($pins, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>

<?php if ($hasArea): ?>
    <!-- 広さの絞り込み: 「N帖以上の部屋があるスタジオ」だけ残す（JS: setupFilter）。0 = 絞り込みなし。＋／－は共通の setupStepper -->
    <div class="card sinfo-filter" id="studio-filter">
        <span class="sinfo-filter__label" title="広さ"><?= icon('square_foot') ?></span>
        <div class="sinfo-step">
            <button type="button" class="sinfo-step__btn" data-step="-1" aria-label="減らす"><?= icon('remove') ?></button>
            <input type="number" min="0" max="100" value="0" inputmode="numeric" aria-label="広さ（帖）の下限">
            <button type="button" class="sinfo-step__btn" data-step="1" aria-label="増やす"><?= icon('add') ?></button>
        </div>
        <span class="muted">帖以上</span>
        <span class="muted small" data-filter-count></span>
    </div>
<?php endif; ?>

<?php if (!$studios): ?>
    <div class="empty card"><p class="empty__title">スタジオがありません</p></div>
<?php else: ?>
<p class="muted small" data-filter-empty hidden><?= icon('search_off') ?> 該当なし</p>
<ul class="sinfo-list">
    <?php foreach ($studios as $s): ?>
        <li>
            <a class="card sinfo-list__item" href="studio_info?id=<?= (int)$s['studio_id'] ?>" data-studio-id="<?= (int)$s['studio_id'] ?>" data-max-area="<?= h($s['max_area'] === null ? '' : studio_jo_num($s['max_area'])) ?>">
                <span class="sinfo-list__name">
                    <?= h($s['name']) ?>
                    <?php if ($s['min_student'] !== null): ?><span class="tag tag--plan">学割</span><?php endif; ?>
                    <?php if ($s['has_solo']): ?><span class="tag">個人</span><?php endif; ?>
                    <?php if ($s['has_pack']): ?><span class="tag">パック</span><?php endif; ?>
                </span>
                <span class="muted small sinfo-list__meta">
                    <?php if ($s['access']): ?><span><?= icon('train') ?> <?= h($s['access']) ?></span><?php endif; ?>
                    <?php if ($s['max_area'] !== null): ?><span title="いちばん広い部屋"><?= icon('square_foot') ?> <?= h(studio_fmt_jo($s['max_area'])) ?></span><?php endif; ?>
                    <?php if ($s['min_price'] !== null): ?><span title="1時間の最安料金"><?= icon('payments') ?> <?= h(studio_yen((int)$s['min_price'])) ?>〜/h</span><?php endif; ?>
                    <?php if ($s['lat'] === null): ?><span title="地図に未登録"><?= icon('location_off') ?></span><?php endif; ?>
                </span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($canEdit): ?>
    <details class="card sinfo-edit sinfo-section">
        <summary><?= icon('add') ?> スタジオを追加 <span class="tag">幹部</span></summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <div class="sinfo-edit__row">
                <input autocomplete="off" type="text" class="grow" name="name" maxlength="100" required placeholder="スタジオ名" aria-label="スタジオ名">
                <button type="submit" class="btn btn--primary btn--sm"><?= icon('add') ?> 追加</button>
            </div>
        </form>
    </details>
<?php endif; ?>

<script src="<?= asset_url('assets/vendor/leaflet/leaflet.js') ?>"></script>
<script src="<?= asset_url('assets/studio_info.js') ?>"></script>
<?php render_footer();

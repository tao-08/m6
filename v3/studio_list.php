<?php
/**
 * =====================================================================
 *  studio_list.php — スタジオ情報の一覧＋スタジオマップ（tools.php から入る）
 * =====================================================================
 *  表示: 全スタジオのピンを1枚の地図（Leaflet + OpenStreetMap）に出し、その下にカードの一覧
 *        カードには 最寄り駅・最大の部屋の広さ（MAXバッジ）・バンドと個人（1人）のいちばん安い1時間の料金・割引区分（学割など）・パックの有無 を出す
 *  追加: ログインしている人なら誰でも（名前だけ入れて作り、詳しい情報は studio_info.php で編集）。削除したスタジオは「削除済み」から復元できる
 *
 *  ■ Leaflet（リーフレット）とは
 *    地図を表示する JavaScript ライブラリ。地図の「絵」（タイル画像）は別の所から借りてくる。
 *    今回は OpenStreetMap（みんなで作る無料の地図）のタイルを使う → API キー・課金設定が要らない。
 *    ライブラリ本体は CDN ではなく assets/vendor/leaflet に置いた（外部サービスが止まっても動くように）
 *
 *  セキュリティ:
 *    ・POST（追加・復元）は can_edit_studio()（ログイン済みか）で権限を確認 → verify_csrf() で CSRF を確認
 *    ・SQL はプリペアドステートメント、画面の文字は h() で XSS 対策
 *    ・地図用のデータは json_encode(JSON_HEX_TAG …) で <script> の中に安全に埋め込む
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/studio_info.php';
require_once __DIR__ . '/lib/studio_history.php';
require_login();
$pdo = db();
$canEdit = can_edit_studio(); // ログインしている人なら誰でも追加・編集できる（変更は編集履歴に残り、復元できる）

// =====================================================================
//  POST（スタジオの追加 / 削除したスタジオの復元）
// =====================================================================
if (is_post()) {
    if (!can_edit_studio()) { // 画面にフォームを出していなくても、送られてきたら必ずサーバー側で権限を確かめる
        error_page(403, '編集できません');
    }
    verify_csrf();
    // 削除したスタジオを、削除する前の状態で復元する（履歴の番号から。lib/studio_history.php）
    if (($_POST['action'] ?? '') === 'restore_studio') {
        try {
            $id = studio_history_restore($pdo, (int)($_POST['history_id'] ?? 0), (int)current_user()['user_id']);
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
            redirect('studio_list');
        }
        flash('復元しました');
        redirect('studio_info?id=' . $id);
    }
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
// MIN() = 最小値。相関サブクエリでスタジオごとに「バンドのいちばん安い1時間の料金」「割引区分の名前」をまとめて取る
// GROUP_CONCAT = 複数行の値を1つの文字列につなげる（区切りはタブ。区分名にタブは入らないので、あとで explode で分けられる）
//   \'\t\' = PHP の '…' の中に SQL の文字列 '\t'（タブ）を書くため、' の前に \ を付けている
// EXISTS (SELECT 1 …) = 1行でもあれば 1。パックがあるかのタグに使う（件数は要らないので COUNT より速い）
//   max_area = そのスタジオでいちばん広い部屋の帖数（広さの絞り込み用。部屋の広さが未入力なら NULL）
//   （スタジオの数だけ SQL を投げる「N+1 問題」を避けるため、1回の SQL で済ませる）
$studios = $pdo->query('SELECT s.studio_id, s.name, s.access, s.area, s.recommended, s.late_night, s.hours, s.lat, s.lng, s.reserve_url,
        (SELECT MIN(r.price) FROM studio_rate r WHERE r.studio_id = s.studio_id AND r.people IS NULL) AS min_price,
        (SELECT MIN(r.price) FROM studio_rate r WHERE r.studio_id = s.studio_id AND r.people = 1) AS min_solo_price,
        (SELECT GROUP_CONCAT(d.name ORDER BY d.sort_order, d.studio_discount_id SEPARATOR \'\t\') FROM studio_discount d
            WHERE d.studio_id = s.studio_id) AS disc_names,
        (SELECT MAX(m.area_jo) FROM studio_room m WHERE m.studio_id = s.studio_id) AS max_area,
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

$campuses = studio_campuses(); // 明治大学の4キャンパス（lib/studio_info.php）
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// 絞り込み欄は、広さが入っているスタジオが1つでもあるときだけ出す（全部不明なら絞り込む意味がない）
$hasSize = array_filter(array_column($studios, 'max_area'), fn($v) => $v !== null) !== [];
// エリアの候補: 入力されているエリア名（並びは一覧の並びで最初に出た順）。1つも入っていなければエリアのタブは出さない
$areas = studio_areas($pdo); // 並び順は「エリアを編集」で決めた順

// 料金比較用のデータ（JS: setupListSim）。[スタジオid => 試算のデータ]。レンタルは比較に使わないので空
//   スタジオごとに読むので SQL はスタジオ数に比例して増える（数十件までの想定。増えて遅くなったらまとめて読む形に変える）
$simAll = [];
foreach ($studios as $s) {
    $simAll[(int)$s['studio_id']] = studio_sim_load($pdo, (int)$s['studio_id']);
}

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
    <script type="application/json" id="studio-pins"><?= json_encode($pins, $jsonFlags) ?></script>
    <script type="application/json" id="studio-campuses" data-logo="<?= h(asset_url('assets/meiji-logo.svg')) ?>"><?= json_encode($campuses, $jsonFlags) ?></script>
<?php endif; ?>

<?php if ($areas || $hasSize): ?>
    <!-- 絞り込み（JS: setupFilter）。エリアと広さの両方があるときはタブで切り替える（両方の条件が同時に効く）
         ・エリア: 選んだエリアのスタジオだけ残す。広さ: 「N帖以上の部屋があるスタジオ」だけ残す（既定 10帖。0 = 絞り込みなし）。＋／－は共通の setupStepper
         ・件数（N / M）は、絞り込みで隠れたスタジオがあるときだけ出す -->
    <?php $filterTabs = $areas && $hasSize; ?>
    <div class="card sinfo-filter" id="studio-filter">
        <?php if ($filterTabs): ?>
            <div class="sinfo-seg sinfo-filter__tabs" role="radiogroup" aria-label="絞り込み">
                <label><input type="radio" name="filter_tab" value="area"> エリア</label>
                <label><input type="radio" name="filter_tab" value="size" checked> 広さ</label>
            </div>
        <?php endif; ?>
        <?php if ($areas): ?>
            <div class="sinfo-filter__panel" data-filter-panel="area"<?= $filterTabs ? ' hidden' : '' ?>>
                <select name="area" aria-label="エリア">
                    <option value="">すべて</option>
                    <?php foreach ($areas as $a): ?><option value="<?= h($a) ?>"><?= h($a) ?></option><?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($hasSize): ?>
            <div class="sinfo-filter__panel" data-filter-panel="size">
                <div class="sinfo-step">
                    <button type="button" class="sinfo-step__btn" data-step="-1" aria-label="減らす"><?= icon('remove') ?></button>
                    <input type="number" name="size" min="0" max="100" value="10" inputmode="numeric" aria-label="広さ（帖）の下限">
                    <button type="button" class="sinfo-step__btn" data-step="1" aria-label="増やす"><?= icon('add') ?></button>
                </div>
                <span class="muted">帖以上</span>
                <span class="sinfo-filter__info" data-filter-people></span>
            </div>
        <?php endif; ?>
        <span class="muted small" data-filter-count></span>
    </div>
<?php endif; ?>

<?php if ($studios): ?>
    <!-- 料金比較: 開くと、この条件で全スタジオを計算してカードに出し、安い順に並べる（JS: setupListSim）。人数の＋／－は共通の setupStepper
         縦を詰めるため、項目名は付けず（中身で分かる）、「割引」は対象の行の右に置く -->
    <details class="card togglecard sinfo-edit sinfo-section" id="studio-list-sim">
        <summary><?= icon('calculate') ?> <span class="togglecard__title">料金シミュレーション</span><?= icon('expand_more', 'togglecard__chevron') ?></summary>
        <form class="sinfo-sim sinfo-sim--compact sinfo-edit__form togglecard__body" onsubmit="return false">
            <div class="sinfo-sim__top">
                <div class="sinfo-seg sinfo-seg--mode" role="radiogroup" aria-label="対象">
                    <label><input type="radio" name="mode" value="band" checked> <?= icon('groups') ?> バンド練習</label>
                    <label><input type="radio" name="mode" value="solo"> <?= icon('person') ?> 個人練習</label>
                </div>
                <label class="sinfo-chip"><input type="checkbox" name="disc" value="1"> 割引</label>
            </div>
            <div class="sinfo-sim__grid">
                <div class="sinfo-seg" role="radiogroup" aria-label="曜日">
                    <label><input type="radio" name="day" value="1" checked> 平日</label>
                    <label><input type="radio" name="day" value="3"> 土</label>
                    <label><input type="radio" name="day" value="4"> 日祝</label>
                </div>
                <select name="start" aria-label="開始"><?= studio_time_options(16 * 60) ?></select>
                <select name="dur" aria-label="時間">
                    <?php for ($m = 60; $m <= 600; $m += 30): ?>
                        <option value="<?= $m ?>"<?= $m === 120 ? ' selected' : '' ?>><?= h(studio_fmt_hours($m)) ?></option>
                    <?php endfor; ?>
                </select>
                <div class="sinfo-step">
                    <button type="button" class="sinfo-step__btn" data-step="-1" aria-label="減らす"><?= icon('remove') ?></button>
                    <input type="number" name="people" min="1" max="30" value="4" inputmode="numeric" aria-label="人数">
                    <span class="sinfo-step__unit muted">人</span>
                    <button type="button" class="sinfo-step__btn" data-step="1" aria-label="増やす"><?= icon('add') ?></button>
                </div>
            </div>
            <!-- 人数に合わせた広さの絞り込み（JS: setupListSim が人数の変更で広さの絞り込みを書き換え、ここに現在の値を出す） -->
            <span class="muted small" data-sim-filter hidden></span>
        </form>
    </details>
    <script type="application/json" id="studio-sim-all"><?= json_encode($simAll, $jsonFlags) ?></script>
<?php endif; ?>

<?php if (!$studios): ?>
    <div class="empty card"><p class="empty__title">スタジオがありません</p></div>
<?php else: ?>
<p class="muted small" data-filter-empty hidden><?= icon('search_off') ?> 該当なし</p>
<ul class="sinfo-list is-enter">
    <?php foreach ($studios as $i => $s): ?>
        <li style="--i: <?= (int)$i ?>">
            <!-- カード = div。<a> の中に <a>（予約ボタン）を入れるのは HTML のルール違反（リンクの入れ子は禁止）なので、
                 「詳細へのリンク（本体）」と「予約ボタン」を、div の中に横並びの兄弟として置く。
                 data-studio-id / data-max-area は JS（絞り込み・地図のピン連動）が読むので、外側の div に付けたまま -->
            <div class="card sinfo-list__item" data-studio-id="<?= (int)$s['studio_id'] ?>" data-max-area="<?= h($s['max_area'] === null ? '' : studio_jo_num($s['max_area'])) ?>" data-area="<?= h($s['area'] ?? '') ?>">
            <a class="sinfo-list__link" href="studio_info?id=<?= (int)$s['studio_id'] ?>">
                <!-- 1段め: スタジオ名（と おすすめ・深夜早朝）。割引のタグ（学割など）とパックは2段めの先頭へ -->
                <span class="sinfo-list__name"><?= h($s['name']) ?><?php if ($s['recommended']): ?><span class="tag tag--rec">おすすめ</span><?php endif; ?><?php if ($s['late_night']): ?><span class="tag tag--night">深夜早朝</span><?php endif; ?></span>
                <span class="sinfo-list__sim" data-list-sim hidden></span>
                <span class="muted small sinfo-list__meta">
                    <?php $discTags = $s['disc_names'] === null ? [] : explode("\t", $s['disc_names']); // 割引区分の名前（学割・U20 …） ?>
                    <?php if ($discTags || $s['has_pack']): ?>
                        <span class="sinfo-list__tags">
                            <?php foreach ($discTags as $t): ?><span class="tag tag--plan"><?= h($t) ?></span><?php endforeach; ?>
                            <?php if ($s['has_pack']): ?><span class="tag">パック</span><?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($s['access']): ?><span><?= icon('train') ?> <?= h($s['access']) ?></span><?php endif; ?>
                    <?php if ($s['hours']): ?><span><?= icon('schedule') ?> <?= h($s['hours']) ?></span><?php endif; ?>
                    <!-- 広さ: 枠なしの小さな薄い「MAX」＋帖数（= いちばん広い部屋だとひと目でわかる）。
                         .sinfo-area が inline-flex（中身を横並びにして align-items:center で上下中央にそろえる）。CSS: .sinfo-area / .sinfo-max -->
                    <?php if ($s['max_area'] !== null): ?><span class="sinfo-area" title="いちばん広い部屋"><span class="sinfo-max">MAX</span><?= h(studio_fmt_jo($s['max_area'])) ?></span><?php endif; ?>
                    <?php if ($s['min_price'] !== null): ?><span title="バンド練習の1時間の最安料金"><?= icon('payments') ?> <?= h(studio_yen((int)$s['min_price'])) ?>〜/h</span><?php endif; ?>
                    <!-- 個人練習（1人利用）の最安。バンドの最安の隣に、人のアイコンで並べる。個人1人の料金が無ければ出さない（min_solo_price が NULL） -->
                    <?php if ($s['min_solo_price'] !== null): ?><span title="個人練習（1人）の1時間の最安料金"><?= icon('person') ?> <?= h(studio_yen((int)$s['min_solo_price'])) ?>〜/h</span><?php endif; ?>
                    <?php if ($s['lat'] === null): ?><span title="地図に未登録"><?= icon('location_off') ?></span><?php endif; ?>
                </span>
            </a>
            <?php if ($s['reserve_url']): ?>
                <!-- 予約ページへ（別タブ）。URL は保存時に http(s):// だけ許可している（studio_url_ok）ので javascript: などは入らない。
                     表示時にも h() で XSS 対策。noopener noreferrer = 開いた先のページから、こちらのページを操作・追跡させない -->
                <a class="btn btn--primary btn--sm sinfo-list__reserve" href="<?= h($s['reserve_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('event_available') ?> 予約</a>
            <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($canEdit): ?>
    <details class="card togglecard sinfo-edit sinfo-section">
        <summary><?= icon('add') ?> <span class="togglecard__title">スタジオを追加</span><?= icon('expand_more', 'togglecard__chevron') ?></summary>
        <form method="post" class="sinfo-edit__form togglecard__body">
            <?= csrf_field() ?>
            <div class="sinfo-edit__row">
                <input autocomplete="off" type="text" class="grow" name="name" maxlength="100" required placeholder="スタジオ名" aria-label="スタジオ名">
                <button type="submit" class="btn btn--primary btn--sm"><?= icon('add') ?> 追加</button>
            </div>
        </form>
    </details>
    <?php
    // 削除したスタジオ（削除前の状態に復元できる）。表をまだ作っていない環境でもページは開けるよう、読めなければ出さない
    $deleted = [];
    try {
        $deleted = studio_history_deleted($pdo);
    } catch (PDOException $e) {
        error_log('studio_history_deleted: ' . $e->getMessage());
    }
    ?>
    <?php if ($deleted): ?>
    <details class="card togglecard sinfo-edit sinfo-section">
        <summary><?= icon('delete') ?> <span class="togglecard__title">削除済み <span class="tag"><?= count($deleted) ?></span></span><?= icon('expand_more', 'togglecard__chevron') ?></summary>
        <ul class="sinfo-history togglecard__body">
            <?php foreach ($deleted as $d): ?>
                <li>
                    <span class="sinfo-history__what"><?= h($d['studio_name']) ?></span>
                    <span class="muted small"><?= h($d['user_name'] ?? '不明') ?> ・ <?= h(date('n/j H:i', strtotime((string)$d['created_at']))) ?></span>
                    <form method="post" class="sinfo-history__restore">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="restore_studio">
                        <input type="hidden" name="history_id" value="<?= (int)$d['history_id'] ?>">
                        <button type="submit" class="btn btn--sm" title="削除前の状態に復元" aria-label="<?= h($d['studio_name']) ?>を復元"><?= icon('restore') ?> 復元</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
<?php endif; ?>

<script src="<?= asset_url('assets/vendor/leaflet/leaflet.js') ?>"></script>
<script src="<?= asset_url('assets/studio_sim.js') ?>"></script><!-- 料金の計算（studio_info.js より先に読む） -->
<script src="<?= asset_url('assets/studio_info.js') ?>"></script>
<?php render_footer();

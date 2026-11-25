<?php
/**
 * =====================================================================
 *  tools.php — ツール（バンドやライブに紐づかない道具の入口）
 * =====================================================================
 *  今あるもの:
 *    ・セッティングシート（白紙から）… setting_sheet.php を band なしで開く
 *  道具が増えたら、下の $tools に1行足すだけでカードが増える。
 *
 *  ここは表示するだけ（POST を受けない）ので CSRF 対策は不要。
 *  DB を読むのは「回答受付中のスタジオ割に、自分のバンドがあるか」だけ（あればカードを出す）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();
require_once __DIR__ . '/lib/repository.php';

// [行き先, アイコン, 名前, 説明, タグ（権限のラベル。無ければ省略）]
$tools = [
    // 楽曲データベース・練習プレイヤー（ログインしていれば誰でも。TAB 譜の作成・編集だけ管理人）
    ['songs', 'library_music', '楽曲データベース',
        'これまで演奏された楽曲の情報が確認できます。練習用の動画や耳コピメモなどの登録もできます。'],
    ['practice', 'slow_motion_video', '練習プレイヤー',
        'YouTubeの動画をリピート機能やチャプター機能がついた練習向きのプレイヤーで再生します。'],
    ['setting_sheet', 'description', 'セッティングシート作成',
        'セットリストやセット図を記入し、提出用の PDF を作成します。 ライブデータが登録されていれば、ナビゲーションバーの ライブ→各バンドのページ から自動で作成できます。'],
    // 型紙（会場ごとの PDF と座標）の登録。誰でも作れる（編集・削除は作った人と管理者だけ）
    ['sheet_templates', 'edit_document', 'セッティングシート編集（PC推奨）',
        '新しい会場のセッティングシートの PDF を登録し要素を配置して、セッティングシート作成用のテンプレートを編集します。'],
    // 会場の最寄り駅・機材・過去のライブ（誰でも見られて、誰でも編集できる）
    ['venue_list', 'location_on', 'ライブ会場リスト', '会場の最寄り駅・機材・過去のライブ'],
    // 年度・ライブに付けるリンクとファイル（誰でも追加。削除は追加した人と管理者 = special.php）
    ['special', 'redeem', 'スペシャルコンテンツ', 'みんなが作ったライブごとや年度ごとの動画・ファイルなどが閲覧できます。'],
    // 練習スタジオの情報・料金シミュレーション・地図（見るのも編集もログインしていれば誰でも。変更は編集履歴から復元できる = studio_info.php）
    ['studio_list', 'piano', 'スタジオ情報', '練習スタジオの料金・学割・機材・予約ページ。料金シミュレーションとスタジオマップ付き。'],
    // 落ちものパズル（ログインしていれば誰でも。点数は自己ベストだけ block_best に残り、ランキングに出る）
    ['block', 'grid_view', 'ブロックパズル', 'テ◯リスができます。月間ランキング付き。'],
    // イントロクイズ（ログインしていれば誰でも。点数は自己ベストだけ intro_best に残り、ランキングに出る）
    ['intro', 'headphones', 'イントロクイズ', 'サークルで演奏された曲の試聴を聴いて曲名を当てます。月間ランキング付き。'],
];
// 名簿から出順を決める（管理者だけ。公開するまでは部員に見せない）
if (is_executive()) {
    $tools[] = ['tt_builder', 'view_timeline', 'タイムテーブル作成',
        '名簿または登録済みの予定ライブから、日程希望・連続出演・学年を見ながら出順を決めます。', '幹部'];
    // ライブ前のスタジオ練習の部屋割り（studio.php）
    $tools[] = ['studio', 'meeting_room', 'スタジオ割',
        '練習の要否を集め、メンバーが重ならないように練習の順番と部屋を決めて画像にします。', '幹部'];
}
// バンド希望調査（lib/band_survey.php の条件を満たす人だけ）
require_once __DIR__ . '/lib/band_survey.php';
if (band_survey_can_view()) {
    $tools[] = ['band_survey', 'poll', 'バンド希望調査', '部員の希望順位から、バンドごとの優先度を集計します。', '幹部'];
}
// 試聴の一括調査（管理者だけ。イントロクイズで出せる曲を増やす）
if (is_admin()) {
    $tools[] = ['intro_previews', 'headphones', '試聴の一括調査',
        '試聴を調べていない曲を探して、イントロクイズで出せる曲を増やします。', '管理者'];
}
// スタ連（スタジオ練習）の日程調整（sched.php）。ログインしていれば誰でも
$tools[] = ['sched', 'event_available', 'スタジオ練習の日程調整',
    '時間割・カレンダーからメンバー全員の空きを出し、スタジオの料金と一緒に候補を並べます。'];
// 回答受付中のスタジオ割に自分が出るバンドがあれば、回答ページのカードをタブの上に出す
$answerCards = []; // 回答受付中のカード。タブの上に常に出す
foreach (studio_answer_open_lives(db(), (int)current_user()['user_id']) as $r) {
    $answerCards[] = ['studio_answer?live=' . (int)$r['live_id'], 'meeting_room', $r['name'] . ' スタジオ練習', '練習の要否を回答してください。', '回答受付中'];
}
foreach (!empty(current_user()['member_id']) ? sched_todo_polls(db(), (int)current_user()['member_id']) : [] as $p) {
    $answerCards[] = ['sched_me?poll=' . (int)$p['sched_poll_id'], 'event_available', $p['title'] . ' 日程調整', date('n/j', strtotime((string)$p['date_from'])) . '〜' . date('n/j', strtotime((string)$p['date_to'])) . ' の空きを回答してください。', '未回答'];
}

// タブ: 行き先 => タブ。ここに無いツールは「便利ツール」に入る
$tabs = ['data' => 'データ', 'useful' => '便利ツール', 'admin' => '運営ツール', 'game' => 'ゲーム'];
$tabOf = [
    'songs' => 'data', 'venue_list' => 'data', 'studio_list' => 'data', 'special' => 'data',
    'tt_builder' => 'admin', 'studio' => 'admin', 'sheet_templates' => 'admin', 'intro_previews' => 'admin', 'band_survey' => 'admin',
    'block' => 'game', 'intro' => 'game',
];
$grouped = array_fill_keys(array_keys($tabs), []);
foreach ($tools as $t) {
    $grouped[$tabOf[$t[0]] ?? 'useful'][] = $t;
}
$grouped = array_filter($grouped); // 中身が無いタブ（幹部でないときの運営ツール）は出さない
$tab = (string)($_GET['tab'] ?? '');
if (!isset($grouped[$tab])) {
    $tab = (string)array_key_first($grouped);
}

render_header('ツール', 'tools');
?>
<h1 class="display display--sm">ツール</h1>
<?php if ($answerCards): ?>
<div class="tool-list">
    <?php foreach ($answerCards as $t): [$href, $ic, $name, $desc] = $t; ?>
        <a class="card tool-card" href="<?= h($href) ?>">
            <span class="tool-card__icon"><?= icon($ic) ?></span>
            <span class="tool-card__body">
                <span class="tool-card__name"><?= h($name) ?> <span class="tag"><?= h($t[4]) ?></span></span>
                <span class="muted small"><?= h($desc) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<nav class="tabs tabs--static" aria-label="ツールの種類" data-tool-tabs>
    <?php foreach (array_keys($grouped) as $k): ?>
        <a class="tab<?= $k === $tab ? ' is-active' : '' ?>" href="tools?tab=<?= h($k) ?>" data-tab="<?= h($k) ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= h($tabs[$k]) ?></a>
    <?php endforeach; ?>
</nav>
<?php foreach ($grouped as $k => $list): ?>
<div class="tool-list" data-tool-pane="<?= h($k) ?>"<?= $k === $tab ? '' : ' hidden' ?>>
    <?php foreach ($list as $t): [$href, $ic, $name, $desc] = $t; $tag = $t[4] ?? null; ?>
        <a class="card tool-card" href="<?= h($href) ?>">
            <span class="tool-card__icon"><?= icon($ic) ?></span>
            <span class="tool-card__body">
                <span class="tool-card__name"><?= h($name) ?><?= $tag !== null ? ' <span class="tag">' . h($tag) . '</span>' : '' ?></span>
                <span class="muted small"><?= h($desc) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<p class="muted small" data-tool-pane="useful"<?= $tab === 'useful' ? '' : ' hidden' ?>>登録済みのバンドのシートは、バンドのページの「セッティングシート作成」から作ると、メンバーと曲が最初から入ります。</p>
<?php render_footer();

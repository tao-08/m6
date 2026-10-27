<?php
/**
 * =====================================================================
 *  studio_answer.php — 「スタジオ練習が必要か」の回答ページ（部員向け）
 * =====================================================================
 *  入り方は2つ（studio.php の編集画面からリンクをコピーして LINE などに貼る）
 *    studio_answer?t=合言葉  … 共有リンク。ログイン不要で、そのライブの全バンドに答えられる
 *                               （ログインしていれば、自分のバンドに印が付き、誰が答えたかも残る）
 *    studio_answer?live=12  … ログインした部員用。自分が出るバンドだけ出す
 *  POST（CSRF チェック）
 *    answer … band と need（1 = 必要 / 0 = 不要）を保存して、同じページに戻る
 *
 *  PRG パターン（Post → Redirect → Get）:
 *    POST を受けたら、結果を画面に直接出さずに GET のページへリダイレクトする。
 *    こうすると、ブラウザの「再読み込み」で同じ POST がもう一度送られる事故（二重送信）が起きない。Web の定番。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/studio.php';
$user = current_user();
$pdo = db();

// ---- どのスタジオ割か（合言葉 か ライブの id）----
$token = is_string($_REQUEST['t'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $_REQUEST['t']) ? $_REQUEST['t'] : null;
$plan = null;
if ($token !== null) {
    // 合言葉で探す。プリペアドステートメントなので、t に SQL を混ぜられても文字列として扱われるだけ
    $st = $pdo->prepare('SELECT live_id, answer_open, share_token FROM studio_plan WHERE share_token = ?');
    $st->execute([$token]);
    $plan = $st->fetch() ?: null;
    // hash_equals: 文字列を「かかる時間が一定」で比べる。合言葉の比較で使う定番（時間差から1文字ずつ当てられる攻撃＝タイミング攻撃の対策）
    if ($plan !== null && !hash_equals((string)$plan['share_token'], $token)) {
        $plan = null;
    }
} else {
    $user = require_login(); // live= で来たときはログイン必須
    $st = $pdo->prepare('SELECT live_id, answer_open FROM studio_plan WHERE live_id = ?');
    $st->execute([(int)($_REQUEST['live'] ?? 0)]);
    $plan = $st->fetch() ?: null;
}
if ($plan === null) {
    error_page(404, 'リンクが無効です。最新のリンクを確認してください');
}
$liveId = (int)$plan['live_id'];
$open = (bool)$plan['answer_open'];
$myMember = studio_my_member_id($user);

$data = studio_bands($pdo, $liveId);
// 自分が出るバンドかどうか（members の中に自分の member_id がいるか）
$isMine = static function (array $band) use ($myMember): bool {
    return $myMember !== null && in_array($myMember, array_column($band['members'], 'id'), true);
};
// 見せるバンド: 共有リンクなら全部、ログイン用なら自分のバンドだけ
$bands = $token !== null ? $data['bands'] : array_filter($data['bands'], $isMine);
$self = $token !== null ? 'studio_answer?t=' . $token : 'studio_answer?live=' . $liveId; // 戻り先

// ---------- 回答の保存 ----------
if (is_post()) {
    verify_csrf();
    $band = (int)($_POST['band'] ?? 0);
    $need = ($_POST['need'] ?? '') === '1' ? 1 : (($_POST['need'] ?? '') === '0' ? 0 : null);
    if (!$open) {
        flash('締め切られています', 'error');
    } elseif ($need === null || !isset($bands[$band])) {
        // 見せていないバンド（他のライブのバンド・ログイン用で自分のバンドでない）は受け付けない
        flash('回答できません', 'error');
    } else {
        studio_save_need($pdo, $band, $need, $user !== null ? (int)$user['user_id'] : null, $token !== null);
        flash($bands[$band]['name'] . '：' . ($need ? '練習あり' : '練習なし'));
    }
    redirect($self);
}

$st = $pdo->prepare('SELECT name FROM live WHERE live_id = ?');
$st->execute([$liveId]);
$liveName = (string)$st->fetchColumn();
$needs = studio_needs($pdo, array_keys($bands));
$multi = count($data['days']) > 1;

render_header($liveName . ' — スタジオ練習', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/studio.css') ?>">
<h1 class="display display--sm"><?= h($liveName) ?></h1>
<p class="muted small std-ans__lead"><?= icon('meeting_room') ?> スタジオ練習の要否<?= $open ? '' : ' <span class="tag">締切</span>' ?></p>

<?php if (!$bands): ?>
    <div class="empty card"><p class="empty__title">出演するバンドがありません</p></div>
<?php endif; ?>

<div class="std-ans">
    <?php $curDay = 0; foreach ($bands as $b): ?>
        <?php if ($multi && $b['day'] !== $curDay): $curDay = $b['day']; ?>
            <h2 class="std-ans__day"><?= h($data['days'][$curDay - 1]['label'] ?? '') ?></h2>
        <?php endif; ?>
        <?php $n = $needs[$b['id']] ?? null; $mine = $isMine($b); ?>
        <div class="card std-ans__band<?= $mine ? ' is-mine' : '' ?>">
            <div class="std-ans__head">
                <span class="std-ans__name"><?= h($b['name']) ?></span>
                <?php if ($mine): ?><span class="tag tag--mine">出演</span><?php endif; ?>
            </div>
            <?php // メンバー名はログインしている人にだけ出す（サイトの他のページもログイン必須。リンクが外に漏れても名前は漏れないように）
            if ($user !== null): ?>
                <p class="muted small std-ans__members"><?= h(implode('・', array_column($b['members'], 'name'))) ?></p>
            <?php endif; ?>
            <form method="post" class="std-ans__form">
                <?= csrf_field() ?>
                <input type="hidden" name="band" value="<?= (int)$b['id'] ?>">
                <?php if ($token !== null): ?><input type="hidden" name="t" value="<?= h($token) ?>"><?php else: ?><input type="hidden" name="live" value="<?= $liveId ?>"><?php endif; ?>
                <div class="ttb-seg std-ans__seg" role="group" aria-label="練習">
                    <!-- 押したボタンの value が need として送られる（button の name/value はフォームの値になる） -->
                    <button type="submit" name="need" value="1" class="<?= ($n['need'] ?? null) === 1 ? 'is-on' : '' ?>" <?= $open ? '' : 'disabled' ?>><?= icon('check') ?> 必要</button>
                    <button type="submit" name="need" value="0" class="<?= ($n['need'] ?? null) === 0 ? 'is-on' : '' ?>" <?= $open ? '' : 'disabled' ?>><?= icon('close') ?> 不要</button>
                </div>
                <?php // 回答した人の名前もログインしている人にだけ（未ログインには日時だけ） ?>
                <span class="muted small"><?= $n === null ? '未回答' : h(($user !== null ? ($n['by'] ?? 'リンク') . ' ' : '') . $n['at']) ?></span>
            </form>
        </div>
    <?php endforeach; ?>
</div>
<?php render_footer();

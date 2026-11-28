<?php
/**
 * =====================================================================
 *  lib/special.php — スペシャルコンテンツ（special_content。説明は migrations/135）
 * =====================================================================
 *  登録・削除は special.php。ここは読み込みと、アイコン＋ポップの HTML だけ。
 *  ポップは assets/app_ui.js の setupTip（data-tip-from）をそのまま使う
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/live_image.php';
require_once __DIR__ . '/youtube.php';

/**
 * 見られるものだけに絞る WHERE の条件（$a は special_content の別名）。
 * 入部年度の指定が無い・自分の入部年度が入っている・自分が追加した・管理者なら見られる。値は int にしてから埋め込む
 */
function special_visible_sql(string $a = 'special_content'): string
{
    if (is_admin()) {
        return '1';
    }
    $years = "SELECT entry_year FROM special_view_year v WHERE v.special_id = $a.special_id";
    $ey = my_entry_year();
    return "(NOT EXISTS ($years) OR $a.user_id = " . (int)(current_user()['user_id'] ?? 0)
        . ($ey !== null ? " OR $ey IN ($years)" : '') . ')';
}

/** @return array<int, int[]> special_id => 見られる入部年度（新しい順） */
function special_view_years(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT special_id, entry_year FROM special_view_year ORDER BY entry_year DESC') as $r) {
        $out[(int)$r['special_id']][] = (int)$r['entry_year'];
    }
    return $out;
}

/** 見られる入部年度を入れ直す（空なら全員） */
function special_save_years(PDO $pdo, int $id, array $years): void
{
    $pdo->prepare('DELETE FROM special_view_year WHERE special_id = ?')->execute([$id]);
    $ins = $pdo->prepare('INSERT INTO special_view_year (special_id, entry_year) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $years)) as $y) {
        if ($y >= 1900 && $y <= 2100) {
            $ins->execute([$id, $y]);
        }
    }
}

/** @return array{year: array<int, array>, live: array<int, array>} 年度全体のもの / ライブごとのもの（古い順） */
function special_load(PDO $pdo): array
{
    $out = ['year' => [], 'live' => []];
    foreach ($pdo->query('SELECT * FROM special_content WHERE ' . special_visible_sql() . ' ORDER BY special_id') as $r) {
        if ($r['live_id'] === null) {
            $out['year'][(int)$r['fiscal_year']][] = $r;
        } else {
            $out['live'][(int)$r['live_id']][] = $r;
        }
    }
    return $out;
}

/** 開く先。ファイルは attach_image.php がログインを確かめてから出す */
function special_href(array $r): string
{
    return $r['file_name'] !== null ? 'attach_image?k=special&id=' . (int)$r['special_id'] : (string)$r['url'];
}

/** 種類: yt（YouTube）/ image / pdf / other（その他のファイル）/ link */
function special_kind(array $r): string
{
    return match (true) {
        $r['file_name'] === null => $r['url'] !== null && youtube_video_id((string)$r['url']) !== null ? 'yt' : 'link',
        live_file_is_pdf((string)$r['file_name']) => 'pdf',
        live_file_is_other((string)$r['file_name']) => 'other',
        default => 'image',
    };
}

/** 1件のリンク（アイコン＋タイトル）。動画・画像・PDF は、スペシャルコンテンツのページでポップアップを開く（special.js が ?open=ID を見る） */
function special_link_html(array $r): string
{
    if (in_array(special_kind($r), ['yt', 'image', 'pdf'], true)) {
        $isYt = special_kind($r) === 'yt';
        $ic = $isYt ? youtube_icon() : icon(special_kind($r) === 'pdf' ? 'picture_as_pdf' : 'image');
        return '<a class="special-link" href="special?open=' . (int)$r['special_id'] . '">' . $ic . '<span>' . h($r['title']) . '</span></a>';
    }
    $isYt = $r['url'] !== null && youtube_url_valid((string)$r['url']);
    $ic = $isYt ? youtube_icon() : icon($r['file_name'] !== null ? 'attach_file' : 'link');
    return '<a class="special-link" href="' . h(special_href($r)) . '" target="_blank" rel="noopener noreferrer">' . $ic . '<span>' . h($r['title']) . '</span></a>';
}

/** アイコン（押す・乗せるとリンクの一覧が出る）。$key はページ内で一意な文字（year-2025 / live-12） */
function special_button_html(string $key, array $rows, string $extraClass = ''): string
{
    if (!$rows) {
        return '';
    }
    // 年度の横: 押すとプルダウンで一覧が開く（右上のメニューと同じ <details class="usermenu">。外を押すと閉じる動きは app_ui.js）
    if (str_starts_with($key, 'year-')) {
        return '<details class="usermenu special-pull no-print"><summary aria-label="スペシャルコンテンツ">' . special_icon(true) . '</summary>'
            . '<span class="usermenu__panel special-pull__panel special-list">' . implode('', array_map('special_link_html', $rows)) . '</span></details>';
    }
    $id = 'special-' . $key;
    // <p>・<h2> の中にも置くので <div> ではなく <span>
    $list = '<span id="' . h($id) . '" class="special-list" hidden>' . implode('', array_map('special_link_html', $rows)) . '</span>';
    return '<button type="button" class="special-btn no-print' . ($extraClass !== '' ? ' ' . h($extraClass) : '') . '" data-tip data-tip-from="#' . h($id) . '" aria-label="スペシャルコンテンツ">'
        . special_icon() . '</button>' . $list;
}

/** アイコン: 橙の枠の角丸に、ギフト（オレンジの箱＋赤いリボン）。$plus なら白地に細い枠で＋（年度の横。目立たせない）。色は assets/app.css の .special-tile / .special-icon--plus */
function special_icon(bool $plus = false): string
{
    $body = $plus
        ? '<rect class="special-tile" x=".5" y=".5" width="23" height="23" rx="5.5" stroke-width="1"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 7v10M7 12h10"/>'
        : '<rect class="special-tile" x=".75" y=".75" width="22.5" height="22.5" rx="5" stroke="#f08a00" stroke-width="1.5"/>'
        . '<g transform="translate(1.2 1) scale(.9)"><path fill="#f08a00" d="M3 8h18v4H3zM4.5 12.5h15V21h-15z"/><path fill="#d90f0f" d="M11 8h2v13h-2z"/>'
        . '<path fill="none" stroke="#f08a00" stroke-width="2" stroke-linecap="round" d="M12 7.5C10.5 4 7 3.5 7 5.6 7 7.3 10 7.5 12 7.5zM12 7.5c1.5-3.5 5-4 5-1.9 0 1.7-3 1.9-5 1.9z"/></g>';
    return '<svg class="special-icon' . ($plus ? ' special-icon--plus' : '') . '" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">' . $body . '</svg>';
}

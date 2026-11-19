<?php
/**
 * =====================================================================
 *  lib/audio_player.php — 音源のプレーヤー（band.php・live.php のファイル欄で共通）
 * =====================================================================
 *  ・直接置いた MP3（attach_image.php?k=band_audio）もドライブの音源（drive_audio.php）も、同じプレーヤーで鳴らす
 *  ・band.php では再生位置に「MC」「曲」のチャプターを付けられる（band_audio_chapter。説明は migrations/130）
 *      audio_key … 'a:<band_audio の image_id>' か 'd:<ドライブのファイル ID>'
 *      曲の番号は持たない。時間順に 1, 2, 3… と数えて、セットリストの曲名に当てる（曲名の直しに自動で追従する）
 *  ・チップの描画と追加・削除は assets/app.js の setupAudioPlayers。保存は api_audio_chapter.php
 * =====================================================================
 */
declare(strict_types=1);

/** バイト数の表示: 1MB 未満は KB（整数）、10MB 未満は小数1桁（4.8MB）、それ以上は整数（48MB） */
function fmt_bytes(int $b): string
{
    if ($b < 1048576) {
        return (int)round($b / 1024) . 'KB';
    }
    $mb = $b / 1048576;
    return $mb < 10 ? number_format($mb, 1) . 'MB' : (int)round($mb) . 'MB';
}

/**
 * バンドの音源ごとのチャプター（時間順）
 * @return array<string, array[]> audio_key => [['chapter_id', 'kind', 'at_ms'], ...]
 */
function audio_chapters(PDO $pdo, int $bandId): array
{
    $st = $pdo->prepare('SELECT chapter_id, audio_key, kind, at_ms FROM band_audio_chapter WHERE band_id = ? ORDER BY at_ms, chapter_id');
    $st->execute([$bandId]);
    $out = [];
    foreach ($st as $r) {
        $out[(string)$r['audio_key']][] = $r;
    }
    return $out;
}

/**
 * チャプターを JS に渡す形にする（api_audio_chapter.php の返事と、プレーヤーの data-chapters で共通）
 * @return array<int, array{id: int, kind: string, ms: int}>
 */
function audio_chapter_list(array $rows): array
{
    return array_map(static fn($r) => ['id' => (int)$r['chapter_id'], 'kind' => (string)$r['kind'], 'ms' => (int)$r['at_ms']], $rows);
}

/**
 * プレーヤーの HTML
 *   $src … 音源の URL（attach_image?k=band_audio&id=N / drive_audio?band=N&f=ID）
 *   $chapterCtx … チャプターを出すとき: ['band_id' => int, 'key' => 'a:N' | 'd:ID', 'chapters' => 行の配列, 'songs' => [曲名（SE 以外・セトリ順）]]
 */
function audio_player_html(string $src, string $title, ?int $size, ?array $chapterCtx = null): string
{
    $data = '';
    if ($chapterCtx !== null) {
        $data = ' data-chapters="' . h(json_encode([
            'band'  => (int)$chapterCtx['band_id'],
            'key'   => (string)$chapterCtx['key'],
            'list'  => audio_chapter_list($chapterCtx['chapters']),
            'songs' => array_values($chapterCtx['songs']),
        ], JSON_UNESCAPED_UNICODE)) . '"';
    }
    $html = '<figure class="drive-player audio-player" data-audio-player' . $data . '>'
        . '<figcaption class="drive-player__name">' . icon('audio_file', 'file-icon file-icon--audio')
        . '<span class="audio-player__title">' . h($title) . '</span>'
        . ($size !== null ? '<small class="audio-player__size">' . h(fmt_bytes($size)) . '</small>' : '');
    if ($chapterCtx !== null) {
        $html .= '<button type="button" class="audio-player__edit no-print" data-ap-edit aria-pressed="false" aria-label="チャプターを編集" title="チャプターを編集">' . icon('edit') . '</button>';
    }
    $html .= '</figcaption>'
        . '<audio class="band-audio" controls preload="none" src="' . h($src) . '"></audio>';
    if ($chapterCtx !== null) {
        $html .= '<div class="chip-list chip-list--sm audio-player__list" data-ap-list></div>'
            . '<div class="audio-player__add" data-ap-bar hidden>'
            . '<button type="button" class="btn btn--ghost btn--sm" data-ap-add="mc">MC</button>'
            . '<button type="button" class="btn btn--ghost btn--sm" data-ap-add="song">曲</button></div>';
    }
    return $html . '</figure>';
}

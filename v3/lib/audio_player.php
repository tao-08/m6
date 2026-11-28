<?php
/**
 * =====================================================================
 *  lib/audio_player.php — 音源のプレーヤー（band.php・live.php のファイル欄で共通）
 * =====================================================================
 *  ・直接置いた MP3（attach_image.php?k=band_audio）もドライブの音源（drive_audio.php）も、同じプレーヤーで鳴らす
 *  ・band.php では再生位置に「MC」「曲」のチャプターを付けられる（band_audio_chapter。説明は migrations/130）
 *      audio_key … 'a:<band_audio の image_id>' か 'd:<ドライブのファイル ID>'。YouTube の区分の音源は動画と共有する 'y:<動画 ID>'
 *      曲の番号は持たない。時間順に 1, 2, 3… と数えて、セットリストの曲名に当てる（曲名の直しに自動で追従する）
 *  ・チップ・吹き出しの描画と追加・削除・時間の直しは assets/app_player.js の setupAudioPlayers。保存は api_audio_chapter.php
 *  ・「自動で付ける」は 裏で Gemini に音源を聴かせ（lib/chapter_job.php）、終わったらそのまま置き換える。画面を閉じても続く
 *  ・再生・シークも setupAudioPlayers（自前）。まだ読み込んでいない位置へは、音源に ?from=バイト位置 を付けて、その位置から流してもらう（lib/wav.php）
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
    $st = $pdo->prepare('SELECT chapter_id, audio_key, kind, song_no, at_ms FROM band_audio_chapter WHERE band_id = ? ORDER BY at_ms, chapter_id');
    $st->execute([$bandId]);
    $out = [];
    foreach ($st as $r) {
        $out[(string)$r['audio_key']][] = $r;
    }
    return $out;
}

/**
 * YouTube の区分の音源（video_id。migrations/153）のチャプターを、動画の 'y:動画ID' に寄せる（ファイルと動画で同じチャプターを使う）
 *   動画の側にもうあれば動画の側を残してファイルの分を消す。区分を変えた保存（band_edit.php）・まとめて紐付け（api_yt_drive.php）のあとに呼ぶ
 *   同じ処理を今までの分に流したのが migrations/159
 */
function audio_chapters_to_video(PDO $pdo, int $bandId): void
{
    $st = $pdo->prepare("SELECT CONCAT('a:', image_id) AS k, video_id FROM band_audio WHERE band_id = ? AND video_id IS NOT NULL
        UNION ALL SELECT CONCAT('d:', file_id), video_id FROM band_drive_file WHERE band_id = ? AND video_id IS NOT NULL");
    $st->execute([$bandId, $bandId]);
    $has = $pdo->prepare('SELECT 1 FROM band_audio_chapter WHERE band_id = ? AND audio_key = ? LIMIT 1');
    $del = $pdo->prepare('DELETE FROM band_audio_chapter WHERE band_id = ? AND audio_key = ?');
    $move = $pdo->prepare('UPDATE band_audio_chapter SET audio_key = ? WHERE band_id = ? AND audio_key = ?');
    foreach ($st->fetchAll() as $r) {
        $has->execute([$bandId, 'y:' . $r['video_id']]);
        $has->fetchColumn() ? $del->execute([$bandId, $r['k']]) : $move->execute(['y:' . $r['video_id'], $bandId, $r['k']]);
    }
}

/**
 * 曲（SE 以外）ごとに、セトリでその曲より前にある SE の数。途中の曲から始まる音源で、SE を何個飛ばすか
 *   $rows: is_se を持つセトリの行（セトリ順）
 * @return int[]
 */
function chapter_se_before(array $rows): array
{
    $out = [];
    $se = 0;
    foreach ($rows as $r) {
        if ($r['is_se']) {
            $se++;
        } else {
            $out[] = $se;
        }
    }
    return $out;
}

/**
 * チャプターを JS に渡す形にする（api_audio_chapter.php の返事と、プレーヤーの data-chapters で共通）
 * @return array<int, array{id: int, kind: string, ms: int, no: ?int}>
 */
function audio_chapter_list(array $rows): array
{
    return array_map(static fn($r) => ['id' => (int)$r['chapter_id'], 'kind' => (string)$r['kind'], 'ms' => (int)$r['at_ms'], 'no' => $r['song_no'] === null ? null : (int)$r['song_no']], $rows); // no = 当たる曲（NULL = 前の曲 + 1）
}

/**
 * 解析で入れたセトリ・チャプターの「確認済みにする」（小見出しの右。band.php が受ける。migrations/160）
 *   $action … confirm_setlist | confirm_chapters。$key … チャプターの音源キー
 */
function confirm_button_html(string $action, string $key = ''): string
{
    return '<form method="post" class="confirm-form no-print">' . csrf_field()
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . ($key !== '' ? '<input type="hidden" name="key" value="' . h($key) . '">' : '')
        . '<button type="submit" class="btn btn--sm btn--ghost" aria-label="確認済みにする" title="確認済みにする">' . icon('flag', 'icon--fill flag-icon') . '未確認</button></form>';
}

/**
 * プレーヤーの HTML
 *   $src … 音源の URL（attach_image?k=band_audio&id=N / drive_audio?band=N&f=ID）
 *   $chapterCtx … チャプターを出すとき: ['band_id' => int, 'key' => 'a:N' | 'd:ID' | 'y:動画ID', 'chapters' => 行の配列, 'songs' => [曲名（SE 以外・セトリ順）], 'ses' => [SE 名（セトリ順。無ければ省略可）]]
 *     'se_before' => chapter_se_before()（省略可）
 *     'embed' => true … <audio> の代わりに YouTube の埋め込み（app_player.js の apYouTube）を上に置き、最初から編集モード（video_chapters.php。MP3 の無い動画だけ）
 *     'editing' => true … 最初から編集モード（band.php?chap=キー。解析の状況から開く）
 *     'video' => 動画ID … YouTube の区分の音源: アイコンを YouTube にして、名前をその動画へのリンクにする
 *     'unconfirmed' => true … 自動で付けて未確認: 名前の右に「確認済みにする」
 *   figure の id は ch-キー（: は -）。band.php?chap=キー#ch-… で飛んでくる
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
            'ses'   => array_values($chapterCtx['ses'] ?? []),
            'job'   => $chapterCtx['job'] ?? null, // 裏の解析（lib/chapter_job.php）
            'se_before' => array_values($chapterCtx['se_before'] ?? []), // chapter_se_before
        ], JSON_UNESCAPED_UNICODE)) . '"';
    }
    $yt = !empty($chapterCtx['embed']) ? substr((string)$chapterCtx['key'], 2) : null;
    $editing = $yt !== null || !empty($chapterCtx['editing']);
    $video = $chapterCtx['video'] ?? null;
    $id = $chapterCtx !== null ? ' id="ch-' . h(str_replace(':', '-', (string)$chapterCtx['key'])) . '"' : '';
    // 大きさ（バイト）: プレーヤーが「?from=バイト位置」を決めるのに使う（長さは読み込んだ音源から取る）
    $html = '<figure' . $id . ' class="drive-player audio-player' . ($yt !== null ? ' audio-player--yt' : '') . ($editing ? ' is-editing' : '') . '" data-audio-player' . ($size ? ' data-size="' . (int)$size . '"' : '') . $data . '>';
    if ($yt !== null) {
        $html .= '<div class="audio-player__yt" data-ap-yt="' . h($yt) . '"></div><figcaption class="drive-player__name">'
            . '<button type="button" class="audio-player__edit no-print" data-ap-edit aria-pressed="true">' . icon('edit') . 'チャプターを編集</button>';
    } else {
        $html .= '<figcaption class="drive-player__name">'
            . ($video !== null
                ? '<a class="audio-player__title audio-player__title--yt" href="' . h(youtube_watch_url((string)$video)) . '" target="_blank" rel="noopener noreferrer">' . youtube_icon() . h($title) . '</a>'
                : icon('audio_file', 'file-icon file-icon--audio') . '<span class="audio-player__title">' . h($title) . '</span>')
            . ($size !== null ? '<small class="audio-player__size">' . h(fmt_bytes($size)) . '</small>' : '');
        // 保存: 元のファイルがそのまま落ちる（同じ権限のチェックを通る）
        $html .= '<a class="audio-player__dl no-print" href="' . h($src . (str_contains($src, '?') ? '&' : '?') . 'dl=1') . '" download aria-label="ダウンロード" title="ダウンロード">' . icon('download') . '</a>';
        if ($chapterCtx !== null) {
            $html .= '<button type="button" class="audio-player__edit no-print" data-ap-edit aria-pressed="' . ($editing ? 'true' : 'false') . '">' . icon('edit') . 'チャプターを編集</button>';
        }
        if (!empty($chapterCtx['unconfirmed'])) {
            $html .= confirm_button_html('confirm_chapters', (string)$chapterCtx['key']);
        }
    }
    // 自前の操作部（ブラウザ標準のコントロールは Range が使えない環境でシークできないため）。吹き出し（チャプター）は再生位置の棒の上に並べる
    $html .= '</figcaption>'
        . ($yt === null ? '<audio class="band-audio" preload="none" src="' . h($src) . '"></audio>' : '')
        . '<div class="audio-player__bar">'
        . '<button type="button" class="audio-player__play" data-ap-play aria-label="再生" title="再生">' . icon('play_arrow', 'icon--fill') . '</button>'
        . '<div class="audio-player__seek' . ($chapterCtx !== null ? ' has-bubbles' : '') . '">'
        . ($chapterCtx !== null ? '<div class="audio-player__bubbles" data-ap-bubbles></div>' : '')
        . '<input type="range" class="audio-player__range" data-ap-seek min="0" max="0" step="1" value="0" disabled aria-label="再生位置"></div>'
        . '<span class="audio-player__time" data-ap-time>0:00 / 0:00</span></div>';
    if ($chapterCtx !== null) {
        // 時間の直し（チップを選んだときだけ開く）と、足す・自動の操作部（編集モードのときだけ開く）。開け閉めの動きは CSS の .audio-player__fold
        $html .= '<div class="chip-list chip-list--sm audio-player__list" data-ap-list></div>'
            . '<div class="audio-player__fold audio-player__fold--move"><div class="audio-player__move">'
            . '<input type="text" class="audio-player__at" data-ap-at inputmode="text" autocomplete="off" maxlength="8" pattern="[0-9]+(:[0-5]?[0-9]){0,2}" aria-label="時間" title="時間">'
            . '<button type="button" class="btn btn--ghost btn--sm" data-ap-nudge="-1" aria-label="1秒前へ" title="1秒前へ">−1</button>'
            . '<button type="button" class="btn btn--ghost btn--sm" data-ap-nudge="1" aria-label="1秒後へ" title="1秒後へ">+1</button>'
            . '<button type="button" class="btn btn--ghost btn--sm" data-ap-here aria-label="今の位置" title="今の位置">今の位置</button>'
            . (count($chapterCtx['songs']) > 1 ? '<select class="audio-player__song" data-ap-song aria-label="曲" title="曲" hidden><option value="0">—</option>' // 曲のチップを選んだときだけ（app_player.js）。0 = 曲なし（わからない曲など。シャッフルでは流さない）
                . implode('', array_map(static fn($t, $i) => '<option value="' . ($i + 1) . '">' . ($i + 1) . '. ' . h((string)$t) . '</option>', array_values($chapterCtx['songs']), array_keys(array_values($chapterCtx['songs']))))
                . '</select>' : '')
            . '</div></div>'
            . '<div class="audio-player__fold audio-player__fold--bar"><div class="audio-player__add">'
            . '<button type="button" class="btn btn--ghost btn--sm" data-ap-in="idle" data-ap-add="mc">MC</button>'
            . '<button type="button" class="btn btn--ghost btn--sm" data-ap-in="idle" data-ap-add="song">曲</button>'
            . '<button type="button" class="btn btn--ghost btn--sm" data-ap-in="idle" data-ap-add="end">終わり</button>'
            . (!empty($chapterCtx['ses']) ? '<button type="button" class="btn btn--ghost btn--sm" data-ap-in="idle" data-ap-add="se">SE</button>' : '') // セトリに SE があるときだけ
            . (($chapterCtx['auto'] ?? true) ? '<button type="button" class="btn btn--ghost btn--sm" data-ap-in="idle" data-ap-auto>' . icon('auto_awesome') . '自動で付ける</button>' : '') // YouTube は MP3 があるときだけ（video_chapters.php）
            . '<button type="button" class="btn btn--ghost btn--sm" data-ap-in="busy" data-ap-stop hidden>中止</button></div></div>';
    }
    return $html . '</figure>';
}

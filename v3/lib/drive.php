<?php
/**
 * =====================================================================
 *  lib/drive.php — Google ドライブのファイルをバンドに紐付ける（1バンドに何個でも・種類は問わない）
 * =====================================================================
 *  流れ:
 *    1. ライブにフォルダのリンク（live.drive_url）を入れる
 *    2. フォルダの中のファイルを、サブフォルダの中まで全部取る（drive_folder_tree）
 *         12月ライブ/
 *           ├ スピッツ(山田).wav     ← 直下のファイル
 *           └ 写真/スピッツ(山田).jpg ← サブフォルダの中のファイル
 *    3. 「フォルダ名 ＋ ファイル名」にバンド名が入っているかで結び付ける（drive_match_files → youtube_match_bands）
 *         バンドごとにフォルダを分けた場合（スピッツ/ライン.wav）もフォルダ名で当たる
 *    4. 確認画面（live_drive.php）で人がチェックしてから band_drive_file に保存
 *
 *  ■ 表示は種類（mime_type）で自動で変える（drive_files_html）
 *    音源・動画 … ドライブのプレーヤーを iframe で埋め込む（…/file/d/ID/preview）
 *    写真       … サムネイル（ライブ・バンドの添付画像と同じ .live-thumbs の見た目）。押すとドライブで開く
 *    それ以外   … チップ（.chip）のリンク。押すとドライブで開く
 *
 *  ■ API キー
 *    YouTube と同じ Google Cloud のプロジェクトで「Google Drive API」も有効にすれば、同じキーが使える。
 *    config.php に drive.api_key があればそれを、無ければ youtube.api_key を使う。
 *    ※ キーで読めるのは「リンクを知っている全員」に共有したフォルダだけ
 *
 *  ■ セキュリティ
 *    URL は人が貼った文字列をそのまま保存・表示しない。必ず「ID を取り出す → こちらで URL を組み立てる」。
 *    ID は英数字と - _ だけに限定しているので、href や src に入れても変な動きをしない（XSS 対策）。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/albums.php';  // album_http（通信）
require_once __DIR__ . '/youtube.php'; // youtube_match_bands（タイトルとバンド名の突き合わせ）を使い回す

/** 読み込むページ数の上限（100件 × 5 = 500ファイル）。変なフォルダで延々と通信しないように */
const DRIVE_MAX_PAGES = 5;

/**
 * ドライブのファイル / フォルダの ID として正しい形か。
 *   実際の ID は 28〜44 文字くらいの英数字と - _。余裕を持たせて 10〜100 文字にしている
 */
function drive_id_valid(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{10,100}$/', $id);
}

/**
 * URL が drive.google.com の https のリンクか（ホスト名は完全一致で比べる。youtube_url_valid と同じ考え方）
 *   「drive.google.com.example.com」のような偽物や「javascript:」を通さない
 */
function drive_url_valid(string $url): bool
{
    if ($url === '' || strlen($url) > 500 || preg_match('/\s/', $url)) {
        return false;
    }
    $parts = parse_url($url); // parse_url: URL を scheme / host / path / query などに分解する
    return $parts !== false
        && ($parts['scheme'] ?? '') === 'https'
        && strtolower($parts['host'] ?? '') === 'drive.google.com';
}

/**
 * フォルダの URL からフォルダ ID を取り出す。フォルダの URL でなければ null
 *   https://drive.google.com/drive/folders/ID?usp=sharing
 *   https://drive.google.com/drive/u/0/folders/ID  （アカウントを切り替えて開いたとき）
 */
function drive_folder_id(string $url): ?string
{
    if (!drive_url_valid($url)) {
        return null;
    }
    $path = (string)parse_url($url, PHP_URL_PATH);
    // ~ は正規表現の区切り文字（/ を区切りにすると、パスの / を全部 \/ と書く必要があって読みにくい）
    if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $path, $m) && drive_id_valid($m[1])) {
        return $m[1];
    }
    return null;
}

/**
 * ファイルの URL からファイル ID を取り出す。ファイルの URL でなければ null
 *   https://drive.google.com/file/d/ID/view?usp=sharing  （「リンクをコピー」で出る形）
 *   https://drive.google.com/open?id=ID / https://drive.google.com/uc?id=ID  （古い形）
 */
function drive_file_id(string $url): ?string
{
    if (!drive_url_valid($url)) {
        return null;
    }
    $parts = parse_url($url);
    if (preg_match('~^/file/d/([A-Za-z0-9_-]+)~', $parts['path'] ?? '', $m)) {
        $id = $m[1];
    } else {
        parse_str($parts['query'] ?? '', $q); // "id=xxx&usp=yyy" → ['id' => 'xxx', 'usp' => 'yyy']
        $id = $q['id'] ?? '';
    }
    return is_string($id) && drive_id_valid($id) ? $id : null;
}

/** ファイル ID から、保存する URL を作る（ID は検証済みのものだけ渡す） */
function drive_file_url(string $fileId): string
{
    return 'https://drive.google.com/file/d/' . $fileId . '/view';
}

/** ファイル ID から、iframe に入れる再生用の URL を作る */
function drive_preview_url(string $fileId): string
{
    return 'https://drive.google.com/file/d/' . $fileId . '/preview';
}

/** ファイル名から拡張子を外す（「12月 スピッツ.wav」→「12月 スピッツ」）。表示とバンド名の突き合わせに使う */
function drive_strip_ext(string $name): string
{
    // pathinfo を使わないのは、日本語のファイル名で環境（ロケール）によって壊れることがあるため
    return preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) ?? $name;
}

/** フォルダの mimeType（ドライブではフォルダも「ファイルの一種」として返ってくる） */
const DRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';

/** API キー。drive 用が空なら YouTube のキーを使う */
function drive_api_key(): string
{
    //   ?? は「null のときだけ」右へ進む。config.sample.php は '' で置いてあるので、'' でも右へ進む ?: で受ける
    //   （?: は「左が false 扱い（'' / 0 / null など）なら右」）
    $key = (string)((config('drive')['api_key'] ?? '') ?: (config('youtube')['api_key'] ?? ''));
    if ($key === '') {
        throw new RuntimeException('config.php に Google の API キー（drive.api_key か youtube.api_key）が設定されていません');
    }
    return $key;
}

/**
 * フォルダの中身（直下だけ）を全部取ってくる。サブフォルダとファイルに分けて返す
 *   戻り値: ['folders' => [['file_id' => ..., 'name' => 'スピッツ(山田)'], ...],
 *            'files'   => [['file_id' => ..., 'name' => 'ライン.wav', 'mime_type' => 'audio/wav'], ...]]（どちらも名前順）
 * @throws RuntimeException キーが無い・通信できない・フォルダが見つからない
 */
function drive_folder_children(string $folderId): array
{
    $key = drive_api_key();
    $out = ['folders' => [], 'files' => []];
    $pageToken = '';
    for ($page = 0; $page < DRIVE_MAX_PAGES; $page++) {
        $params = [
            // q = 検索条件。「このフォルダが親」かつ「ゴミ箱に入っていない」。ID は検証済み（英数字と - _ だけ）なので ' で囲んでも壊れない
            'q'         => "'" . $folderId . "' in parents and trashed = false",
            // fields = 返してほしい項目だけ指定する（全部もらうと重い。API の定番の作法）
            'fields'    => 'nextPageToken, files(id, name, mimeType)',
            'orderBy'   => 'name',
            'pageSize'  => 100,
            // 共有ドライブ（チームのドライブ）に置いたフォルダも読めるようにする
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
        ];
        if ($pageToken !== '') {
            $params['pageToken'] = $pageToken; // 2ページ目以降は、前の結果の nextPageToken を渡す（ページング）
        }
        // キーは URL ではなくヘッダーで渡す（サーバーのアクセスログに残らないように）
        $res = album_http('https://www.googleapis.com/drive/v3/files?' . http_build_query($params), ['X-Goog-Api-Key: ' . $key]);
        drive_check_response($res, 'フォルダ');
        foreach ($res['json']['files'] ?? [] as $f) {
            $id = (string)($f['id'] ?? '');
            if (!drive_id_valid($id)) {
                continue;
            }
            $mime = (string)($f['mimeType'] ?? '');
            if ($mime === DRIVE_FOLDER_MIME) {
                $out['folders'][] = ['file_id' => $id, 'name' => (string)($f['name'] ?? '')];
            } else {
                $out['files'][] = ['file_id' => $id, 'name' => (string)($f['name'] ?? ''), 'mime_type' => $mime];
            }
        }
        $pageToken = (string)($res['json']['nextPageToken'] ?? '');
        if ($pageToken === '') {
            break;
        }
    }
    return $out;
}

/** サブフォルダをたどる深さの上限（ライブ/写真/2日目/… の 3段まで）。変なフォルダで延々と通信しないように */
const DRIVE_MAX_DEPTH = 3;

/**
 * フォルダの中のファイルを、サブフォルダの中まで全部取ってくる
 *   戻り値: [['file_id' => ..., 'name' => 'スピッツ.jpg', 'mime_type' => 'image/jpeg', 'folder' => '写真'], ...]
 *     folder = どのサブフォルダにあったか（直下なら ''。2段なら '写真/2日目'）。バンドとの突き合わせにも使う
 *   たどり方は「幅優先探索」: 今の段のフォルダを全部見てから、次の段へ進む（キューに積んで1つずつ取り出す）
 * @throws RuntimeException キーが無い・通信できない・フォルダが見つからない
 */
function drive_folder_tree(string $folderId): array
{
    $files = [];
    $queue = [[$folderId, '', 0]]; // [フォルダ ID, ここまでのフォルダ名, 深さ]
    while ($queue) {
        [$id, $path, $depth] = array_shift($queue); // array_shift: 配列の先頭を取り出す（キュー = 先に入れたものから順に処理）
        $children = drive_folder_children($id);
        foreach ($children['files'] as $f) {
            $files[] = $f + ['folder' => $path]; // + は配列の足し算（左に無いキーだけ右から足す）
        }
        if ($depth + 1 < DRIVE_MAX_DEPTH) {
            foreach ($children['folders'] as $sub) {
                $queue[] = [$sub['file_id'], ltrim($path . '/' . $sub['name'], '/'), $depth + 1];
            }
        }
    }
    return $files;
}

/**
 * ファイルをバンドに割り当てる（DB も通信も使わない → tests/run.php でテストできる）
 *   比べる文字は「フォルダ名 ＋ ファイル名（拡張子なし）」。
 *     写真/スピッツ.jpg … ファイル名で当たる
 *     スピッツ/ライン.wav … フォルダ名で当たる（バンドごとにフォルダを分けた場合）
 *   中身は youtube_match_bands（同じロジックを2回書かない = DRY）
 * @param array $files drive_folder_tree の戻り値
 * @return array{bands: array<int, string[]>, auto: array<int, string>, unmatched: string[]} 中身はファイル ID
 */
function drive_match_files(array $files, array $bands): array
{
    $list = [];
    foreach ($files as $f) {
        $list[] = ['video_id' => $f['file_id'], 'title' => ($f['folder'] ?? '') . ' ' . drive_strip_ext((string)$f['name'])];
    }
    return youtube_match_bands($list, $bands);
}

/**
 * ファイル1つの名前と種類を取る（バンド編集でリンクを1本ずつ貼ったとき用）
 *   戻り値: ['file_id' => ..., 'name' => ..., 'mime_type' => ...]
 * @throws RuntimeException 読めない（共有されていない・存在しない）
 */
function drive_file_meta(string $fileId): array
{
    $res = album_http('https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?'
        . http_build_query(['fields' => 'id, name, mimeType', 'supportsAllDrives' => 'true']), ['X-Goog-Api-Key: ' . drive_api_key()]);
    drive_check_response($res, 'ファイル');
    if (($res['json']['mimeType'] ?? '') === DRIVE_FOLDER_MIME) {
        throw new RuntimeException('フォルダのリンクです。ファイルのリンクを貼ってください');
    }
    return ['file_id' => $fileId, 'name' => (string)($res['json']['name'] ?? ''), 'mime_type' => (string)($res['json']['mimeType'] ?? '')];
}

/** API の返事を確かめる。だめなら理由つきで例外（2つの関数で同じことを書かないようにまとめた） */
function drive_check_response(?array $res, string $what): void
{
    if ($res === null) {
        throw new RuntimeException('Google ドライブに接続できませんでした。時間をおいてもう一度試してください');
    }
    if ($res['status'] === 404) {
        throw new RuntimeException("{$what}が見つかりません（共有が「リンクを知っている全員」になっているか確認してください）");
    }
    if ($res['status'] !== 200 || !is_array($res['json'])) {
        $reason = $res['json']['error']['message'] ?? ('HTTP ' . $res['status']);
        throw new RuntimeException("Google ドライブの{$what}を読めませんでした（{$reason}）");
    }
}

/** 種類 → 表示の仕方。media = プレーヤー / image = サムネイル / other = リンク */
function drive_kind(string $mime): string
{
    if (preg_match('~^(audio|video)/~', $mime)) {
        return 'media';
    }
    return str_starts_with($mime, 'image/') ? 'image' : 'other';
}

/** 種類ごとのアイコン（リンクのチップに付ける） */
function drive_kind_icon(string $mime): string
{
    return match (true) {           // match(true): 上から順に条件を見て、最初に true になった値を返す
        $mime === 'application/pdf' => 'picture_as_pdf',
        str_starts_with($mime, 'audio/') => 'audio_file',
        str_starts_with($mime, 'video/') => 'movie',
        str_starts_with($mime, 'image/') => 'image',
        default => 'description',
    };
}

/**
 * 種類のラベル（WAV / MP4 / JPG / PDF …）。見た目は楽器ラベル（.part）と同じで、色だけ種類で変える
 *   拡張子はファイル名から取る。拡張子の無いファイル（Google ドキュメントなど）は mime_type の後ろ半分から作る
 *   色: 音源 = 緑 / 動画 = 赤 / 写真 = 紫 / PDF = 青 / それ以外 = 灰（CSS の .ext-label--*）
 */
function drive_ext_label(string $name, string $mime): string
{
    if (preg_match('/\.([A-Za-z0-9]{1,5})$/', $name, $m)) {
        $ext = $m[1];
    } else {
        // Google ドキュメント・スプレッドシート・スライドは拡張子が無いので、決まった名前にする。それ以外は FILE
        $ext = match ($mime) {
            'application/vnd.google-apps.document' => 'DOC',
            'application/vnd.google-apps.spreadsheet' => 'SHEET',
            'application/vnd.google-apps.presentation' => 'SLIDE',
            default => 'FILE',
        };
    }
    $kind = match (true) {
        str_starts_with($mime, 'audio/') => 'audio',
        str_starts_with($mime, 'video/') => 'video',
        str_starts_with($mime, 'image/') => 'image',
        $mime === 'application/pdf' => 'pdf',
        default => 'other',
    };
    return '<span class="part ext-label ext-label--' . $kind . '">' . h(strtoupper($ext)) . '</span>';
}

/** 写真のサムネイルの URL（共有されたファイルならキーなしで出る。sz=w400 = 幅 400px） */
function drive_thumb_url(string $fileId): string
{
    return 'https://drive.google.com/thumbnail?id=' . $fileId . '&sz=w400';
}

// =====================================================================
//  DB（band_drive_file）
// =====================================================================

/**
 * バンドごとのファイル。全バンド分を1回の SQL で取る（バンドごとに SQL を投げない = N+1 問題の回避）
 * @param int[] $bandIds
 * @return array<int, array> band_id => [['file_id', 'name', 'mime_type'], ...]（並び順どおり）
 */
function drive_load_files(PDO $pdo, array $bandIds): array
{
    $bandIds = array_values(array_unique(array_map('intval', $bandIds)));
    if (!$bandIds) {
        return [];
    }
    // IN (?, ?, ?) の ? を個数ぶん作る（値はプレースホルダで渡すので SQL インジェクションにならない）
    $marks = implode(',', array_fill(0, count($bandIds), '?'));
    $st = $pdo->prepare("SELECT band_id, file_id, name, mime_type FROM band_drive_file
        WHERE band_id IN ($marks) ORDER BY band_id, sort_order, name");
    $st->execute($bandIds);
    $out = [];
    foreach ($st as $r) {
        $out[(int)$r['band_id']][] = $r;
    }
    return $out;
}

/**
 * バンドのファイルを、渡した一覧と同じにする（無いものは消す・新しいものは足す・あるものは名前と順番を更新）
 *   全部消して入れ直さないのは、変わらない行の created_at（いつ紐付けたか）を残すため
 * @param array $files [['file_id', 'name', 'mime_type'], ...]（この順番が sort_order になる）
 */
function drive_save_files(PDO $pdo, int $bandId, array $files): void
{
    $keep = array_column($files, 'file_id');
    if ($keep) {
        $marks = implode(',', array_fill(0, count($keep), '?'));
        $pdo->prepare("DELETE FROM band_drive_file WHERE band_id = ? AND file_id NOT IN ($marks)")->execute([$bandId, ...$keep]);
    } else {
        $pdo->prepare('DELETE FROM band_drive_file WHERE band_id = ?')->execute([$bandId]);
    }
    // ON DUPLICATE KEY UPDATE: 主キー（band_id, file_id）がもうあれば INSERT の代わりに UPDATE する（MySQL の「あれば更新・無ければ追加」）
    $up = $pdo->prepare('INSERT INTO band_drive_file (band_id, file_id, name, mime_type, sort_order) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), mime_type = VALUES(mime_type), sort_order = VALUES(sort_order)');
    foreach (array_values($files) as $i => $f) {
        if (!drive_id_valid((string)$f['file_id'])) {
            continue;
        }
        $up->execute([$bandId, $f['file_id'], mb_substr((string)$f['name'], 0, 255), substr((string)$f['mime_type'], 0, 100), $i + 1]);
    }
}

/**
 * ページ内で再生するプレーヤー（iframe）の HTML。ID が変なら空文字
 *   loading="lazy" … 画面に近づくまで読み込まない（バンドが多いページでも最初の表示が重くならない）
 *   allow="autoplay" … ボタンで開いたときに、そのまま再生できるように
 */
function drive_player_html(string $fileId, string $title): string
{
    if (!drive_id_valid($fileId)) {
        return '';
    }
    return '<iframe class="drive-player__frame" src="' . h(drive_preview_url($fileId)) . '" title="' . h($title) . '"'
        . ' loading="lazy" allow="autoplay"></iframe>';
}

/**
 * ファイルの一覧を種類ごとに表示する HTML（band.php と live.php の開いたパネルで共通）
 *   並びは「プレーヤー → 写真 → リンク」。種類ごとに見た目を変える
 *     音源・動画 … .drive-player（iframe。loading="lazy" で画面に近づくまで読み込まない）
 *     写真       … .live-thumbs（ライブ・バンドの添付画像と同じ見た目）。押すとドライブで開く
 *     それ以外   … .chip-list のチップ。押すとドライブで開く
 * @param array $files [['file_id', 'name', 'mime_type'], ...]
 */
function drive_files_html(array $files): string
{
    $groups = ['media' => [], 'image' => [], 'other' => []];
    foreach ($files as $f) {
        if (drive_id_valid((string)$f['file_id'])) {
            $groups[drive_kind((string)$f['mime_type'])][] = $f;
        }
    }
    $html = '';
    foreach ($groups['media'] as $f) {
        $name = drive_strip_ext((string)$f['name']);
        $html .= '<figure class="drive-player"><figcaption class="drive-player__name">' . icon(drive_kind_icon((string)$f['mime_type']), 'icon--sm') . ' ' . h($name) . '</figcaption>'
            . drive_player_html((string)$f['file_id'], $name) . '</figure>';
    }
    if ($groups['image']) {
        $html .= '<ul class="live-thumbs drive-thumbs">';
        foreach ($groups['image'] as $f) {
            $html .= '<li><a class="live-thumbs__btn" href="' . h(drive_file_url((string)$f['file_id'])) . '" target="_blank" rel="noopener noreferrer" aria-label="' . h(drive_strip_ext((string)$f['name'])) . '">'
                . '<img src="' . h(drive_thumb_url((string)$f['file_id'])) . '" alt="" loading="lazy" referrerpolicy="no-referrer"></a></li>';
        }
        $html .= '</ul>';
    }
    if ($groups['other']) {
        $html .= '<div class="chip-list chip-list--sm drive-links">';
        foreach ($groups['other'] as $f) {
            $html .= '<a class="chip" href="' . h(drive_file_url((string)$f['file_id'])) . '" target="_blank" rel="noopener noreferrer">'
                . icon(drive_kind_icon((string)$f['mime_type'])) . h(drive_strip_ext((string)$f['name'])) . '</a>';
        }
        $html .= '</div>';
    }
    return $html;
}

/** ドライブの印（フォルダのアイコン）。ライブページのボタン・見出しに使う */
function drive_icon(): string
{
    return icon('folder_open');
}

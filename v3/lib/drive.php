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
 *    音源       … 自前のプレーヤー（audio_player_html）。音は drive_audio.php が中継する
 *    動画       … ドライブのプレーヤーを iframe で埋め込む（…/file/d/ID/preview）
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
require_once __DIR__ . '/audio_player.php'; // 音源のプレーヤー
require_once __DIR__ . '/drive_cache.php'; // 音源のキャッシュ（外したファイルを消す）
require_once __DIR__ . '/attach_image.php'; // 画像のモーダル（attach_modal_html）

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
            'fields'    => 'nextPageToken, files(id, name, mimeType, size)',
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
                // size は文字列で返ってくる。Google のドキュメントなどは無い（null）
                $out['files'][] = ['file_id' => $id, 'name' => (string)($f['name'] ?? ''), 'mime_type' => $mime, 'size' => isset($f['size']) ? (int)$f['size'] : null];
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
 *   戻り値: ['file_id' => ..., 'name' => ..., 'mime_type' => ..., 'size' => バイト数 or null]
 * @throws RuntimeException 読めない（共有されていない・存在しない）
 */
function drive_file_meta(string $fileId): array
{
    $res = album_http('https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?'
        . http_build_query(['fields' => 'id, name, mimeType, size', 'supportsAllDrives' => 'true']), ['X-Goog-Api-Key: ' . drive_api_key()]);
    drive_check_response($res, 'ファイル');
    if (($res['json']['mimeType'] ?? '') === DRIVE_FOLDER_MIME) {
        throw new RuntimeException('フォルダのリンクです。ファイルのリンクを貼ってください');
    }
    return ['file_id' => $fileId, 'name' => (string)($res['json']['name'] ?? ''), 'mime_type' => (string)($res['json']['mimeType'] ?? ''),
        'size' => isset($res['json']['size']) ? (int)$res['json']['size'] : null];
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

/** 画面に出す名前: タイトルがあればタイトル、無ければファイル名（拡張子なし） */
function drive_file_title(array $f): string
{
    $title = trim((string)($f['title'] ?? ''));
    return $title !== '' ? $title : drive_strip_ext((string)$f['name']);
}

/**
 * タイトルを保存する（バンド編集）。空欄は NULL（= ファイル名を出す）に戻す
 * @param array<string, string> $titles file_id => タイトル
 */
function drive_save_titles(PDO $pdo, int $bandId, array $titles): void
{
    $st = $pdo->prepare('UPDATE band_drive_file SET title = ? WHERE band_id = ? AND file_id = ?');
    foreach ($titles as $fid => $title) {
        $title = mb_substr(trim((string)$title), 0, 100); // mb_substr: 日本語も1文字ずつ数えて切る（substr だと文字の途中で切れる）
        $st->execute([$title !== '' ? $title : null, $bandId, (string)$fid]);
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

/**
 * 種類のアイコン（色付き）。拡張子の種類ごとに形と色を変える
 *   音源 = 緑の audio_file / 動画 = 赤の video_file / 写真 = 紫の image / PDF = 青の picture_as_pdf
 *   Google ドキュメント・スプレッドシート・スライド = それぞれの形 / それ以外 = 灰の description
 *   色は CSS の .file-icon--*（楽器ラベルと同じ色の変数を使う）
 */
function drive_type_icon(string $mime): string
{
    // [アイコンの名前, 色の種類]。match(true) は上から順に条件を見て、最初に true になった値を返す
    [$name, $kind] = match (true) {
        str_starts_with($mime, 'audio/') => ['audio_file', 'audio'],
        str_starts_with($mime, 'video/') => ['video_file', 'video'],
        str_starts_with($mime, 'image/') => ['image', 'image'],
        $mime === 'application/pdf' => ['picture_as_pdf', 'pdf'],
        $mime === 'application/vnd.google-apps.spreadsheet' => ['table_chart', 'other'],
        $mime === 'application/vnd.google-apps.presentation' => ['slideshow', 'other'],
        default => ['description', 'other'],
    };
    return icon($name, 'file-icon file-icon--' . $kind);
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
 * @return array<int, array> band_id => [['band_id', 'file_id', 'name', 'title', 'mime_type', 'size', 'is_mix', 'video_id'], ...]（並び順どおり）
 */
function drive_load_files(PDO $pdo, array $bandIds): array
{
    $bandIds = array_values(array_unique(array_map('intval', $bandIds)));
    if (!$bandIds) {
        return [];
    }
    // IN (?, ?, ?) の ? を個数ぶん作る（値はプレースホルダで渡すので SQL インジェクションにならない）
    $marks = implode(',', array_fill(0, count($bandIds), '?'));
    $st = $pdo->prepare("SELECT band_id, file_id, name, title, mime_type, size, is_mix, video_id FROM band_drive_file
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
 *   title（人が付けたタイトル）は触らない。フォルダを読み直しても消えないように（タイトルは drive_save_titles で保存）
 *   全部消して入れ直さないのは、変わらない行の created_at（いつ紐付けたか）を残すため
 * @param array $files [['file_id', 'name', 'mime_type', 'size'], ...]（この順番が sort_order になる）
 */
function drive_save_files(PDO $pdo, int $bandId, array $files): void
{
    $keep = array_column($files, 'file_id');
    $st = $pdo->prepare('SELECT file_id FROM band_drive_file WHERE band_id = ?');
    $st->execute([$bandId]);
    $gone = array_diff($st->fetchAll(PDO::FETCH_COLUMN), $keep); // 外すファイル（あとでキャッシュを消す）
    if ($keep) {
        $marks = implode(',', array_fill(0, count($keep), '?'));
        $pdo->prepare("DELETE FROM band_drive_file WHERE band_id = ? AND file_id NOT IN ($marks)")->execute([$bandId, ...$keep]);
        // 外したファイルのチャプターも消す
        $pdo->prepare("DELETE FROM band_audio_chapter WHERE band_id = ? AND audio_key LIKE 'd:%' AND audio_key NOT IN ($marks)")
            ->execute([$bandId, ...array_map(static fn($id) => 'd:' . $id, $keep)]);
    } else {
        $pdo->prepare('DELETE FROM band_drive_file WHERE band_id = ?')->execute([$bandId]);
        $pdo->prepare("DELETE FROM band_audio_chapter WHERE band_id = ? AND audio_key LIKE 'd:%'")->execute([$bandId]);
    }
    drive_cache_forget($pdo, $gone); // 落としてあった音源も消す（ほかのバンドが使っていれば残す）
    // ON DUPLICATE KEY UPDATE: 主キー（band_id, file_id）がもうあれば INSERT の代わりに UPDATE する（MySQL の「あれば更新・無ければ追加」）
    //   is_mix は新しい行の初期値だけ（音源・動画ならミックス）。あるものは UPDATE しない = 付け外しを残す
    //   size は分かったときだけ更新する（フォルダを読み直しても、分かっている値を NULL で消さない）
    $up = $pdo->prepare('INSERT INTO band_drive_file (band_id, file_id, name, mime_type, size, is_mix, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), mime_type = VALUES(mime_type), size = COALESCE(VALUES(size), size), sort_order = VALUES(sort_order)');
    foreach (array_values($files) as $i => $f) {
        if (!drive_id_valid((string)$f['file_id'])) {
            continue;
        }
        $mime = substr((string)$f['mime_type'], 0, 100);
        $up->execute([$bandId, $f['file_id'], mb_substr((string)$f['name'], 0, 255), $mime, isset($f['size']) ? (int)$f['size'] : null, drive_kind($mime) === 'media' ? 1 : 0, $i + 1]);
    }
}

/**
 * ミックスの印を保存する（バンド編集・割り当て）。画面に出したファイルだけ、選ばれていれば 1・無ければ 0
 *   同じ保存で足したファイル（チェックボックスが無い）は触らない = 初期値のまま
 * @param string[] $shownFileIds 画面に出したファイルの ID
 * @param string[] $mixFileIds   チェックされたファイルの ID
 */
function drive_save_mix(PDO $pdo, int $bandId, array $shownFileIds, array $mixFileIds): void
{
    // MIX にしたファイルは YouTube の区分（video_id。migrations/153）から外す。外したファイルの区分はそのまま
    $st = $pdo->prepare('UPDATE band_drive_file SET is_mix = ?, video_id = IF(? = 1, NULL, video_id) WHERE band_id = ? AND file_id = ?');
    foreach ($shownFileIds as $fid) {
        $mix = in_array((string)$fid, $mixFileIds, true) ? 1 : 0;
        $st->execute([$mix, $mix, $bandId, (string)$fid]);
    }
}

/**
 * ファイルの区分を保存する（バンド編集のセレクト）。'' = なし、'mix' = MIX、'y:動画ID' = その YouTube の音声
 *   画面に出したファイルだけ渡す。動画 ID は呼ぶ側でバンドの動画か確かめる
 * @param array<string, string> $kinds file_id => 区分
 */
function drive_save_kind(PDO $pdo, int $bandId, array $kinds): void
{
    $st = $pdo->prepare('UPDATE band_drive_file SET is_mix = ?, video_id = ? WHERE band_id = ? AND file_id = ?');
    foreach ($kinds as $fid => $kind) {
        $st->execute([$kind === 'mix' ? 1 : 0, str_starts_with($kind, 'y:') ? substr($kind, 2) : null, $bandId, (string)$fid]);
    }
}

/**
 * YouTube の音声のファイル（名前が「動画ID.mp3」。scripts/takeout_to_mp3.ps1 で作る）を、その動画を貼っているバンドに割り当てる（DB も通信も使わない）
 *   同じ動画を2つのバンドが貼っていれば両方に入れる
 * @param array $files drive_folder_tree の戻り値
 * @param array<int, string[]> $bandVideos band_id => そのバンドの動画 ID
 * @return array{bands: array<int, array>, unmatched: string[]} bands: band_id => [ファイル + video_id, ...]、unmatched: 当たらなかったファイル名
 */
function drive_yt_match(array $files, array $bandVideos): array
{
    $owners = []; // 動画 ID => [band_id, ...]
    foreach ($bandVideos as $bandId => $vids) {
        foreach ($vids as $vid) {
            $owners[$vid][] = (int)$bandId;
        }
    }
    $out = ['bands' => [], 'unmatched' => []];
    foreach ($files as $f) {
        $vid = preg_match('/^([A-Za-z0-9_-]{11})\.mp3$/i', (string)$f['name'], $m) ? $m[1] : '';
        if (!isset($owners[$vid])) {
            $out['unmatched'][] = (string)$f['name'];
            continue;
        }
        foreach ($owners[$vid] as $bandId) {
            $out['bands'][$bandId][] = $f + ['video_id' => $vid];
        }
    }
    return $out;
}

/**
 * drive_yt_match で割り当てたファイルを、YouTube の区分でバンドのファイルの後ろに足す。もう紐付けてあるファイルは区分と大きさだけ直す
 * @param array $files [['file_id', 'name', 'mime_type', 'size', 'video_id'], ...]
 */
function drive_add_yt(PDO $pdo, int $bandId, array $files): void
{
    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM band_drive_file WHERE band_id = ?');
    $st->execute([$bandId]);
    $order = (int)$st->fetchColumn();
    $up = $pdo->prepare('INSERT INTO band_drive_file (band_id, file_id, name, mime_type, size, is_mix, video_id, sort_order) VALUES (?, ?, ?, ?, ?, 0, ?, ?)
        ON DUPLICATE KEY UPDATE size = COALESCE(VALUES(size), size), is_mix = 0, video_id = VALUES(video_id)');
    foreach ($files as $f) {
        if (drive_id_valid((string)$f['file_id'])) {
            $up->execute([$bandId, $f['file_id'], mb_substr((string)$f['name'], 0, 255), substr((string)$f['mime_type'], 0, 100),
                isset($f['size']) ? (int)$f['size'] : null, $f['video_id'], ++$order]);
        }
    }
}

/**
 * ミックスのあるバンド（ミックスの印を付けたドライブのファイル か、アップロードした MP3 がある）
 * @param int[] $bandIds
 * @return array<int, true> band_id => true
 */
function band_mix_ids(PDO $pdo, array $bandIds): array
{
    $bandIds = array_values(array_unique(array_map('intval', $bandIds)));
    if (!$bandIds) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($bandIds), '?'));
    $st = $pdo->prepare("SELECT band_id FROM band_drive_file WHERE is_mix = 1 AND band_id IN ($marks)
        UNION SELECT band_id FROM band_audio WHERE video_id IS NULL AND band_id IN ($marks)"); // YouTube の区分の MP3 は MIX ではない
    $st->execute([...$bandIds, ...$bandIds]);
    return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
}

/**
 * MIX の印（ミックスのあるバンドに付ける）。緑の角丸に白抜きの M・I・X（色と線は CSS の .tag--mix）
 */
function mix_tag(string $extraClass = ''): string
{
    return '<span class="tag tag--mix' . ($extraClass !== '' ? ' ' . h($extraClass) : '') . '" role="img" aria-label="MIX">'
        . '<svg viewBox="0 0 25 14" aria-hidden="true" focusable="false"><rect width="25" height="14" rx="3" fill="currentColor"/>'
        . '<path d="M4 11V3l3 4 3-4v8M13.5 3v8M16.5 3l4.5 8m0-8l-4.5 8"/></svg></span>';
}

/**
 * ファイルの容量が分からない音源（size が NULL）を、ドライブに1回ずつ聞いて埋める（再生前にサイズを出すため）
 *   読めなかったファイルはそのまま（次に開いたときにまた試す）。$files は参照渡しで size も直す
 */
function drive_backfill_sizes(PDO $pdo, int $bandId, array &$files): void
{
    $up = $pdo->prepare('UPDATE band_drive_file SET size = ? WHERE band_id = ? AND file_id = ?');
    foreach ($files as &$f) {
        if ($f['size'] !== null || !str_starts_with((string)$f['mime_type'], 'audio/')) {
            continue;
        }
        try {
            $size = drive_file_meta((string)$f['file_id'])['size'];
        } catch (RuntimeException) {
            $size = null;
        }
        // 取れなかったら 0（= 不明）を入れて、ページを開くたびに聞き直さない
        $up->execute([$size ?? 0, $bandId, $f['file_id']]);
        $f['size'] = $size ?? 0;
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
 *   並びは「音源 → 動画 → 写真 → リンク」。種類ごとに見た目を変える
 *     音源       … 1ファイルにつき1つのプレーヤー（audio_player_html）。$skipAudio = true なら出さない（band.php は自分で並べる）
 *     動画       … プレーヤー（iframe）は1つだけ。2つ以上あるときは上のチップで切り替える
 *                  切り替えは JS（setupDriveSwitch）が iframe の src を差し替えるだけ。全部並べるより軽い
 *     写真       … .live-thumbs（ライブ・バンドの添付画像と同じ見た目）。押すとドライブで開く
 *     それ以外   … .chip-list のチップ。押すとドライブで開く
 *   名前はタイトル（バンド編集で付ける）があればタイトル、無ければファイル名
 * @param array $files [['band_id', 'file_id', 'name', 'title', 'mime_type', 'size'], ...]
 */
function drive_files_html(array $files, bool $skipAudio = false): string
{
    static $panel = 0; // 1ページに何個も出るので、ラジオボタンの name が重ならないよう番号を振る
    $panel++;
    $groups = ['audio' => [], 'video' => [], 'image' => [], 'other' => []];
    foreach ($files as $f) {
        if (drive_id_valid((string)$f['file_id'])) {
            $kind = drive_kind((string)$f['mime_type']);
            $groups[$kind === 'media' ? (str_starts_with((string)$f['mime_type'], 'audio/') ? 'audio' : 'video') : $kind][] = $f;
        }
    }
    $html = '';
    foreach ($skipAudio ? [] : $groups['audio'] as $f) {
        $html .= audio_player_html('drive_audio?band=' . (int)$f['band_id'] . '&f=' . rawurlencode((string)$f['file_id']),
            drive_file_title($f), isset($f['size']) ? (int)$f['size'] : null, null);
    }
    if ($groups['video']) {
        $first = $groups['video'][0];
        $html .= '<figure class="drive-player" data-drive-switch>';
        if (count($groups['video']) > 1) {
            // ラジオボタン（1つだけ選べる）。見た目は既存のチップ（.pick-chip）。選ぶと JS がプレーヤーの src を差し替える
            $html .= '<div class="drive-picks" role="radiogroup" aria-label="再生するファイル">';
            foreach ($groups['video'] as $i => $f) {
                $html .= '<label class="pick-chip pick-chip--who"><input type="radio" name="drive-mix-' . $panel . '"'
                    . ' value="' . h(drive_preview_url((string)$f['file_id'])) . '" data-drive-src' . ($i === 0 ? ' checked' : '') . '>'
                    . drive_type_icon((string)$f['mime_type']) . h(drive_file_title($f)) . '</label>';
            }
            $html .= '</div>';
        } else {
            $html .= '<figcaption class="drive-player__name">' . drive_type_icon((string)$first['mime_type']) . h(drive_file_title($first)) . '</figcaption>';
        }
        $html .= drive_player_html((string)$first['file_id'], drive_file_title($first)) . '</figure>';
    }
    if ($groups['image']) {
        $html .= '<ul class="live-thumbs drive-thumbs">';
        foreach ($groups['image'] as $f) {
            // 押すとドライブへ飛ばず、添付画像と同じモーダル（app_admin.js の setupLiveImages）で開く
            $html .= '<li><button type="button" class="live-thumbs__btn" data-live-img="' . h((string)$f['file_id']) . '"'
                . ' data-live-src="drive_image?band=' . (int)$f['band_id'] . '&amp;f=" aria-label="' . h(drive_file_title($f)) . '" title="' . h(drive_file_title($f)) . '">'
                . '<img src="' . h(drive_thumb_url((string)$f['file_id'])) . '" alt="" loading="lazy" referrerpolicy="no-referrer"></button></li>';
        }
        $html .= '</ul>' . attach_modal_html();
    }
    if ($groups['other']) {
        $html .= '<div class="chip-list chip-list--sm drive-links">';
        foreach ($groups['other'] as $f) {
            if ((string)$f['mime_type'] === 'application/pdf') { // PDF はドライブへ飛ばず、モーダルの中で開く
                $html .= '<button type="button" class="chip" data-live-img="' . h((string)$f['file_id']) . '" data-live-pdf="1" data-live-tab="1"'
                    . ' data-live-src="drive_image?band=' . (int)$f['band_id'] . '&amp;f=">'
                    . drive_type_icon((string)$f['mime_type']) . h(drive_file_title($f)) . '</button>' . attach_modal_html();
                continue;
            }
            $html .= '<a class="chip" href="' . h(drive_file_url((string)$f['file_id'])) . '" target="_blank" rel="noopener noreferrer">'
                . drive_type_icon((string)$f['mime_type']) . h(drive_file_title($f)) . '</a>';
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

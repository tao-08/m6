<?php
/**
 * =====================================================================
 *  lib/drive.php — Google ドライブのフォルダを読んで、ラインミックスをバンドに割り当てる
 * =====================================================================
 *  流れは lib/youtube.php（プレイリスト → バンド）とまったく同じ:
 *    1. ライブにフォルダのリンク（live.drive_url）を入れる
 *    2. フォルダの中のファイル一覧を Google Drive API v3 の files.list で取る
 *    3. ファイル名にバンド名が入っているかで結び付ける（youtube_match_bands をそのまま使う）
 *    4. 確認画面（live_drive.php）で人が確かめてから band.drive_url に保存
 *
 *  ■ API キー
 *    YouTube と同じ Google Cloud のプロジェクトで「Google Drive API」も有効にすれば、同じキーが使える。
 *    config.php に drive.api_key があればそれを、無ければ youtube.api_key を使う。
 *    ※ キーで読めるのは「リンクを知っている全員」に共有したフォルダだけ（キーは「誰か」ではないので、
 *      特定の人だけに共有したフォルダは見えない）
 *
 *  ■ 再生
 *    https://drive.google.com/file/d/ファイルID/preview を iframe で埋め込むと、ドライブのプレーヤーで再生できる。
 *    （音源ファイル自体をこのサーバーに置かないので、容量も転送量も食わない）
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

/**
 * フォルダの中の音声・動画ファイルを全部取ってくる（サブフォルダの中までは見ない）
 *   戻り値: [['file_id' => '1AbC…', 'name' => '12月 スピッツ.wav'], ...]（名前順）
 * @throws RuntimeException キーが無い・通信できない・フォルダが見つからない
 */
function drive_folder_files(string $folderId): array
{
    // drive 用のキーが空なら YouTube のキーを使う
    //   ?? は「null のときだけ」右へ進む。config.sample.php は '' で置いてあるので、'' でも右へ進む ?: で受ける
    //   （?: は「左が false 扱い（'' / 0 / null など）なら右」）
    $key = (string)((config('drive')['api_key'] ?? '') ?: (config('youtube')['api_key'] ?? ''));
    if ($key === '') {
        throw new RuntimeException('config.php に Google の API キー（drive.api_key か youtube.api_key）が設定されていません');
    }
    $files = [];
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
        if ($res === null) {
            throw new RuntimeException('Google ドライブに接続できませんでした。時間をおいてもう一度試してください');
        }
        if ($res['status'] === 404) {
            throw new RuntimeException('フォルダが見つかりません（共有が「リンクを知っている全員」になっているか確認してください）');
        }
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            $reason = $res['json']['error']['message'] ?? ('HTTP ' . $res['status']);
            throw new RuntimeException('Google ドライブのフォルダを読めませんでした（' . $reason . '）');
        }
        foreach ($res['json']['files'] ?? [] as $f) {
            $id = (string)($f['id'] ?? '');
            $mime = (string)($f['mimeType'] ?? '');
            // 音声（audio/wav, audio/mpeg …）と動画（video/mp4 …）だけ。PDF やサブフォルダは飛ばす
            if (!drive_id_valid($id) || !preg_match('~^(audio|video)/~', $mime)) {
                continue;
            }
            $files[] = ['file_id' => $id, 'name' => (string)($f['name'] ?? '')];
        }
        $pageToken = (string)($res['json']['nextPageToken'] ?? '');
        if ($pageToken === '') {
            break;
        }
    }
    return $files;
}

/**
 * ファイルをバンドに割り当てる（DB も通信も使わない → tests/run.php でテストできる）
 *   中身は youtube_match_bands。あちらは ['video_id', 'title'] の形を受け取るので、詰め替えて渡すだけ
 *   （同じロジックを2回書かない = DRY: Don't Repeat Yourself）
 *
 * @param array $files [['file_id' => ..., 'name' => ...], ...]
 * @param array $bands youtube_match_bands と同じ [band_id => ['name' => ..., 'names' => [...]], ...]
 * @return array{bands: array<int, string[]>, auto: array<int, string>, unmatched: string[]} 中身はファイル ID
 */
function drive_match_bands(array $files, array $bands): array
{
    $list = [];
    foreach ($files as $f) {
        // 拡張子を外してから比べる（「.wav」の wav がバンド名に当たったりしないように）
        $list[] = ['video_id' => $f['file_id'], 'title' => drive_strip_ext($f['name'])];
    }
    return youtube_match_bands($list, $bands);
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

/** ドライブの印（三角のロゴではなく、音源だとわかるヘッドホン）。Material Symbols を使う */
function drive_icon(): string
{
    return icon('headphones');
}

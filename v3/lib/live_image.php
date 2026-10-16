<?php
/**
 * =====================================================================
 *  lib/live_image.php — ライブの添付画像（live_image）
 * =====================================================================
 *  保存先は storage/live_images/（.htaccess で URL から直接は開けない）。
 *  見せるときは live_image.php がログインを確かめてから出す。
 *  アップロードで確かめていること:
 *    ・ちゃんとアップロードされたファイルか（is_uploaded_file）
 *    ・中身が本当に JPEG / PNG / WebP / GIF か（getimagesize。拡張子は信用しない）
 *    ・保存する名前はこちらで決める（ランダム）。送られてきたファイル名は使わない
 *    ・最大 1600px に縮小して JPEG で保存し直す（EXIF の位置情報などは消える）
 *  撮影日時だけは、縮小する前に EXIF（DateTimeOriginal）から読んで DB に残す
 * =====================================================================
 */
declare(strict_types=1);

const LIVE_IMAGE_MAX = 5;                       // 1ライブに付けられる枚数
const LIVE_IMAGE_MAX_BYTES = 15 * 1024 * 1024;  // 1枚あたり（スマホの写真が入る大きさ）
const LIVE_IMAGE_DIR = __DIR__ . '/../storage/live_images';

/** @return array live_id => [画像の行, ...]（表示順） */
function live_images(PDO $pdo, array $liveIds): array
{
    if (!$liveIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($liveIds), '?'));
    $st = $pdo->prepare("SELECT * FROM live_image WHERE live_id IN ($in) ORDER BY sort_order, image_id");
    $st->execute(array_values($liveIds));
    $by = [];
    foreach ($st as $r) {
        $by[(int)$r['live_id']][] = $r;
    }
    return $by;
}

/**
 * フォームの <input type="file" name="images[]" multiple> を PHP が扱いやすい形（1枚ずつの配列）にする。
 * 選ばれていない枠（UPLOAD_ERR_NO_FILE）は捨てる
 */
function live_image_uploads(mixed $files): array
{
    if (!is_array($files) || !is_array($files['name'] ?? null)) {
        return [];
    }
    $out = [];
    foreach (array_keys($files['name']) as $i) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['error' => $files['error'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i]];
    }
    return $out;
}

/** 撮影日時（EXIF）。読めなければ null。Y-m-d H:i:s */
function live_image_taken_at(string $path): ?string
{
    if (!function_exists('exif_read_data')) {
        return null;
    }
    $exif = @exif_read_data($path, 'EXIF');
    $raw = is_array($exif) ? (string)($exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? '') : '';
    // 形式は「2025:09:20 18:30:00」
    if (!preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $raw, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }
    return "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
}

/** GD の画像を長辺 $max 以内に縮小して JPEG で書き出す */
function live_image_write(GdImage $src, int $max, string $dest, int $quality): void
{
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, $max / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // 透過 PNG は白地に
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($dst, $dest, $quality);
    if (!$ok) {
        throw new RuntimeException('画像を保存できませんでした');
    }
}

/**
 * アップロードされた1枚を確かめて保存する。
 * @return array{file_name: string, taken_at: ?string}
 * ダメなら理由の文字列を投げる（RuntimeException）
 */
function live_image_store(array $file): array
{
    $err = $file['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || $file['size'] > LIVE_IMAGE_MAX_BYTES) {
        throw new RuntimeException('画像が大きすぎます（15MB まで）');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('画像のアップロードに失敗しました。もう一度選んでください');
    }
    $info = @getimagesize($file['tmp_name']);
    $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
    if ($info === false || !isset($loaders[$info[2]])) {
        throw new RuntimeException('JPEG・PNG・WebP・GIF の画像を選んでください');
    }
    if ($info[0] * $info[1] > 50_000_000) { // 縮小で使うメモリが膨らみすぎる巨大画像は断る
        throw new RuntimeException('画像のサイズが大きすぎます');
    }
    $takenAt = $info[2] === IMAGETYPE_JPEG ? live_image_taken_at($file['tmp_name']) : null;
    $src = @$loaders[$info[2]]($file['tmp_name']);
    if (!$src) {
        throw new RuntimeException('画像を読み込めませんでした');
    }
    // スマホの写真は「回転する向き」を EXIF に持っているので、見た目の向きに直してから保存する
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($file['tmp_name'], 'IFD0')['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle !== 0 && ($rot = imagerotate($src, $angle, 0))) {
            $src = $rot;
        }
    }
    if (!is_dir(LIVE_IMAGE_DIR) && !mkdir(LIVE_IMAGE_DIR, 0775, true)) {
        throw new RuntimeException('保存先のフォルダを作れませんでした');
    }
    $name = bin2hex(random_bytes(8)) . '.jpg'; // 名前はこちらで決める（推測されにくく、ぶつからない）
    live_image_write($src, 1600, LIVE_IMAGE_DIR . '/' . $name, 85);
    live_image_write($src, 360, LIVE_IMAGE_DIR . '/t_' . $name, 80); // サムネイル
    return ['file_name' => $name, 'taken_at' => $takenAt];
}

/** 画像ファイル（本体とサムネイル）を消す。file_name は DB の値だが、念のため形も確かめる */
function live_image_unlink(string $fileName): void
{
    if (preg_match('/^[0-9a-f]{16}\.jpg$/', $fileName)) {
        @unlink(LIVE_IMAGE_DIR . '/' . $fileName);
        @unlink(LIVE_IMAGE_DIR . '/t_' . $fileName);
    }
}

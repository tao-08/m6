-- =====================================================================
--  バンドに Google ドライブのファイルを「何個でも」紐付ける（音源・動画・写真・PDF など種類は問わない）
--  101_drive_mix.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  101 では band.drive_url（1バンド1ファイル）にしていたが、複数持てるように子テーブルへ移す。
--    1つのバンド → たくさんのファイル（1対多）。列を drive_url_1, drive_url_2 … と増やすのは
--    正規化の失敗パターン（いくつ必要か決められない・検索しにくい）なので、行で持つ。
--  band.drive_url は本番でまだ1件も使っていないので、移行せずに消す。
--  live.drive_url（ライブのフォルダ）はそのまま使う。中の「バンド名のサブフォルダ」を各バンドに割り当てる。
-- =====================================================================
SET NAMES utf8mb4;

-- band_drive_file — バンドに紐付けたドライブのファイル（1行 = 1ファイル）
--   file_id   … ドライブのファイル ID（英数字と - _ だけなので ascii。URL はこの ID から PHP で組み立てる）
--   name      … 表示名（ファイル名。拡張子つきのまま持ち、表示のときに外す）
--   mime_type … 種類（audio/wav, image/jpeg, application/pdf …）。表示の仕方を決める（プレーヤー / サムネイル / リンク）
--   sort_order… 並び順（フォルダの名前順）
--   バンドを消したらファイルの紐付けも消える（ON DELETE CASCADE。ドライブのファイル自体は消えない）
CREATE TABLE band_drive_file (
    band_id    INT UNSIGNED      NOT NULL,
    file_id    VARCHAR(100)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name       VARCHAR(255)      NOT NULL,
    mime_type  VARCHAR(100)      CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (band_id, file_id),          -- 同じバンドに同じファイルを2回入れない
    CONSTRAINT fk_bdf_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE band DROP COLUMN drive_url;

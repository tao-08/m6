-- =====================================================================
--  128_band_audio.sql — バンドにミックス音源（MP3）を直接アップロードできるようにする
-- =====================================================================
--  127_sched_google_calendar.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--
--  band_audio … バンドに置いた MP3（1行 = 1ファイル、1バンドに最大3つ。上限はアプリ側 ATTACH_KINDS）
--    ファイル本体は storage/live_images/（画像と同じ置き場。見せるときは attach_image.php がログインを確かめる）
--    title … 再生バーの上に出す名前。アップロードしたファイル名（拡張子なし）が入り、バンド編集で直せる
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE band_audio (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    band_id    INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    title      VARCHAR(100) NOT NULL DEFAULT '',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_ba_band (band_id, sort_order),
    CONSTRAINT fk_ba_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

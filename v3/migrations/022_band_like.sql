-- =====================================================================
--  バンドの「お気に入り（❤）」を記録する（live.php のタイムテーブル / band.php のバンド名の右。api_band_like.php）
--  021_app_setting.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  画面に出すのは「数」だけ。誰が押したかはどこにも表示しない（匿名）。
--  user_id を持つのは「同じ人が2回押せない」「もう一度押すと取り消せる」ため。
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE band_like (
    band_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (band_id, user_id),                    -- 1人1バンド1回（2回目の INSERT は主キーで弾かれる）
    KEY idx_like_user (user_id),
    CONSTRAINT fk_like_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,           -- バンドが消えたら ❤ も消える
    CONSTRAINT fk_like_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE            -- アカウントが消えたらその人の ❤ も消える
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

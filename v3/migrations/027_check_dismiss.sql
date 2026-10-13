-- =====================================================================
--  🚩 確認依頼のお知らせ（トップページ）を ✕ で消した記録
--  026_band_check_reason.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  check_dismiss … 誰が（user_account）どのバンドのお知らせを消したか（中間テーブル）
--                   旗を外すと、そのバンドの行は消す（立て直したらまた届くように。save_check_members）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE check_dismiss (
    user_id INT UNSIGNED NOT NULL,
    band_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, band_id),
    KEY idx_cd_band (band_id),
    CONSTRAINT fk_cd_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cd_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

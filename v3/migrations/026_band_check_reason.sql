-- =====================================================================
--  🚩 確認依頼に「何についてか（理由）」と「誰について（メンバー）」を付ける
--  025_contact.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  band.needs_check … 0 = 依頼なし。それ以外は理由のビットの組み合わせ（複数選べる）
--                       1 = 担当楽器が合っているか不明（今までの 1 はこれになる）
--                       2 = Vo か Vo/Gt か不明
--                       4 = メンバーが合っているか不明
--  band_check_member … 誰について確認してほしいか（バンド × メンバーの中間テーブル。複数選べる）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band
    MODIFY COLUMN needs_check TINYINT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE band_check_member (
    band_id   INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (band_id, member_id),
    KEY idx_bcm_member (member_id),
    CONSTRAINT fk_bcm_band   FOREIGN KEY (band_id)   REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bcm_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

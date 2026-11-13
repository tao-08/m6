-- =====================================================================
--  ブロックパズル（block.php）の自己ベスト表を作る
--  103_band_drive_file_title.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  block_best — 部員ごとの自己ベスト（1人1行）。ランキングはこの表の score が高い順
--    user_id が主キー = 1人につき1行しか持てない（自己ベストより高いときだけ上書きする = api_block_score.php）
--    `lines` は MySQL の予約語（LOAD DATA の LINES TERMINATED BY）なので、SQL の中ではいつも `lines` とバッククォートで囲む
--    level は lines から決まる値（1 + lines / 10、上限 20）だが、ランキングに出すため列に持つ
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE block_best (
    user_id    INT UNSIGNED      NOT NULL,
    score      INT UNSIGNED      NOT NULL,
    `lines`    SMALLINT UNSIGNED NOT NULL,
    level      TINYINT UNSIGNED  NOT NULL,
    updated_at DATETIME          NOT NULL,
    PRIMARY KEY (user_id),
    KEY idx_block_score (score),
    CONSTRAINT fk_block_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE                  -- アカウントが消えたら、その人の記録も消える
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

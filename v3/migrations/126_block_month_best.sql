-- =====================================================================
--  ブロックパズル（block.php）の月間ランキングの表を作る
--  125_intro_best.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  block_month_best — 部員ごと・月ごとの自己ベスト（1人につき1か月1行）。月間ランキングはこの表の score が高い順
--    block_best（月を問わない自己ベスト = ベストスコア）はそのまま残す
--    month_start はその月の1日（Asia/Tokyo）。主キー (user_id, month_start) = 1人が同じ月に持てる行は1つ
--    （その月の自己ベストより高いときだけ上書きする = api_block_score.php）
--    `lines` は MySQL の予約語なので、SQL の中ではいつも `lines` とバッククォートで囲む
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE block_month_best (
    user_id     INT UNSIGNED      NOT NULL,
    month_start DATE              NOT NULL,
    score       INT UNSIGNED      NOT NULL,
    `lines`     SMALLINT UNSIGNED NOT NULL,
    level       TINYINT UNSIGNED  NOT NULL,
    updated_at  DATETIME          NOT NULL,
    PRIMARY KEY (user_id, month_start),
    KEY idx_block_month_score (month_start, score),
    CONSTRAINT fk_block_month_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE                  -- アカウントが消えたら、その人の記録も消える
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 今月に出した自己ベストは、今月の記録として移す（流した時点の月。それ以前に出した記録は月間には入らず、ベストスコアにだけ残る）
INSERT INTO block_month_best (user_id, month_start, score, `lines`, level, updated_at)
SELECT user_id, DATE_FORMAT(CURDATE(), '%Y-%m-01'), score, `lines`, level, updated_at
FROM block_best
WHERE score > 0 AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01');

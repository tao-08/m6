-- =====================================================================
--  イントロクイズ（intro.php）の月間ランキングの表を作る
--  124_sched_fix.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  intro_best — 部員ごと・月ごとの自己ベスト（1人につき1か月1行）。ランキングは同じ月の score が高い順
--    month_start はその月の1日（Asia/Tokyo）。主キー (user_id, month_start) = 1人が同じ月に持てる行は1つ
--    （自己ベストより高いときだけ上書きする = api_intro.php）。月が変わると新しい行になるので、前の月の記録がそのまま「先月」になる
--    correct は正解した問題数（0〜10）。score は 1 問 100〜1000 点の合計（満点 10000）
--    ランキングに載るのは、絞り込みなしで 10 問遊んだときだけ
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE intro_best (
    user_id     INT UNSIGNED     NOT NULL,
    month_start DATE             NOT NULL,
    score       INT UNSIGNED     NOT NULL,
    correct     TINYINT UNSIGNED NOT NULL,
    updated_at  DATETIME         NOT NULL,
    PRIMARY KEY (user_id, month_start),
    KEY idx_intro_month_score (month_start, score),
    CONSTRAINT fk_intro_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE                  -- アカウントが消えたら、その人の記録も消える
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

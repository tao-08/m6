-- =====================================================================
--  ログイン・招待コードの「失敗」を記録する（総当たり攻撃を止めるため。lib/bootstrap.php の too_many_failures）
--  018_venue_role_sort_order.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  1回失敗するたびに1行。成功したらそのログインIDの行を消す。1日より古い行は失敗を記録するついでに消す。
--  パスワードそのものは絶対に入れない（ログインIDと IP アドレスと時刻だけ）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE login_attempt (
    attempt_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip           VARCHAR(45)     NOT NULL,             -- IPv6 でも入る長さ
    login_id     VARCHAR(25)     NOT NULL,             -- 招待コードの失敗は '#invite'
    attempted_at DATETIME        NOT NULL,
    PRIMARY KEY (attempt_id),
    KEY idx_attempt_ip (ip, attempted_at),
    KEY idx_attempt_login (login_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

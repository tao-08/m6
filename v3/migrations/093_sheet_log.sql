-- =====================================================================
--  093_sheet_log.sql — セッティングシートの PDF 作成ログ
-- =====================================================================
--  PDF を作るたびに 1 行（誰が・いつ・どの型紙・どのバンド）。管理者が sheet_log.php で見る。無期限で残す
--  ユーザー・バンドが消えても記録は残す（ON DELETE SET NULL。名前は band_name に控える）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると PDF 作成時の記録が失敗する）
-- =====================================================================
CREATE TABLE sheet_log (
    log_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED    NULL,
    band_id      INT UNSIGNED    NULL COMMENT 'DB のバンドから作ったときだけ。白紙は NULL',
    band_name    VARCHAR(100)    NOT NULL DEFAULT '',
    held_on      VARCHAR(30)     NOT NULL DEFAULT '' COMMENT 'シートに書いた出演日（書いたままの文字）',
    template_key VARCHAR(100)    NOT NULL DEFAULT '',
    created_at   DATETIME        NOT NULL,
    PRIMARY KEY (log_id),
    KEY idx_sheet_log_created (created_at),
    CONSTRAINT fk_sheet_log_user FOREIGN KEY (user_id) REFERENCES user_account (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_sheet_log_band FOREIGN KEY (band_id) REFERENCES band (band_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

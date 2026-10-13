-- =====================================================================
--  変更履歴と「元に戻す」（history.php・lib/history.php）
--  027_check_dismiss.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  change_op   … 1回の操作（保存ボタン1回 = 1行）。誰が・いつ・何をしたか
--  change_unit … その操作で変わったもの（ライブ / 日程 / バンド）1つ分の「変更前の状態」（change_op と 1対多）
--                 snapshot が NULL = 変更前は無かった（その操作で新しく作られた）
--  1年より古い操作は、新しく記録するときに自動で消す（change_unit は CASCADE で一緒に消える）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE change_op (
    op_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,          -- 誰が（アカウントを消したら NULL = 削除済みのユーザー）
    action      VARCHAR(30)  NOT NULL,      -- band_edit / timetable / import ...（lib/history.php の CHANGE_ACTIONS）
    summary     VARCHAR(255) NOT NULL,
    live_id     INT UNSIGNED NULL,          -- 絞り込み用。ライブが消えても履歴は残すので外部キーにしない
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at DATETIME     NULL,          -- 「元に戻す」をした日時
    restored_by INT UNSIGNED NULL,
    PRIMARY KEY (op_id),
    KEY idx_op_created (created_at),
    KEY idx_op_live (live_id),
    CONSTRAINT fk_op_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_op_restored_by FOREIGN KEY (restored_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE change_unit (
    op_id     INT UNSIGNED                 NOT NULL,
    unit_type ENUM('live', 'day', 'band')  NOT NULL,
    unit_id   INT UNSIGNED                 NOT NULL,
    label     VARCHAR(150)                 NOT NULL,  -- 表示用（2024年度 2月ライブ 1日目「King Gnu」）
    snapshot  MEDIUMBLOB                   NULL,      -- 変更前の行の JSON（圧縮）。NULL = 変更前は無かった
    PRIMARY KEY (op_id, unit_type, unit_id),
    KEY idx_unit_target (unit_type, unit_id),
    CONSTRAINT fk_unit_op FOREIGN KEY (op_id) REFERENCES change_op (op_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

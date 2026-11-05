-- =====================================================================
--  097_studio_history.sql — スタジオ情報の編集履歴（変更前の状態を残して、あとから復元できるようにする）
-- =====================================================================
--  保存・AI反映・削除のたびに、「その操作の前」のスタジオ1件ぶん（基本情報・部屋・割引区分・料金・パック・レンタル・機材）を
--  JSON（圧縮して base64）で1行に残す。復元すると、その状態に戻る（戻す前の状態も新しい行として残るので、戻したこともまた戻せる）
--  スタジオを削除しても履歴は残す → studio_id に外部キーは付けない（名前は studio_name に控える）。削除したスタジオも復元できる
--  ユーザーが消えても記録は残す（ON DELETE SET NULL）。1年より古い行は、新しく記録するときに消す
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（流す前でも編集はできるが、履歴は残らない）
-- =====================================================================
CREATE TABLE studio_history (
    history_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    studio_id   INT UNSIGNED    NOT NULL,
    studio_name VARCHAR(100)    NOT NULL,
    user_id     INT UNSIGNED    NULL,
    action      VARCHAR(30)     NOT NULL COMMENT 'save_info / save_rates / delete_studio など（lib/studio_history.php の STUDIO_HISTORY_ACTIONS）',
    snapshot    MEDIUMTEXT      NOT NULL COMMENT '操作の前の状態（圧縮した JSON）',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    KEY idx_studio_history_studio (studio_id, history_id),
    KEY idx_studio_history_created (created_at),
    CONSTRAINT fk_studio_history_user FOREIGN KEY (user_id) REFERENCES user_account (user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

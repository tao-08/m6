-- =====================================================================
--  プロフィールに「学部」と「係」を追加する
--  015_band_needs_check.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  学部: 1人1つなので member の列にする。選べる値は FACULTIES（lib/bootstrap.php）と同じ
--  係  : 1人で何個も持てる（会計＋PA など）ので、マスタ role ＋ 中間テーブル member_role（many-to-many）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE member
    ADD COLUMN faculty VARCHAR(20) NULL AFTER entry_year,
    ADD CONSTRAINT ck_member_faculty CHECK (faculty IS NULL OR faculty IN
        ('法学部', '政治経済学部', '商学部', '経営学部', '文学部', '情報コミュニケーション学部',
         '総合数理学部', '理工学部', '農学部', '国際日本学部'));

CREATE TABLE role (
    role_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name    VARCHAR(30)  NOT NULL,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE member_role (
    member_id INT UNSIGNED NOT NULL,
    role_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (member_id, role_id),
    CONSTRAINT fk_mr_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mr_role FOREIGN KEY (role_id) REFERENCES role (role_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

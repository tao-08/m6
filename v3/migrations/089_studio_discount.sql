-- =====================================================================
--  089_studio_discount.sql — 学割を「区分（学割・U20 …）」ごとに持てるようにする
-- =====================================================================
--  今まで: studio_rate / studio_pack の student_price（学割は1種類だけ・曜日の条件なし）
--  問題  : 「学割」と「U20」のように割引が複数あるスタジオを表せない。
--          「学割は平日のみ」のような曜日の条件も、シミュレーションで判定できなかった
--  解決  :
--    studio_discount         … スタジオごとの割引区分（名前・使える曜日）
--    studio_rate_discount    … 料金の行 × 区分 → その区分の1時間の料金（中間テーブル）
--    studio_pack_discount    … パック × 区分 → その区分のパック料金（中間テーブル）
--
--  ■ なぜ中間テーブルか（多対多）
--    1つの料金の行は複数の区分の料金を持ち（学割 ¥1,650 / U20 ¥1,100）、1つの区分は多くの料金の行に使われる = 多対多。
--    列（u20_price …）で持つと、区分が増えるたびに ALTER TABLE が要る。行で持てば「区分1つ = 1行」で何種類でも増やせる。
--    中間テーブルに price を持たせるのは「どの行の・どの区分の」料金かは、その組み合わせで初めて決まる値だから。
--
--  ■ studio_discount.day_type（使える曜日）
--    0 = 全日 / 1 = 平日のみ / 2 = 土日祝のみ（料金表と同じ区分。lib/studio_info.php の STUDIO_DAY_TYPES）
--    シミュレーションは、選んだ曜日で使えない区分なら通常料金で計算し、その理由を出す
--
--  ■ あわせて: 料金の行を「1行 = 1部屋（か全部屋共通）」にそろえる
--    料金の編集画面を「部屋ごとのブロック」にしたので、複数の部屋にまたがる料金の行（088 で可能にした）を、
--    部屋ごとの行に分ける（同じ料金を部屋の数だけ複製する）。パック・機材は今まで通り複数の部屋のまま。
--
--  実行順: 088 → 089。何度も流すと二重に分割・登録されるので、1回だけ流すこと
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- ① 複数の部屋にまたがる料金の行を、部屋ごとの行に分ける
--    やり方: 一時的な列 tmp_room を足し、「いちばん小さい部屋 id 以外」の部屋の分だけ行を複製する
--            → 元の行には最小の部屋だけを残し、複製した行には tmp_room の部屋を紐づける
--    （student_price も一緒に複製される。② で区分の料金に移すので、ここで先に分けておく）
-- ---------------------------------------------------------------------
ALTER TABLE studio_rate ADD COLUMN tmp_room INT UNSIGNED NULL;

-- 部屋ごとの最小の部屋 id（派生テーブル = FROM の中の SELECT。同じテーブルを読みながら書けない MySQL の制限を避ける）
INSERT INTO studio_rate (studio_id, people, day_type, start_min, end_min, price, student_price, sort_order, tmp_room)
SELECT r.studio_id, r.people, r.day_type, r.start_min, r.end_min, r.price, r.student_price, r.sort_order, l.studio_room_id
  FROM studio_rate r
  JOIN studio_rate_room l ON l.studio_rate_id = r.studio_rate_id
  JOIN (SELECT studio_rate_id, MIN(studio_room_id) AS keep_room FROM studio_rate_room GROUP BY studio_rate_id) k
    ON k.studio_rate_id = r.studio_rate_id
 WHERE l.studio_room_id <> k.keep_room;

-- 元の行からは、最小の部屋以外の紐づけを外す
DELETE l FROM studio_rate_room l
  JOIN (SELECT studio_rate_id, MIN(studio_room_id) AS keep_room FROM studio_rate_room GROUP BY studio_rate_id) k
    ON k.studio_rate_id = l.studio_rate_id
 WHERE l.studio_room_id <> k.keep_room;

-- 複製した行を、その部屋に紐づける
INSERT INTO studio_rate_room (studio_rate_id, studio_room_id)
SELECT studio_rate_id, tmp_room FROM studio_rate WHERE tmp_room IS NOT NULL;

ALTER TABLE studio_rate DROP COLUMN tmp_room;

-- ---------------------------------------------------------------------
-- ② 割引区分のテーブル
-- ---------------------------------------------------------------------
CREATE TABLE studio_discount (
    studio_discount_id INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    studio_id          INT UNSIGNED     NOT NULL,
    name               VARCHAR(30)      NOT NULL,          -- 例: 学割 / U20 / 高校生
    day_type           TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 使える曜日 0 = 全日 / 1 = 平日のみ / 2 = 土日祝のみ
    sort_order         INT UNSIGNED     NOT NULL DEFAULT 0,
    PRIMARY KEY (studio_discount_id),
    KEY idx_sdisc_studio (studio_id),
    CONSTRAINT fk_sdisc_studio FOREIGN KEY (studio_id) REFERENCES studio (studio_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE studio_rate_discount (
    studio_rate_id     INT UNSIGNED NOT NULL,
    studio_discount_id INT UNSIGNED NOT NULL,
    price              INT UNSIGNED NOT NULL,              -- その区分の1時間の料金
    PRIMARY KEY (studio_rate_id, studio_discount_id),      -- 2列の組を主キーに = 同じ組の重複を防ぐ
    KEY idx_srd_discount (studio_discount_id),
    CONSTRAINT fk_srd_rate FOREIGN KEY (studio_rate_id) REFERENCES studio_rate (studio_rate_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_srd_discount FOREIGN KEY (studio_discount_id) REFERENCES studio_discount (studio_discount_id)
        ON UPDATE CASCADE ON DELETE CASCADE                -- 区分を消したら、その区分の料金も消える
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE studio_pack_discount (
    studio_pack_id     INT UNSIGNED NOT NULL,
    studio_discount_id INT UNSIGNED NOT NULL,
    price              INT UNSIGNED NOT NULL,              -- その区分のパック1回の料金
    PRIMARY KEY (studio_pack_id, studio_discount_id),
    KEY idx_spd_discount (studio_discount_id),
    CONSTRAINT fk_spd_pack FOREIGN KEY (studio_pack_id) REFERENCES studio_pack (studio_pack_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_spd_discount FOREIGN KEY (studio_discount_id) REFERENCES studio_discount (studio_discount_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- ③ 今の学割（student_price）を、区分「学割」（全日）に移す
--    学割の料金が1つでもあるスタジオにだけ、区分「学割」を作る
-- ---------------------------------------------------------------------
INSERT INTO studio_discount (studio_id, name, day_type, sort_order)
SELECT s.studio_id, '学割', 0, 1
  FROM studio s
 WHERE EXISTS (SELECT 1 FROM studio_rate r WHERE r.studio_id = s.studio_id AND r.student_price IS NOT NULL)
    OR EXISTS (SELECT 1 FROM studio_pack p WHERE p.studio_id = s.studio_id AND p.student_price IS NOT NULL);

INSERT INTO studio_rate_discount (studio_rate_id, studio_discount_id, price)
SELECT r.studio_rate_id, d.studio_discount_id, r.student_price
  FROM studio_rate r JOIN studio_discount d ON d.studio_id = r.studio_id
 WHERE r.student_price IS NOT NULL;

INSERT INTO studio_pack_discount (studio_pack_id, studio_discount_id, price)
SELECT p.studio_pack_id, d.studio_discount_id, p.student_price
  FROM studio_pack p JOIN studio_discount d ON d.studio_id = p.studio_id
 WHERE p.student_price IS NOT NULL;

-- ④ 古い列を削除（移し終わってから）
ALTER TABLE studio_rate DROP COLUMN student_price;
ALTER TABLE studio_pack DROP COLUMN student_price;

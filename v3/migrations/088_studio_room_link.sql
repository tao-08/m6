-- =====================================================================
--  088_studio_room_link.sql — 料金・パック・機材を「複数の部屋に共通」にできるようにする（多対多）
-- =====================================================================
--  今まで: studio_rate / studio_pack / studio_equipment の studio_room_id（1行 = 1部屋 or 全部屋）
--  問題  : 「A・D・Gスタ共通の料金」を表すのに、同じ料金を部屋の数だけ入力する必要があった
--  解決  : 中間テーブル（連結テーブル）で「この料金 ←→ この部屋」を1組ずつ記録する
--
--  ■ 中間テーブルとは（多対多の解き方）
--    「料金は複数の部屋に使える」「部屋は複数の料金を持つ」= 多対多。1つの列には入れられないので、
--    (料金id, 部屋id) の組を1行ずつ持つ別のテーブルに分ける。主キーを2列の組にして、同じ組の重複を防ぐ。
--  ■ 意味づけ（今までと同じ）
--    部屋の行が1つも無い料金 = 全部屋共通。1つ以上あれば、その部屋だけ。
--  ■ 部屋を削除したとき
--    中間テーブルの行は CASCADE で消える。そのままだと「その部屋だけの料金」が部屋ゼロ = 全部屋共通に化けてしまうので、
--    アプリ側（studio_info.php の studio_save_rows）で、消える部屋だけに紐づく料金・パック・機材は先に削除している。
--
--  実行順: 086 → （studio_fill_4.sql）→ 087 → 088
--    ※ studio_fill_4.sql は旧形式（studio_room_id 列に入れる）なので、088 より前に流すこと。
--      088 の後で流し直すとエラーになる（新形式に作り直すので言ってください）。
-- =====================================================================

-- ① 中間テーブルを作る（料金 / パック / 機材 それぞれ）
CREATE TABLE studio_rate_room (
    studio_rate_id INT UNSIGNED NOT NULL,
    studio_room_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (studio_rate_id, studio_room_id),
    KEY idx_srr_room (studio_room_id),
    CONSTRAINT fk_srr_rate FOREIGN KEY (studio_rate_id) REFERENCES studio_rate (studio_rate_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_srr_room FOREIGN KEY (studio_room_id) REFERENCES studio_room (studio_room_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE studio_pack_room (
    studio_pack_id INT UNSIGNED NOT NULL,
    studio_room_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (studio_pack_id, studio_room_id),
    KEY idx_spr_room (studio_room_id),
    CONSTRAINT fk_spr_pack FOREIGN KEY (studio_pack_id) REFERENCES studio_pack (studio_pack_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_spr_room FOREIGN KEY (studio_room_id) REFERENCES studio_room (studio_room_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE studio_equipment_room (
    studio_equipment_id INT UNSIGNED NOT NULL,
    studio_room_id      INT UNSIGNED NOT NULL,
    PRIMARY KEY (studio_equipment_id, studio_room_id),
    KEY idx_ser_room (studio_room_id),
    CONSTRAINT fk_ser_equipment FOREIGN KEY (studio_equipment_id) REFERENCES studio_equipment (studio_equipment_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ser_room FOREIGN KEY (studio_room_id) REFERENCES studio_room (studio_room_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ② 今ある「1部屋だけ」の紐づけを中間テーブルに移す（NULL = 全部屋共通 は行を作らない）
INSERT INTO studio_rate_room (studio_rate_id, studio_room_id)
    SELECT studio_rate_id, studio_room_id FROM studio_rate WHERE studio_room_id IS NOT NULL;
INSERT INTO studio_pack_room (studio_pack_id, studio_room_id)
    SELECT studio_pack_id, studio_room_id FROM studio_pack WHERE studio_room_id IS NOT NULL;
INSERT INTO studio_equipment_room (studio_equipment_id, studio_room_id)
    SELECT studio_equipment_id, studio_room_id FROM studio_equipment WHERE studio_room_id IS NOT NULL;

-- ③ 古い列を削除（外部キーを先に外してから列を消す。この順番を逆にするとエラーになる）
ALTER TABLE studio_rate      DROP FOREIGN KEY fk_srate_room, DROP COLUMN studio_room_id;
ALTER TABLE studio_pack      DROP FOREIGN KEY fk_spack_room, DROP COLUMN studio_room_id;
ALTER TABLE studio_equipment DROP FOREIGN KEY fk_sequip_room, DROP COLUMN studio_room_id;

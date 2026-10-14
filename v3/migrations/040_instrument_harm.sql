-- 楽器に Harm（ハーモニカ）を追加する。id は自動採番、略称がもうあれば何もしない。
INSERT IGNORE INTO instrument (name, short_name, sort_order) VALUES ('ハーモニカ', 'Harm', 11);

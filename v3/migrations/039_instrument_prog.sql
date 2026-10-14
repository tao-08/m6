-- 楽器に Prog（同期音源）を追加する。
-- 名簿の取り込みで足した楽器と id がぶつからないように id は自動採番。略称がもうあれば何もしない（short_name は UNIQUE）。
INSERT IGNORE INTO instrument (name, short_name, sort_order) VALUES ('同期音源', 'Prog', 10);

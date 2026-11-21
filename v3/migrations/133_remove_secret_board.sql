-- 裏掲示板機能の廃止（データも削除する）
-- secret_board_post は secret_board_thread を参照するため先に削除する。
DROP TABLE IF EXISTS secret_board_post;
DROP TABLE IF EXISTS secret_board_thread;

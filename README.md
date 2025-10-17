# Introduction
TECH-BASEというインターンに参加して学んだPHPで制作した、サークル用データベース閲覧編集サイトです。
# 各ページについて
- login.php
  - ユーザー情報を入力
  - ユーザー登録がされていない場合はリンクからnew_register.phpへ遷移
- new_register.php
  - ユーザー新規登録を行いDBに登録
  - 登録後new_complete.phpに遷移
- new_complete.php
  - 新規登録情報を表示
  - そのままログイン可能
- mainpage.php
  - メインページ
- user_compile.php
  - ユーザー情報を編集

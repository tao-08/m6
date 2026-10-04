<?php
/**
 * 設定ファイルのひな形。
 * このファイルを config.php という名前でコピーして、自分の環境に合わせて書き換える。
 * config.php はパスワードが入るので git に入れない（.gitignore 済み）。
 */
return [
    'db' => [
        // v3 専用の DB（schema.sql を流したもの）
        'dsn'  => 'mysql:host=127.0.0.1;dbname=abbey_v3;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',   // XAMPP の初期状態は root / パスワードなし
    ],
    // PDF を読むときに使う poppler の pdftotext。PATH が通っていれば 'pdftotext' のままでOK
    // Windows の例: 'C:\\poppler\\Library\\bin\\pdftotext.exe'
    'pdftotext' => 'pdftotext',
    // 新規登録に必要な招待コード（合言葉）。'' なら誰でも登録できる
    // サークルの LINE などで共有しておくと、外部の人がメンバーの実名を見られなくなる
    'invite_code' => '',
    // true: 管理者が1人もいないとき、新規登録した人を管理者にする
    'first_user_is_admin' => true,
    // true: エラーの詳細を画面に出す（開発中だけ。公開するときは false）
    'debug' => false,
];

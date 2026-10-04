<?php
// このファイルを config.php にコピーして値を書き換える (config.php は git 管理外)
return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=m6_v2;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
    ],
    // PDF読込に使う poppler の pdftotext。PATH が通っていれば 'pdftotext' のままでOK
    // Windows例: 'C:\\poppler\\Library\\bin\\pdftotext.exe'
    'pdftotext' => 'pdftotext',
    // true にすると最初に登録したユーザーを管理者にする
    'first_user_is_admin' => true,
    'debug' => false,
];

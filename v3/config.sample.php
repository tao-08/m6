<?php
/**
 * 設定ファイルのひな形。
 * このファイルを config.php という名前でコピーして、自分の環境に合わせて書き換える。
 * config.php はパスワードが入るので git に入れない（.gitignore 済み）。
 *
 * ローカル（XAMPP）と本番サーバーの設定を1つのファイルに書いておけるので、
 * ローカルで動いている config.php をそのまま本番にアップロードしてよい。
 *
 *   common     … どの環境でも同じ値
 *   local      … ローカル（XAMPP）用。common を上書きする
 *   production … 本番サーバー用。common を上書きする
 *
 * どちらを使うかは lib/bootstrap.php の app_env() が決める。
 *   - サーバーの環境変数 APP_ENV が 'local' / 'production' ならそれ
 *   - 無ければ Windows なら local、それ以外（レンタルサーバーなど）は production
 */
return [
    'common' => [
        // PDF を読むときに使う poppler の pdftotext。PATH が通っていれば 'pdftotext' のままでOK
        'pdftotext' => 'pdftotext',
        // 新規登録に必要な招待コード（合言葉）。'' なら誰でも登録できる
        // サークルの LINE などで共有しておくと、外部の人がメンバーの実名を見られなくなる
        'invite_code' => '',
        // true: 管理者が1人もいないとき、新規登録した人を管理者にする
        'first_user_is_admin' => true,
        // 好きなアルバムの検索に使う Spotify のキー（https://developer.spotify.com/dashboard でアプリを作ると出る）
        //   空なら iTunes で検索する（キー不要。ただし邦楽のアルバム名がローマ字のことがある）
        //   ※ client_secret はパスワードと同じ。人に見せない・git に入れない
        'spotify' => [
            'client_id'     => '',
            'client_secret' => '',
        ],
        // true: エラーの詳細を画面に出す（開発中だけ）
        'debug' => false,
    ],

    'local' => [
        'db' => [
            // v3 専用の DB（schema.sql を流したもの）
            'dsn'  => 'mysql:host=127.0.0.1;dbname=abbey_v3;charset=utf8mb4',
            'user' => 'root',
            'pass' => '',   // XAMPP の初期状態は root / パスワードなし
        ],
        // Windows の例: 'C:\\poppler\\Library\\bin\\pdftotext.exe'
        'pdftotext' => 'pdftotext',
        'debug' => true,
    ],

    'production' => [
        'db' => [
            // レンタルサーバーの管理画面に出ている「ホスト名・DB名・ユーザー名・パスワード」を入れる
            'dsn'  => 'mysql:host=localhost;dbname=abbey_v3;charset=utf8mb4',
            'user' => '',
            'pass' => '',
        ],
        // 本番では必ず false（エラーの詳細からテーブル名やファイルの場所が見えてしまう）
        'debug' => false,
    ],
];

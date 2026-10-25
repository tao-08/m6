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
        // 新規登録に必要な招待コード（合言葉）の初期値。'' なら誰でも登録できる
        //   管理者が「ユーザー管理」（users.php）で変えたら、そっち（DB の app_setting）が優先される
        // サークルの LINE などで共有しておくと、外部の人がメンバーの実名を見られなくなる
        'invite_code' => '',
        'backup_token' => '', // DB バックアップの合言葉（32文字以上の乱数。GitHub の Secrets「BACKUP_TOKEN」と同じ値。空ならバックアップは使えない）
        // true: 管理者が1人もいないとき、新規登録した人を管理者にする
        'first_user_is_admin' => true,
        // 好きなアルバムの検索に使う Spotify のキー（https://developer.spotify.com/dashboard でアプリを作ると出る）
        //   空なら iTunes で検索する（キー不要。ただし邦楽のアルバム名がローマ字のことがある）
        //   ※ client_secret はパスワードと同じ。人に見せない・git に入れない
        'spotify' => [
            'client_id'     => '',
            'client_secret' => '',
        ],
        // ライブのプレイリストの動画をバンドに割り当てる（live_youtube.php）のに使う YouTube Data API v3 のキー
        //   Google Cloud Console でプロジェクトを作り、YouTube Data API v3 を有効にして「APIキー」を作ると出る
        //   キーの「APIの制限」は YouTube Data API v3 だけにしておく。空なら割り当て機能だけ使えない
        //   ※ パスワードと同じ。人に見せない・git に入れない
        'youtube' => [
            'api_key' => '',
        ],
        // 曲ページの「検索」（tab.php）で U-FRET・楽器.me のページを Google で探すための Programmable Search Engine（JSON API）
        //   1. https://programmablesearchengine.google.com/ で検索エンジンを作る（検索対象は ufret.jp と gakki.me、または「ウェブ全体」）→ 「検索エンジン ID」が cx
        //   2. Google Cloud Console で「Custom Search API」を有効にして API キーを作る（キーの「APIの制限」は Custom Search API だけに）
        //   無料枠は1日100回（検索1回 = 1サイト分。1曲で2回使う）。超えるとエラーになるだけで、料金は有効化しない限りかからない
        //   空なら全部 AI（anthropic）で探す。※ キーはパスワードと同じ。人に見せない・git に入れない
        'google_cse' => [
            'api_key' => '',
            'cx'      => '',
        ],
        // TAB の BPM を曲名から検索する（tab.php）のに使う GetSongBPM のキー（https://getsongbpm.com/api で無料登録）
        //   無料の条件: サイトのどこかに getsongbpm.com へのリンクを載せること（TAB の検索結果に出している）
        //   空なら BPM 検索のボタンだけ出ない
        'getsongbpm' => [
            'api_key' => '',
        ],
        // タイムテーブル・名簿の「画像」を AI（Claude）で読み取る（import.php）のに使う Anthropic の API キー
        //   https://console.anthropic.com で作る。Console の「Limits」で月の利用上限を必ず設定しておく
        //   空なら画像の読み取りだけ使えない（CSV / Excel / PDF はそのまま使える）
        //   ※ パスワードと同じ。人に見せない・git に入れない
        //   ※ 画像はAnthropic のサーバーに送られる（名簿なら部員の実名も）。サークルで了承を取ってから使う
        'anthropic' => [
            'api_key' => '',
            'model'   => 'claude-opus-5-5', // 1回 10円前後。安くしたいなら 'claude-sonnet-5-5'（約6円）/ 'claude-haiku-5-5'（1円未満）。精度は実物で比べて決める
        ],
        // TAB の BPM を AI が推定する（tab.php の ✨ボタン）のモデル。省略すると 'claude-sonnet-5-5'。上の anthropic.api_key を共用する
        //   anthropic に 'bpm_model' => 'claude-haiku-5-5'（安い・精度は劣る）/ 'claude-opus-5-5'（高い・マイナーな曲に強い）を足すと変えられる
        // 問い合わせ（contact.php）のメールの差出人。サイトと同じドメインのアドレスにする
        //   （違うドメインだと迷惑メール扱いされやすい）。空ならメールは送らず、サイトの中に残すだけ
        'mail_from' => '',
        // プッシュ通知（lib/push.php）の VAPID 鍵。空 or vendor/ が無ければ、プッシュ通知の機能だけ休む
        //   鍵の作り方: php -r '$k=openssl_pkey_new(["private_key_type"=>OPENSSL_KEYTYPE_EC,"curve_name"=>"prime256v1"]);$d=openssl_pkey_get_details($k)["ec"];$b=fn($s)=>rtrim(strtr(base64_encode($s),"+/","-_"),"=");echo $b("\x04".$d["x"].$d["y"]),"\n",$b($d["d"]),"\n";'
        //   （1行目 = public_key、2行目 = private_key）。private_key はパスワードと同じ。人に見せない・git に入れない
        //   ※ public_key を変えると、登録済みの端末はすべて無効になる
        'push' => [
            'public_key'  => '',
            'private_key' => '',
            'subject'     => 'mailto:', // 連絡先のメールアドレス（例: mailto:contact@example.com）
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
        // 本番では false（管理者が0人になったとき、次に登録した人が管理者になってしまわないように）
        'first_user_is_admin' => false,
    ],
];

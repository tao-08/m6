<?php
// 取り込み処理の簡易テスト:  php v2/tests/run.php
declare(strict_types=1);

function config($key = null) { return $key === 'pdftotext' ? 'pdftotext' : null; }
require __DIR__ . '/../lib/import/parsers.php';
require __DIR__ . '/../lib/albums.php'; // itunes.php と spotify.php も読み込まれる

$failed = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $failed;
    if ($actual === $expected) {
        echo "  ok   {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n       expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE)
        . "\n       actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "text helpers\n";
check('全角括弧の吸収', band_key('ヨルシカ（安田）'), band_key('ヨルシカ(安田)'));
check('大文字小文字・空白', band_key('KingGnu'), band_key('King Gnu'));
check('代表者付き名称', band_split_suffix('ハンブレッダーズ（郡山）'), ['ハンブレッダーズ', '郡山']);
check('姓名間スペースは1人', split_member_names('岩﨑 太一'), ['岩﨑太一']);
check('中黒区切り', split_member_names('山田太郎・佐藤花子'), ['山田太郎', '佐藤花子']);
check('空白区切りの2人', split_member_names('山田太郎 佐藤花子'), ['山田太郎', '佐藤花子']);
check('未定は無視', split_member_names('未定'), []);
check('異体字', member_key('岩﨑太一'), member_key('岩崎太一'));
$ph = fn(string $t, float $y, float $x0, float $x1) => ['text' => $t, 'yc' => $y, 'x0' => $x0, 'x1' => $x1];
check('PDF の折り返しは詰める', pdf_join_cell([$ph('GENERATION（谷', 10, 100, 160), $ph('ヶ崎）', 20, 100, 120)]), 'GENERATION（谷ヶ崎）');
check('PDF の縦に2人は分ける', pdf_join_cell([$ph('伊藤和奏', 10, 110, 131), $ph('小坂知都乃（Vn.）', 20, 98, 143)]), '伊藤和奏 小坂知都乃（Vn.）');
$wd = fn(string $t, float $x0, float $x1, float $y) => ['text' => $t, 'x0' => $x0, 'x1' => $x1, 'y0' => $y, 'y1' => $y + 5];
$pdfCols = null;
check('PDF で隣の欄にはみ出して接した文字を分ける', pdf_words_to_rows([
    $wd('バンド名', 10, 30, 10), $wd('Ba.', 100, 110, 10), $wd('Dr.', 150, 160, 10),
    $wd('GOING', 5, 15, 30), $wd('UNDER', 16.4, 26, 30), $wd('奥山航太郎', 92, 117, 30), $wd('小豆畑健吾・東哲平・福地龍之介', 117.2, 192, 30),
], $pdfCols), [['バンド名', 'Ba.', 'Dr.'], ['GOING UNDER', '奥山航太郎', '小豆畑健吾・東哲平・福地龍之介']]);
check('PDF の康熙部首（⾧→長）', split_member_names("\u{2FA7}谷川優"), ['長谷川優']);
check('パート見出し', array_map('normalize_part', ['Vo(Gt.)', 'Gt.1', 'Gt2', 'Ba.', 'Dr.', 'Key./その他', '曲数']), ['Vo', 'Gt', 'Gt', 'Ba', 'Dr', 'Key', null]);
check('時刻（1セル）', extract_times('13:30〜14:00'), ['13:30', '14:00']);
check('誤字候補', names_look_similar(member_key('清水啓之介'), member_key('清水啓乃介')), true);
check('苗字だけ', names_look_similar(member_key('皆川'), member_key('皆川桜')), true);
check('別人', names_look_similar(member_key('斉藤豪'), member_key('斉藤弘汰')), false);

echo "timetable\n";
$tt = parse_timetable([
    ['', '', '', 'ライブハウス2日目', '会場', '新宿テスト', ''],
    ['時間', '', '持ち時間', 'バンド名', '曲数', '人数', 'key'],
    ['11:00', '', '', '集合', '', '', ''],
    ['11:30', '11:50', '20', 'バンドA', '3', '4', ''],
    ['11:50', '12:20', '30', '休憩', '', '', ''],
    ['12:20', '12:50', '30', 'バンドB(山田)', '4', '5', '私物(エレピ)'],
]);
check('ライブ名', $tt['live_name'], 'ライブハウス');
check('日程ラベル', $tt['label'], '2日目');
check('会場', $tt['venue'], '新宿テスト');
check('バンド数', count(array_filter($tt['slots'], fn($s) => $s['is_band'])), 2);
check('休憩はバンドではない', $tt['slots'][1]['is_band'], false);
check('終了時刻', $tt['slots'][2]['end_time'], '12:50');

echo "roster\n";
$roster = parse_roster([
    ['バンド名', 'Vo(Gt.)', 'Gt.1', 'Gt.2', 'Ba.', 'Dr.', 'Key.', '曲数', 'マイク', 'Key'],
    ['バンドA', '山田太郎', '', '', '佐藤花子', '鈴木一郎', '', '3', '2', ''],
    ['バンドB（山田）', '山田太郎', '高橋次郎', '', '山田太郎', '鈴木一郎', '田中三郎', '4', '1', '私物'],
    ['バンドB（伊藤）', '伊藤四郎', '', '', '佐藤花子', '鈴木一郎', '', '4', '1', ''],
]);
check('パート列', array_column($roster['columns'], 'part'), ['Vo', 'Gt', 'Gt', 'Ba', 'Dr', 'Key']);
$roster = $roster['bands'];
check('メンバー数', count($roster[1]['members']), 5);
check('入力欄用のセル', $roster[1]['cells'][1], '山田太郎');
check('兼任（Vo と Ba）', array_column(array_filter($roster[1]['members'], fn($m) => $m['name'] === '山田太郎'), 'part'), ['Vo', 'Ba']);
check('照合: 完全一致', match_roster_band(['band_name' => 'バンドA', 'song_count' => 3, 'member_count' => 3], $roster), 0);
check('照合: 括弧の表記ゆれ', match_roster_band(['band_name' => 'バンドB(山田)', 'song_count' => 4, 'member_count' => 5], $roster), 1);
check('照合: 括弧なし→代表者で特定不可', match_roster_band(['band_name' => 'バンドB', 'song_count' => 4, 'member_count' => 5], $roster), null);

echo "excel\n";
// fixtures/timetable.xlsx: 「1日目」「名簿」「メモ」(見出し無し)「隠し」(非表示) の4シート
$sheets = read_table_sheets(__DIR__ . '/fixtures/timetable.xlsx', 'live.xlsx');
check('使うシートだけ読む', array_keys($sheets), ['live.xlsx［1日目］', 'live.xlsx［名簿］']);
$rows = $sheets['live.xlsx［1日目］'];
check('日付セル → 「10月5日」', $rows[0][0], '10月5日');
check('時刻セル（h:mm）', $rows[3][0], '11:30');
check('時刻セル（h時mm分）', $rows[3][1], '11:50');
check('数値セル', $rows[3][2], '20');
check('書式が混ざった文字列', $rows[3][3], 'King Gnu');
check('空セルを詰めない', $rows[1], ['時間', '', '持ち時間', 'バンド名', '曲数', '人数', 'key']);
check('ふりがなは読まない', $sheets['live.xlsx［名簿］'][1][1], '山田太郎');
$tt = parse_timetable($rows);
check('Excel→タイムテーブル', [$tt['month'], $tt['day'], $tt['slots'][0]['start_time'], $tt['slots'][0]['end_time']], [10, 5, '11:30', '11:50']);

echo "itunes\n";
// iTunes の返事（の一部）を真似したデータで、整形処理が正しいかを確かめる（通信はしない）
$sample = [
    'wrapperType' => 'collection', 'collectionId' => 1441164426, 'collectionName' => 'Abbey Road (Remastered)',
    'artistName' => 'The Beatles', 'releaseDate' => '1969-09-26T07:00:00Z',
    'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/thumb/Music/v4/aa/bb/cc/source/100x100bb.jpg',
];
$norm = itunes_normalize_album($sample);
check('iTunesのキー', album_key($norm['source'], $norm['album_id']), 'itunes:1441164426');
check('ジャケットを600x600に', $norm['artwork_url'], 'https://is1-ssl.mzstatic.com/image/thumb/Music/v4/aa/bb/cc/source/600x600bb.jpg');
check('発売年', $norm['release_year'], 1969);
check('曲（アルバム以外）は捨てる', itunes_normalize_album(['wrapperType' => 'track'] + $sample), null);
check('Apple以外の画像URLは拒否', itunes_normalize_album(['artworkUrl100' => 'https://evil.example.com/100x100bb.jpg'] + $sample), null);
check('httpは拒否', itunes_normalize_album(['artworkUrl100' => 'http://is1.mzstatic.com/100x100bb.jpg'] + $sample), null);
check('なりすましドメインは拒否', itunes_normalize_album(['artworkUrl100' => 'https://mzstatic.com.evil.example/100x100bb.jpg'] + $sample), null);

echo "spotify
";
// Spotify の返事（の一部）を真似したデータ（通信はしない）
$sp = [
    'id' => '0ETFjACtuP2ADo6LFhL6HN', 'name' => '魚図鑑', 'release_date' => '2018-03-28',
    'artists' => [['name' => 'サカナクション'], ['name' => 'ゲスト']],
    'images' => [['url' => 'https://i.scdn.co/image/ab67616d0000b273aaaa', 'width' => 640], ['url' => 'https://i.scdn.co/image/small', 'width' => 64]],
];
$spNorm = spotify_normalize_album($sp);
check('Spotifyのキー', album_key($spNorm['source'], $spNorm['album_id']), 'spotify:0ETFjACtuP2ADo6LFhL6HN');
check('一番大きい画像', $spNorm['artwork_url'], 'https://i.scdn.co/image/ab67616d0000b273aaaa');
check('複数アーティストはつなぐ', $spNorm['artist_name'], 'サカナクション, ゲスト');
check('年だけの発売日', spotify_normalize_album(['release_date' => '1969'] + $sp)['release_year'], 1969);
check('Spotify以外の画像URLは拒否', spotify_normalize_album(['images' => [['url' => 'https://evil.example.com/a.jpg']]] + $sp), null);
check('なりすましドメインは拒否', spotify_normalize_album(['images' => [['url' => 'https://i.scdn.co.evil.example/a.jpg']]] + $sp), null);
check('おかしなIDは拒否', spotify_normalize_album(['id' => '../../me'] + $sp), null);
check('画像なしは捨てる', spotify_normalize_album(['images' => []] + $sp), null);

echo "album keys
";
check('Spotifyのキーを分解', album_parse_key('spotify:0ETFjACtuP2ADo6LFhL6HN'), ['spotify', '0ETFjACtuP2ADo6LFhL6HN']);
check('iTunesのキーを分解', album_parse_key('itunes:1441164426'), ['itunes', '1441164426']);
check('知らないサービスは拒否', album_parse_key('evil:1441164426'), null);
check('iTunesのIDに文字は拒否', album_parse_key('itunes:12ab'), null);
check('SpotifyのIDの長さ違いは拒否', album_parse_key('spotify:abc'), null);
check('末尾の改行は拒否', album_parse_key("spotify:0ETFjACtuP2ADo6LFhL6HN
"), null);
check('文字列以外は拒否', album_parse_key(['spotify', 'x']), null);

echo "music apps\n";
$sp = ['source' => 'spotify', 'album_id' => '0ETFjACtuP2ADo6LFhL6HN', 'title' => '魚図鑑', 'artist_name' => 'サカナクション'];
$it = ['source' => 'itunes', 'album_id' => '1358578519', 'title' => 'IRIS OUT - Single', 'artist_name' => '米津玄師'];
check('未設定は登録元のページ', album_listen_url(null, $sp), 'https://open.spotify.com/album/0ETFjACtuP2ADo6LFhL6HN');
check('同じアプリは直接', album_listen_url('apple_music', $it), 'https://music.apple.com/jp/album/1358578519');
check('またぐときはまず album_go', album_listen_url('apple_music', $sp), 'album_go.php?album=spotify%3A0ETFjACtuP2ADo6LFhL6HN');
check('見つかっていたら直接', album_listen_url('apple_music', $sp, 'https://music.apple.com/jp/album/1'), 'https://music.apple.com/jp/album/1');
check('見つからなかったら検索ページ', album_listen_url('spotify', $it, null), 'https://open.spotify.com/search/IRIS%20OUT%20%E7%B1%B3%E6%B4%A5%E7%8E%84%E5%B8%AB');
check('YouTube Music は検索ページ', album_listen_url('youtube_music', $sp), 'https://music.youtube.com/search?q=%E9%AD%9A%E5%9B%B3%E9%91%91%20%E3%82%B5%E3%82%AB%E3%83%8A%E3%82%AF%E3%82%B7%E3%83%A7%E3%83%B3');
check('LINE MUSIC は検索ページ', album_listen_url('line_music', $sp), 'https://music.line.me/webapp/search?query=%E9%AD%9A%E5%9B%B3%E9%91%91%20%E3%82%B5%E3%82%AB%E3%83%8A%E3%82%AF%E3%82%B7%E3%83%A7%E3%83%B3');
check('- Single を除く', album_title_core('IRIS OUT - Single'), 'IRIS OUT');
check('かっこの注記を除く', album_title_core('CAMERA TALK (Remastered 2006)'), 'CAMERA TALK');
check('<> の注記を除く', album_title_core('JP<2016 リマスター>'), 'JP');
check('全部かっこなら元のまま', album_title_core('(What)'), '(What)');
check('大文字小文字と記号の違い', album_match_key("FLIPPER'S GUITAR"), album_match_key("Flipper's Guitar"));
check('全角英数字の違い', album_match_key('ＡＢＣ　１２３'), album_match_key('abc 123'));
check('別の名前は別', album_match_key('くるり') === album_match_key('ゆず'), false);

echo "album matching\n";
// 通信はしない。検索結果・アーティストのアルバム一覧を配列で作って、選び方だけ確かめる
$mk = fn(string $source, string $id, string $title, string $artist, ?int $year) => ['source' => $source, 'album_id' => $id, 'title' => $title, 'artist_name' => $artist, 'release_year' => $year];
check('アルバム名=アーティスト名なら検索語は1回だけ', album_search_query($mk('itunes', '1', 'andymori', 'andymori', 2009)), 'andymori');
check('普通は「アルバム名 アーティスト名」', album_search_query($mk('itunes', '1', 'GAME', 'Perfume', 2008)), 'GAME Perfume');
check('英単語（飾り語は除く）', album_title_words('Touhou Eiyasho - Imperishable Night. SoundTrack'), ['touhou', 'eiyasho', 'imperishable', 'night']);
check('日本語の中の英単語', album_title_words('東方永夜抄 ～ Imperishable Night. サウンドトラック'), ['imperishable', 'night']);

$nee = $mk('spotify', '0ETFjACtuP2ADo6LFhL6HN', 'NEE', 'NEE', 2021);
check('検索語からアーティストを外したときは、別人の同名アルバムを選ばない',
    album_match_in_results($nee, [$mk('itunes', '1444855531', 'Nee - Single', 'DREAMS COME TRUE', 2010)]), null);
$oasis = $mk('itunes', '1', '(What\'s the Story) Morning Glory?', 'オアシス', 1995);
check('アーティストの表記違いでも、アルバム名が同じなら選ぶ',
    album_match_in_results($oasis, [$mk('spotify', '2zw9FrFHDh3IlHKg6osNdo', "(What's The Story) Morning Glory?", 'Oasis', 1995)]), 'https://open.spotify.com/album/2zw9FrFHDh3IlHKg6osNdo');
check('同じ名前が複数なら発売年が同じ版',
    album_match_in_results($oasis, [$mk('spotify', '3UsWuvyuXkospeI9nLdVem', "(What's The Story) Morning Glory (Remastered, Deluxe)", 'オアシス', 2014), $mk('spotify', '2zw9FrFHDh3IlHKg6osNdo', "(What's The Story) Morning Glory?", 'オアシス', 1995)]),
    'https://open.spotify.com/album/2zw9FrFHDh3IlHKg6osNdo');
check('一番上がローマ字表記違いなら選ぶ',
    album_match_in_results($mk('spotify', '1', 'おやすみモンスター', 'GOING UNDER GROUND', 2007), [$mk('itunes', '1512387976', 'Oyasumi Monster', 'GOING UNDER GROUND', 2007)]), 'https://music.apple.com/jp/album/1512387976');
check('同じアーティストでも同じ文字の種類の別名は選ばない',
    album_match_in_results($mk('spotify', '1', 'ハートビート', 'GOING UNDER GROUND', 2001), [$mk('itunes', '9', 'ホーム', 'GOING UNDER GROUND', 2001)]), null);

$touhou = $mk('spotify', '0jaGJ0LfrD2jXvmuJ3RpRH', '東方永夜抄 ～ Imperishable Night. サウンドトラック', '上海アリス幻樂団', 2004);
$catalog = [
    $mk('itunes', '1580546929', 'Hifu Nightmare Diary - Violet Detector. SoundTrack', '上海アリス幻樂団', 2018),
    $mk('itunes', '1581516007', 'Touhou Eiyasho - Imperishable Night. SoundTrack', '上海アリス幻樂団', 2004),
    $mk('itunes', '1581516008', 'Touhou Youyoumu - Perfect Cherry Blossom. SoundTrack', '上海アリス幻樂団', 2003),
];
check('一覧から: 発売年と英単語2つで選ぶ', album_match_in_catalog($touhou, $catalog), 'https://music.apple.com/jp/album/1581516007');
check('一覧から: 発売年が違えば選ばない', album_match_in_catalog(['release_year' => 2005] + $touhou, $catalog), null);
check('一覧から: その年に1枚だけならローマ字表記違いで選ぶ',
    album_match_in_catalog($mk('spotify', '1', '金字塔', '中村一義', 1997), [$mk('itunes', '5', 'Kinjitou', '中村一義', 1997), $mk('itunes', '6', 'Shudaika', '中村一義', 1998)]), 'https://music.apple.com/jp/album/5');
check('一覧から: その年に2枚あればローマ字表記違いでは選ばない',
    album_match_in_catalog($mk('spotify', '1', '金字塔', '中村一義', 1997), [$mk('itunes', '5', 'Kinjitou', '中村一義', 1997), $mk('itunes', '7', 'Ikiru', '中村一義', 1997)]), null);

echo "song titles\n";
// 版違いを同じ曲にまとめる（lib/tracks.php の song_title_key）。
// ほとんどは Spotify / iTunes に実際にある曲名（40アーティスト・約4000曲と、有名曲の検索結果で確かめた）
require_once __DIR__ . '/../lib/tracks.php';
$same = [
    // (2020 Remaster) の書き方いろいろ
    'Let It Be' => ['Let It Be (2020 Remaster)', 'Let It Be (Remastered 2020)', 'Let It Be - 2020 Remaster', 'Let It Be -2020 Remaster-',
        'Let It Be [2020 Remaster]', 'Let It Be【2020 Remaster】', 'Let It Be（2020 リマスター）', 'Let It Be ～2020 Remaster～',
        'Let It Be (2020 Remastered Version)', 'Let It Be (2020 Digital Remaster)', 'Let It Be - Remastered 2020', 'Let It Be 2020 Remaster',
        'Let It Be Remastered 2009', 'Let It Be ‐ 2020 Remaster', 'Let It Be – 2020 Remaster', 'Let It Be / 2020 Remaster',
        'Let It Be - Single Version / 2021 Mix', 'Let It Be (Single Version) [2021 Mix]', 'Let It Be (Take 28)',
        'Let It Be (feat. Paul McCartney) [Live at Shea Stadium, Queens, NY - July 2008]', 'Let It Be (Apple Studio - Remastered)', 'ＬＥＴ ＩＴ ＢＥ'],
    'Lemon' => ['Lemon - Acoustic ver.', 'Lemon (Piano Ver.)', 'Lemon(「アンナチュラル」より) [inst version]', 'Lemon ~ドラマ「アンナチュラル」主題歌~ (オルゴール)',
        'Lemon Originally Performed By 米津玄師(オルゴール)', 'Lemon(カラオケ)[原曲歌手:米津玄師]', 'Lemon -Album ver.-', 'Lemon Piano Version'],
    'Hotel California' => ['Hotel California (Eagles) 1976', 'Hotel California[Eagles] (from Guitar☆Man LIVE #001)', 'Hotel California - Live; 1999 Remaster'],
    'Bohemian Rhapsody' => ['Bohemian Rhapsody - Live At The Montreal Forum / November 1981', 'Bohemian Rhapsody (Operatic Section / 2011 A Cappella Mix)'],
    'Smells Like Teen Spirit' => ['Smells Like Teen Spirit - 1992/Live at Reading'],
    'Love Story' => ['Love Story (Taylor’s Version)', "Love Story (Taylor's Version) - Elvira Remix", 'Love Story - Taylor Swift Cover - Piano Version'],
    'Wonderwall' => ['Wonderwall - Unplugged', "Wonderwall - Live at Knebworth, 10 August '96"],
    'Basket Case' => ['Basket Case - Live at The Point, Dublin, Ireland - March 2003'],
    'Bling-Bang-Bang-Born' => ['Bling - Bang - Bang - Born (Mashle) [Sped Up]', 'Bling-Bang-Bang-Born - Slowed & Reverb', 'Bling-Bang-Bang-Born (マッシュル-MASHLE- OP)'],
    'Paradise Has No Border' => ['Paradise Has No Border -SKY-HI Remix-', 'Paradise Has No Border feat.さかなクン [2020 Remaster]', 'Paradise Has No Border - feat.NO BORDER ALL STARS',
        '「Paradise Has No Border」(キリン氷結)ORIGINAL COVER'],
    'Link' => ['Link -KISS Mix-/- Remastered 2022'],
    'Pretender' => ['Pretender ~映画「コンフィデンスマンJP」主題歌~(オルゴール)', 'Pretender(ONLINE LIVE 2020 - Arena Travelers -) - Live', 'Pretender Instrumental'],
    'ピースサイン' => ['ピースサイン - Peace Sign'],
    'がらくた' => ['がらくた - JUNK'],
    '天体観測' => ['天体観測 - BUMP OF CHICKEN TOUR 2024 Sphery Rendezvous at The Kanazawa Theatre', '天体観測 (2022 Rerecording Version)',
        '天体観測 オリジナルアーティスト: BUMP OF CHICKEN (カラオケ)', '天体観測 Originally Performed By  BUMP OF CHICKEN (アンティークオルゴール)'],
    'ひこうき雲' => ['ひこうき雲 (Instrumental Version) 『風立ちぬ』より', 'ひこうき雲 - 2022 mix'],
    '群青日和' => ['群青日和 - Bon Voyageより', '群青日和 (Dynamite outより)'],
    '愛にできることはまだあるかい' => ['愛にできることはまだあるかい (Movie edit) 映画『天気の子』主題歌(バック演奏編)', '愛にできることはまだあるかい - サンライトLIVE 2 (Cover)',
        '愛にできることはまだあるかい（『天気の子』より） - Piano Echoes Ver.'],
    'シルエット' => ['シルエット-TV SIZE-', 'シルエット New Go-Line ver. - From THE FIRST TAKE'],
    'ないものねだり' => ['ないものねだり +1Key(原曲歌手:KANA-BOON)', 'ないものねだり -5Key(原曲歌手:KANA-BOON)', 'ないものねだり - Revenge THE FIRST TAKE (feat. もっさ)'],
    '道なき道、反骨の。' => ['道なき道、反骨の。feat.Ken Yokoyama'],
    'めくったオレンジ' => ['めくったオレンジ feat.尾崎世界観(クリープハイプ)'],
    'ミュージック・アワー' => ['ミュージック・アワー  Ver.164'],
    'ムーンライトステーション' => ['ムーンライトステーション remixed by Dux Content from London'],
    '君の中で踊りたい' => ['君の中で踊りたい 2023'],
    'いとをかし' => ['いとをかし album ver.'],
    '丸ノ内サディスティック' => ['丸ノ内サディスティック ～Marunouchi Sadistic～ - Miso Remix', '丸ノ内サディスティック (EXPO Ver.)'],
    'チェリー' => ['チェリー（オルゴールver.）', 'チェリー(オリジナルアーティスト:スピッツ)[ガイドメロディ無しカラオケ]', 'チェリー - Cover Ver.'],
    'Highway Star' => ['Highway Star〜エレキギターソロ〜 (Cover)', 'Highway Star (Live in Osaka, Japan, 8/16/1972) - Steven Wilson Remix'],
    'Ame(B)' => ['Ame(B) -SAKANATRIBE × ATM version-'],
];
foreach ($same as $base => $variants) {
    foreach ($variants as $v) {
        check("同じ曲: {$v}", song_title_key($v), song_title_key($base));
    }
}
check('アーティスト名がくっついている', song_title_key('First Love/宇多田ヒカル(オルゴール)', '宇多田ヒカル'), song_title_key('First Love'));
check('アーティスト名がくっついている（スラッシュ）', song_title_key('ハナミズキ/一青窈', '一青窈'), song_title_key('ハナミズキ'));
check('曲名=アーティスト名でも消えない', song_title_key('andymori', 'andymori'), 'andymori');

// 別の曲を同じにしない
$different = [
    ['Bling-Bang-Bang-Born', 'Bling'],                  // 曲名そのものに - がある
    ['Hello - Goodbye', 'Hello'],
    ['Ame(A)', 'Ame(B)'],                               // かっこの中の1〜2文字は曲名の一部
    ['Live Forever', 'Forever'],
    ['1980', '1981'],                                   // 西暦だけの曲名は残る
    // ※ "20/20" と "2020"、"¡Viva la Gloria!" と "¿Viva la Gloria?" のように記号だけが違う別の曲は、見分けられない（限界）
    ['Ex-fan des sixties', 'Ex'],
    ['1/3の純情な感情', '1'],
];
foreach ($different as [$a, $b]) {
    check("別の曲: {$a} ≠ {$b}", song_title_key($a) === song_title_key($b), false);
}
check('西暦だけの曲名はそのまま', song_title_key('1980'), '1980');
check('先頭のかっこは曲名の一部', song_title_key("(I Can't Get No) Satisfaction (Live)"), song_title_key("I Can't Get No Satisfaction"));

echo "track matching\n";
$tr = fn(string $source, string $id, string $title, string $artist) => ['source' => $source, 'track_id' => $id, 'title' => $title, 'artist_name' => $artist];
$letItBe = $tr('spotify', '7iN1s7xHE4ifF5povM6A48', 'Let It Be - Remastered 2009', 'The Beatles');
check('版の注記まで同じものを優先', track_match_in_results($letItBe, [
    $tr('itunes', '1', 'Let It Be (Live)', 'The Beatles'), $tr('itunes', '2', 'Let It Be (Remastered 2009)', 'The Beatles')]), 'https://music.apple.com/jp/song/2');
check('無ければ同じ曲の別の版', track_match_in_results($letItBe, [$tr('itunes', '1', 'Let It Be (Live)', 'The Beatles')]), 'https://music.apple.com/jp/song/1');
check('アーティストが違えば選ばない', track_match_in_results($letItBe, [$tr('itunes', '3', 'Let It Be', 'Glee Cast')]), null);
check('Spotify の複数アーティスト表記', track_match_in_results($tr('itunes', '9', 'Fin (feat. クリープハイプ)', '10-FEET'),
    [$tr('spotify', '4uLU6hMCjMI75M1A2tKUQC', 'Fin', '10-FEET, クリープハイプ')]), 'https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC');
check('またぐときは song_go', track_listen_url('apple_music', $letItBe), 'song_go.php?track=spotify%3A7iN1s7xHE4ifF5povM6A48');
check('同じアプリは直接', track_listen_url('spotify', $letItBe), 'https://open.spotify.com/track/7iN1s7xHE4ifF5povM6A48');

echo "roster choices\n";
require_once __DIR__ . '/../lib/import/planner.php';
$rb = fn(string $name, array $members) => ['band_name' => $name, 'members' => array_map(fn($m) => ['name' => $m[0], 'part' => $m[1]], $members)];
check('同名はボーカルで区別', roster_choices(['rosters' => [['file' => '名簿.pdf', 'bands' => [
    $rb('ELLEGARDEN', [['岩崎太一', 'Vo'], ['山田花子', 'Gt']]),
    $rb('ヨルシカ', [['佐藤一郎', 'Vo']]),
    $rb('ELLEGARDEN', [['鈴木一郎', 'Vo'], ['田中次郎', 'Ba']]),
]]]]), ['ELLEGARDEN（Vo 岩崎太一）' => '0:0', 'ヨルシカ' => '0:1', 'ELLEGARDEN（Vo 鈴木一郎）' => '0:2']);
check('Vo が空なら最初の人', array_keys(roster_choices(['rosters' => [['file' => 'a.pdf', 'bands' => [
    $rb('ENTH', [['高橋', 'Gt'], ['伊藤', 'Dr']]), $rb('ENTH', [['渡辺', 'Vo']]),
]]]])), ['ENTH（高橋）', 'ENTH（Vo 渡辺）']);
$cols = [1 => ['part' => 'Vo'], 2 => ['part' => 'Gt'], 3 => ['part' => 'Gt'], 4 => ['part' => 'Ba'], 5 => ['part' => 'Dr']];
check('ギター2人埋まり・Ba 空ならベースボーカル', guess_vocal_role($cols,
    ['cells' => [1 => '八木毬有', 2 => '木村剛', 3 => '郡山桃子', 4 => '', 5 => '煙山諒芽'], 'extras' => []], '八木毬有', true), 'ba');
check('Ba 空なら人数に関係なくベースボーカル', guess_vocal_role($cols,
    ['cells' => [1 => 'A', 2 => 'B', 3 => '', 4 => '', 5 => 'C'], 'extras' => [], 'member_count' => 4], 'A', true), 'ba');
check('Gt.1 も Ba も空ならベースボーカル（SHANK）', guess_vocal_role($cols,
    ['cells' => [1 => 'A', 2 => '', 3 => 'B', 4 => '', 5 => 'C'], 'extras' => []], 'A', true), 'ba');
check('ギターが全員いない4人編成はギターボーカル', guess_vocal_role($cols + [6 => ['part' => 'Key']],
    ['cells' => [1 => 'A', 2 => '', 3 => '', 4 => 'B', 5 => 'C', 6 => 'D'], 'extras' => [], 'member_count' => 4], 'A', true), 'gt');
check('Ba がいて Gt.1 が空ならギターボーカル', guess_vocal_role($cols,
    ['cells' => [1 => 'A', 2 => '', 3 => 'B', 4 => 'D', 5 => 'C'], 'extras' => []], 'A', true), 'gt');
check('ボーカルも同じならファイル名', array_keys(roster_choices(['rosters' => [
    ['file' => 'a.pdf', 'bands' => [$rb('ENTH', [['渡辺', 'Vo']])]],
    ['file' => 'b.pdf', 'bands' => [$rb('ENTH', [['渡辺', 'Vo']])]],
]])), ['ENTH（Vo 渡辺）（a.pdf）', 'ENTH（Vo 渡辺）（b.pdf）']);

echo "manual timetable\n";
$mplan = ['rosters' => [['file' => 'a.pdf', 'bands' => [
    $rb('ENTH', [['渡辺', 'Vo']]) + ['song_count' => 4, 'member_count' => 3],
    $rb('ENTH', [['高橋', 'Vo']]) + ['song_count' => 5, 'member_count' => 3],
    $rb('SHANK', [['庵原', 'Vo']]) + ['song_count' => null, 'member_count' => 3],
]]]];
[$mt, $refs] = build_manual_timetables($mplan, [
    ['day' => '1', 'pick' => '0:1', 'start' => '13:30', 'end' => '13:50', 'songs' => ''],
    ['day' => '1', 'pick' => '0:0', 'start' => '13:00', 'end' => '13:20', 'songs' => '3'],
    ['day' => '1', 'pick' => 'break:休憩', 'start' => '13:20', 'end' => '', 'songs' => ''],
    ['day' => '2', 'pick' => '0:2', 'start' => '', 'end' => '', 'songs' => ''],
    ['day' => '1', 'pick' => '', 'start' => '15:00', 'end' => '', 'songs' => ''],
]);
check('手入力: 日程の数', array_column($mt, 'label'), ['1日目', '2日目']);
check('手入力: 開始時刻の順', array_column($mt[0]['slots'], 'band_name'), ['ENTH', '休憩', 'ENTH']);
check('手入力: 同じ名前でも選んだ名簿に対応', $refs, ['0:0' => '0:0', '0:2' => '0:1', '1:0' => '0:2']);
check('手入力: 曲数（入力 > 名簿）', array_column($mt[0]['slots'], 'song_count'), [3, null, 5]);
check('手入力: 休憩はバンドではない', $mt[0]['slots'][1]['is_band'], false);
$err = static function (array $rows) use ($mplan): string {
    try {
        build_manual_timetables($mplan, $rows);
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
check('手入力: 同じバンドを2回', $err([['pick' => '0:0'], ['pick' => '0:0']]), '「ENTH（Vo 渡辺）」を2回選んでいます。1つの行だけにしてください');
check('手入力: 終了が開始より前', $err([['pick' => '0:0', 'start' => '14:00', 'end' => '13:00']]), '「ENTH（Vo 渡辺）」の終了時刻は開始時刻より後にしてください');
check('手入力: 名簿に無い値', $err([['pick' => '9:9']]), '1行目: 選んだバンドが名簿にありません');
check('手入力: 休憩だけ', $err([['pick' => 'break:休憩']]), '名簿のバンドを1つ以上選んでください');

echo $failed ? "\n{$failed} 件失敗\n" : "\nすべて成功\n";
exit($failed ? 1 : 0);

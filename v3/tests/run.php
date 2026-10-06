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

echo $failed ? "\n{$failed} 件失敗\n" : "\nすべて成功\n";
exit($failed ? 1 : 0);

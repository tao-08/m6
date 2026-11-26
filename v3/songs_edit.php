<?php
/**
 * =====================================================================
 *  songs_edit.php?band=バンドID — 曲（セットリスト）と、曲ごとの演奏者の編集
 * =====================================================================
 *  1曲 = 1枚のカード。カードの中に「バンドのメンバー全員」が並び、
 *    ☑ チェック … その曲を演奏した
 *    楽器1 / 楽器2 … その曲で弾いた楽器（ギターボーカルなら「Vo/Gt」1つで Vo と Gt の両方になる）
 *  を選ぶ。
 *
 *  曲の追加: 「＋ 曲を追加」で空のカードを出し、曲名を書けば追加。曲名が空のカードは無視される。
 *  曲の削除: 🗑 ボタン（押すとカードが薄くなり、保存したときに消える。もう一度押すと取り消し）。
 *            追加したばかりの（まだ保存していない）カードは、押すとその場で消える。
 *  並び順  : 画面の上から順に 1,2,3... と振る（番号は手で変えない）。
 *  アーティスト: 一番上の「オムニバス」にチェックしたときだけ、曲ごとに書ける。
 *    チェックなし → 全曲バンドのアーティスト（song.artist_id は NULL）。
 *    チェックあり → バンドのコピー元アーティストは外す（band.artist_id = NULL）。曲ごとのアーティストだけになる
 *  曲の紐付け: 🔍で Spotify / iTunes の曲を検索して選ぶ（assets/app_songs.js の setupTrackSearch → api_track_search.php）。
 *    選んだ曲のキー（"spotify:xxxx"）だけが送られてくるので、曲名・ジャケットはサーバーが取り直す。
 *
 *    セッティングシートから来たときは、紐付けていない曲のうち、アーティスト名と曲名が track テーブルと完全一致するものを自動で紐付ける（track に無ければ🔍と同じ検索で完全一致を探す）。
 *
 *  保存の中身は lib/repository.php の save_songs()。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/tracks.php';
require_once __DIR__ . '/lib/youtube.php'; // 概要欄から曲名を読む
require_once __DIR__ . '/lib/gemini_setlist.php'; // YouTube から曲を検出（Gemini の設定があるときだけボタンを出す）
require_once __DIR__ . '/lib/history.php'; // 変更履歴（元に戻すための「変更前の状態」を残す）
require_login();

$pdo = db();
$bandId = (int)($_GET['band'] ?? $_POST['band_id'] ?? 0);
$st = $pdo->prepare('SELECT b.*, a.name AS artist_name, d.live_id, d.label, d.held_on, l.name AS live_name FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id JOIN live l ON l.live_id = d.live_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id WHERE b.band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch();
if (!$band) {
    error_page(404, 'バンドが見つかりません');
}
// 出演まで非公開のセットリストは、メンバーと管理者以外には編集画面でも見せない（曲名が見えてしまうので）
$setlistVis = band_setlist_visibility($pdo, $bandId);
if ($setlistVis['hidden']) {
    http_response_code(403); // 403 = 「あるけど、あなたには見せられない」
    render_header('セットリストを編集', 'lives');
    echo '<div class="empty card"><p class="empty__title">' . icon('lock') . ' セットリストは出演まで非公開です</p>'
        . '<p class="muted small">編集できるのはバンドのメンバーと管理者だけです</p>'
        . '<a class="btn" href="band?id=' . $bandId . '">バンドへ戻る</a></div>';
    render_footer();
    exit;
}
$liveUrl = 'live?id=' . (int)$band['live_id'] . '#day-' . (int)$band['live_day_id'];
$backUrl = 'band?id=' . $bandId; // 保存・キャンセルの戻り先はバンド詳細
// オムニバスでないときにアーティスト欄に出す名前（アーティスト未設定のバンドはバンド名）
//   オムニバスのバンドはコピー元アーティストを持たない（band.artist_id = NULL）ので、バンド名から推測した名前になる
//   （オムニバスを外して保存すると、この名前で find_or_create_artist() する）
$defaultArtist = $band['artist_name'] ?? band_split_suffix($band['name'])[0];

// ---- バンドのメンバー（人ごと）と、バンドでの楽器（初期値に使う） ----
$st = $pdo->prepare('SELECT m.member_id, m.name, bm.instrument_id FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE bm.band_id = ? ORDER BY i.sort_order, m.name');
$st->execute([$bandId]);
$members = []; // member_id => ['name' => ..., 'roles' => [instrument_id, ...]]
foreach ($st as $r) {
    $members[(int)$r['member_id']]['name'] = $r['name'];
    $members[(int)$r['member_id']]['roles'][] = (int)$r['instrument_id'];
}
// 楽器 ID の配列 → 楽器欄の行 [['choice' => ..., 'cho' => bool], ...]。Vo と Gt を両方持っていたら 'vo:gt'（Vo/Gt）の1つにまとめる
//   例: [Vo, Gt] → ['vo:gt']、[Vo, Gt, Key] → ['vo:gt', 'Key の id']、[Gt, Cho] → ['Gt の id'（Cho トグル ON）]
$choicesOf = static fn(array $ids): array =>
    merge_vocal_roles(array_map(static fn(int $id) => ['name' => '', 'instrument_id' => $id], $ids));

/* =====================================================================
 *  保存
 * ===================================================================== */
$errors = [];
// セッティングシート（setting_sheet.php）の「DB に登録する」から来たとき: まだ保存しない。
//   送られてきた曲名を、いつものカードに並べて表示するだけ（曲の紐付け・演奏者を確認してから「保存」してもらう）
$fromSheet = is_post() && ($_POST['from_sheet'] ?? '') === '1';
$sheetTitles = [];
$detectCandidates = []; // 動画から曲を検出したとき、自信が低い曲の候補（from_detect）
$detectShort = false; // 検出した曲がタイムテーブルの曲数より少ない（from_detect）
$detectStarts = [];     // 同じく、曲が始まる秒（null = 不明）
$detectVideo = null;    // 曲の場面（video_start）があるとき、確認しながら流すバンドの YouTube の動画 ID（管理者だけ）
$sheetTracks = []; // 曲名と同じ順の、シートで紐付けたサブスクの曲 [source, track_id]（無い・形がおかしい = null）
if ($fromSheet) {
    verify_csrf();
    $postedTracks = (array)($_POST['tracks'] ?? []);
    foreach ((array)($_POST['titles'] ?? []) as $i => $t) {
        $t = trim((string)$t);
        if ($t !== '') {
            $sheetTitles[] = mb_substr($t, 0, 100); // 100文字を超える分は切る（DB の列の長さ）
            $sheetTracks[] = album_parse_key($postedTracks[$i] ?? null); // ブラウザから来たキーは形を確かめる
        }
    }
    $sheetTitles = array_slice($sheetTitles, 0, 15); // 曲は15曲まで（保存のときと同じ上限）
    $sheetTracks = array_slice($sheetTracks, 0, 15);
}
if (is_post() && !$fromSheet) {
    verify_csrf();
    $omnibus = !empty($_POST['omnibus']);
    $private = !empty($_POST['setlist_private']); // 出演まで非公開
    $songCount = trim((string)($_POST['song_count'] ?? '')); // タイムテーブルの曲数（band_edit と同じ。空欄 = 不明で NULL）
    if ($songCount !== '' && (!ctype_digit($songCount) || (int)$songCount > 255)) {
        $errors[] = '曲数を0〜255の数字で入力してください';
    }
    $songs = [];
    foreach ((array)($_POST['songs'] ?? []) as $k => $in) {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '' || !empty($in['delete'])) {
            continue; // 曲名が空 or 削除チェック → 保存しない（既存の曲なら save_songs が消す）
        }
        if (mb_strlen($title) > 100) {
            $errors[] = "「{$title}」: 曲名は100文字以内にしてください";
            continue;
        }
        // オムニバスでなければ、送られてきたアーティスト欄は見ない（全曲バンドのアーティスト）
        $artist = $omnibus ? trim((string)($in['artist'] ?? '')) : '';
        if (mb_strlen($artist) > 100) {
            $errors[] = "「{$title}」: アーティスト名は100文字以内にしてください";
            continue;
        }
        // 紐付けた曲（空 = 紐付けなし）。形がおかしいキーは受け付けない
        $trackKey = (string)($in['track'] ?? '');
        $track = $trackKey === '' ? null : album_parse_key($trackKey);
        if ($trackKey !== '' && $track === null) {
            $errors[] = "「{$title}」: 紐付けた曲の指定がおかしいです";
            continue;
        }
        $isSe = !empty($in['se']); // SE: 演奏者・アーティストなし（曲の紐付けだけ可）
        if ($isSe) {
            $artist = '';
        }
        $performers = [];
        foreach ($isSe ? [] : $members as $memberId => $_) {
            $p = $in['p'][$memberId] ?? [];
            if (empty($p['on'])) {
                continue; // チェックが付いていない = その曲は弾いていない
            }
            foreach (['i1', 'i2'] as $slot) {
                if (($p[$slot] ?? '') === '') {
                    continue; // 楽器2の「—」
                }
                // 'vo:gt'（Vo/Gt）なら Vo と Gt の2つになる。Cho のトグルは楽器1に付く（Gt/Cho なら Gt と Cho の2つ）
                $cho = $slot === 'i1' && ($p['cho'] ?? '') === '1';
                foreach (instruments_for_choice($p[$slot], $cho) as $inst) {
                    $performers["$memberId-$inst"] = [$memberId, $inst]; // キーにして重複を消す
                }
            }
        }
        $songs[] = [
            'song_id' => ctype_digit((string)($in['id'] ?? '')) ? (int)$in['id'] : null,
            'title' => $title,
            'is_se' => $isSe,
            'artist' => $artist,
            'track' => $track,
            'video_start' => is_int($vs = filter_var($in['vs'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]])) ? $vs : null,
            'k' => (int)$k,
            // 画面の上から何番目か（≡ で並び替えた順。JS が振り直す）。無い・数字でなければ songs[番号] の番号順
            'pos' => is_int($pos = filter_var($in['pos'] ?? null, FILTER_VALIDATE_INT)) ? $pos : (int)$k,
            'performers' => array_values($performers),
        ];
    }
    if (count(array_filter($songs, static fn($s) => !$s['is_se'])) > 15) { // SE は曲数に数えない
        $errors[] = '曲は15曲までです（SE は除く）';
    }
    // まだ track テーブルに無い曲は、Spotify / iTunes から取り直す（ブラウザから来た曲名や画像URLは使わない）。
    //   通信するので、トランザクションの外で先にやっておく（DB をロックしたまま外部の返事を待たないため）
    $newTracks = []; // "source:id" => 曲の情報
    $exists = $pdo->prepare('SELECT 1 FROM track WHERE source = ? AND track_id = ?');
    foreach ($errors ? [] : $songs as $song) {
        if ($song['track'] === null) {
            continue;
        }
        $key = album_key(...$song['track']); // ...$配列 = 配列の中身を引数として順に渡す（source, track_id）
        $exists->execute($song['track']);
        if (isset($newTracks[$key]) || $exists->fetchColumn()) {
            continue;
        }
        $info = track_lookup(...$song['track']);
        if ($info === null) {
            $errors[] = "「{$song['title']}」: 紐付けた曲の情報を取得できませんでした。時間をおいてもう一度試してください";
            continue;
        }
        $newTracks[$key] = $info;
    }
    if (!$errors) {
        // 画面の並び順（≡ で並び替えた順 = pos。同じなら songs[番号] の番号順。JS で足したカードは大きい番号）
        usort($songs, static fn($a, $b) => [$a['pos'], $a['k']] <=> [$b['pos'], $b['k']]);
        $pdo->beginTransaction();
        try {
            $op = change_begin($pdo, 'setlist', '「' . $band['name'] . '」のセットリスト（' . count($songs) . '曲）', (int)$band['live_id']);
            change_capture($pdo, $op, 'band', $bandId); // 変更前のセトリ・メンバーを履歴に残す
            foreach ($songs as &$song) {
                // アーティスト名 → artist_id（無ければ作る）。空欄なら NULL
                //   オムニバスでなければ $song['artist'] は '' なので全曲 NULL（= バンドのアーティスト）
                $song['artist_id'] = $song['artist'] === '' ? null : find_or_create_artist($pdo, $song['artist']);
            }
            unset($song); // foreach の & を切っておく（後で $song を使ったときに最後の曲を書き換えないため）
            foreach ($newTracks as $info) {
                save_track($pdo, $info); // song から外部キーで指すので、先に track に入れる
            }
            // オムニバス: コピー元アーティストを外す（NULL）。バンド名から推測した「ボカロバンド」のような
            //   アーティストが一覧に残らないようにするため（artists.php は演奏0回のアーティストを出さない）
            // オムニバスを外したとき: コピー元アーティストが無ければ、バンド名から決め直す
            $artistId = $omnibus ? null : ($band['artist_id'] ?? find_or_create_artist($pdo, $band['name']));
            $pdo->prepare('UPDATE band SET is_omnibus = ?, artist_id = ?, setlist_private = ?, song_count = ? WHERE band_id = ?')
                ->execute([(int)$omnibus, $artistId, (int)$private, $songCount !== '' ? (int)$songCount : null, $bandId]);
            save_songs($pdo, $bandId, $songs);
            // オムニバスにしたら、外したコピー元アーティストが他で使われていなければ消す
            //   同じ名前の非オムニバスのバンド（band.artist_id）や、曲のアーティストに使っていれば残す。
            //   save_songs の後にやる: 曲のアーティスト欄に同じ名前を書いていたら、その曲が使っているので消さない
            if ($omnibus && $band['artist_id'] !== null) {
                delete_artist_if_unused($pdo, (int)$band['artist_id']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        // 保存のあと、試聴をまだ調べていない曲を最大 3 曲だけ下調べする（イントロクイズの出題に使う）。
        //   トランザクションの外でやる: 通信中に DB のロックを持ち続けないため。残りは運営ツールの一括調査で消化する。
        //   通信に失敗しても保存は成功のまま（例外も握りつぶす）
        try {
            foreach (track_preview_pending($pdo, 3, $bandId) as $pending) {
                track_preview_remember($pdo, $pending);
            }
        } catch (Throwable $e) {
            // 下調べは任せきりでよいので何もしない
        }
        flash('「' . $band['name'] . '」の曲を保存しました（' . count($songs) . '曲）');
        redirect($backUrl);
    }
}

/* =====================================================================
 *  表示用データ
 * ===================================================================== */
$st = $pdo->prepare('SELECT s.song_id, s.track_no, s.title, s.is_se, s.video_start, a.name AS artist_name,
        s.track_source, s.track_id, t.title AS track_title, t.artist_name AS track_artist, t.artwork_url
    FROM song s
    LEFT JOIN artist a ON a.artist_id = s.artist_id
    LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
    WHERE s.band_id = ? ORDER BY s.track_no');
$st->execute([$bandId]);
$songs = $st->fetchAll();

// バンド編集で YouTube のリンクを入れたあと: セトリが空なら、概要欄の曲名をカードに並べる（シートから来たときと同じ。保存はまだしない）
if (!is_post() && ($_GET['from_yt'] ?? '') === '1') {
    $videoId = youtube_video_id((string)$band['youtube_url']);
    $description = $songs || $videoId === null ? null : youtube_description($videoId);
    $sheetTitles = $description === null ? [] : youtube_parse_songs($description);
    if (!$sheetTitles) {
        redirect($backUrl); // 読み取れなかったときは何も言わずバンドに戻る
    }
    $sheetTracks = array_fill(0, count($sheetTitles), null);
    $fromSheet = true;
    $fromYt = true; // 画面を開いたら、曲ごとに候補を自動で探す（data-auto-search）
}

// 「YouTube から曲を検出」（このページの上）が終わったあと: api_setlist_detect がセッションに置いた曲名を、同じようにカードに並べる（保存はまだしない）
if (!is_post() && ($_GET['from_detect'] ?? '') === '1' && is_admin()) {
    $detected = $_SESSION['setlist_detect'][$bandId] ?? [];
    unset($_SESSION['setlist_detect'][$bandId]); // 1回だけ使う
    $sheetTitles = array_column($detected, 'title');
    // 自信が低い曲の候補（曲の順）。自信ありの曲も、念のため試聴できるよう、その曲だけを候補にする
    $detectCandidates = array_map(static fn($s) => $s['candidates'] ?: ($s['title'] !== '' ? [['title' => $s['title'], 'artist' => $s['artist'] ?? '']] : []), $detected);
    $detectStarts = array_map(static fn($s) => $s['start'] ?? null, $detected);
    $detectShort = (int)($band['song_count'] ?? 0) > count($detected); // タイムテーブルの曲数より少ない = 聴き落としかも
    if (!$sheetTitles) {
        redirect($backUrl);
    }
    $sheetTracks = array_fill(0, count($sheetTitles), null);
    $fromSheet = true;
    $fromYt = true;
}

// 曲ごとの演奏者: [song_id][member_id] = [instrument_id, ...]
$st = $pdo->prepare('SELECT sp.song_id, sp.member_id, sp.instrument_id FROM song_performer sp
    JOIN song s ON s.song_id = sp.song_id JOIN instrument i ON i.instrument_id = sp.instrument_id
    WHERE s.band_id = ? ORDER BY i.sort_order');
$st->execute([$bandId]);
$played = [];
foreach ($st as $r) {
    $played[$r['song_id']][$r['member_id']][] = (int)$r['instrument_id'];
}

// 空のカードは最初は出さない。最後に1枚だけ <template>（画面に出ない・送信もされない）の中に作っておき、
// 「＋ 曲を追加」を押したら JS がコピーして並べる（assets/app_songs.js の setupSongs）
$emptyCard = ['song_id' => null, 'track_no' => 0, 'title' => '', 'is_se' => 0, 'video_start' => null, 'artist_name' => null,
    'track_source' => null, 'track_id' => null, 'track_title' => null, 'track_artist' => null, 'artwork_url' => null];
$droppedTitles = []; // セッティングシートに無い、登録済みの曲（保存すると消える）
if ($fromSheet) {
    // シートの曲順でカードを作る。同じ曲名の登録済みの曲があれば、それを使う（紐付けた曲・演奏者の記録を残すため）
    //   曲名の比べ方: 前後の空白を消して、大文字小文字を区別しない（mb_strtolower）
    $norm = static fn(string $t): string => mb_strtolower(trim($t));
    $pool = []; // 曲名 => [登録済みの曲, ...]（同じ曲名を2回やるバンドもあるので配列）
    foreach ($songs as $song) {
        $pool[$norm($song['title'])][] = $song;
    }
    // シートで紐付けた曲の表示用の名前・ジャケット: track テーブルにあればそこから、無ければ Spotify / iTunes に聞く
    //   （ブラウザから来た曲名や画像 URL は使わない。保存のときは今まで通りサーバーが取り直す）
    $trackInfo = static function (array $key) use ($pdo): ?array {
        $st = $pdo->prepare('SELECT title, artist_name, artwork_url FROM track WHERE source = ? AND track_id = ?');
        $st->execute($key);
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
        $t = track_lookup($key[0], $key[1]);
        return $t === null ? null : ['title' => $t['title'], 'artist_name' => $t['artist_name'], 'artwork_url' => $t['artwork_url']];
    };
    $autoSt = $pdo->prepare('SELECT source, track_id, title, artist_name, artwork_url FROM track
        WHERE BINARY title = ? AND BINARY artist_name = ? ORDER BY source DESC, track_id LIMIT 1');
    $cards = [];
    foreach ($sheetTitles as $i => $title) {
        // array_shift: 配列の先頭を取り出す（同じ曲名の2回目には、2つ目の登録済みの曲を使う）
        $match = isset($pool[$norm($title)]) ? array_shift($pool[$norm($title)]) : null;
        $card = $match ?? array_merge($emptyCard, ['title' => $title]);
        // シートで紐付けた曲があれば、それを優先する（登録済みの紐付けと違っても）
        $key = $sheetTracks[$i] ?? null;
        if ($key !== null && [$card['track_source'], $card['track_id']] !== $key && ($info = $trackInfo($key)) !== null) {
            $card = array_merge($card, [
                'track_source' => $key[0], 'track_id' => $key[1],
                'track_title' => $info['title'], 'track_artist' => $info['artist_name'], 'artwork_url' => $info['artwork_url'],
            ]);
        }
        // まだ紐付いていない曲は、アーティスト名と曲名が完全一致する曲が track テーブル（紐付け済みの曲の情報）にあれば自動で紐付ける
        //   アーティスト: オムニバスは曲ごとのアーティスト（無ければ探さない）、それ以外はバンドのアーティスト
        //   BINARY = 大文字小文字・全半角も区別して比べる。複数あれば Spotify を優先（'spotify' > 'itunes'）
        if ($card['track_id'] === null && !$card['is_se'] && $title !== '') { // 曲名が空（動画から曲を検出して、わからなかった曲）は探さない
            $artistForMatch = $band['is_omnibus'] ? ($card['artist_name'] ?? '') : $defaultArtist;
            if ($artistForMatch !== '') {
                $autoSt->execute([$title, $artistForMatch]);
                $hit = $autoSt->fetch() ?: track_find_exact($title, $artistForMatch); // 無ければ🔍と同じ検索（未登録の曲。保存のときに track へ入る）
                if ($hit) {
                    $card = array_merge($card, [
                        'track_source' => $hit['source'], 'track_id' => $hit['track_id'],
                        'track_title' => $hit['title'], 'track_artist' => $hit['artist_name'], 'artwork_url' => $hit['artwork_url'],
                    ]);
                }
            }
        }
        $card['candidates'] = $detectCandidates[$i] ?? [];
        $card['video_start'] = $detectStarts[$i] ?? $card['video_start'];
        $card['track_no'] = $i + 1;
        $cards[] = $card;
    }
    foreach ($pool as $left) {
        foreach ($left as $song) {
            $droppedTitles[] = $song['title'];
        }
    }
} else {
    $cards = $songs;
}
// 曲の場面（video_start）が1曲でもあれば、管理者には動画と ▶・↻ を出す
if (is_admin() && array_filter(array_column($cards, 'video_start'), static fn($v) => $v !== null)) {
    $detectVideo = youtube_video_id((string)$band['youtube_url']);
}
$canRetry = $detectVideo !== null && gemini_setlist_config() !== null; // ↻（1曲だけ検出し直す）
// 最後の1枚は「＋ 曲を追加」用のひな形（<template> の中に入れる）
$cards[] = $emptyCard + ['template' => true];
$cards[] = array_merge($emptyCard, ['template' => true, 'is_se' => 1, 'template_se' => true]); // 「＋ SE を追加」用
// 出演まで非公開: 保存済みの値。シートから来たときは、まだ公開時刻前なら最初からチェックを入れておく
$private = $fromSheet
    ? ($setlistVis['private'] || ($setlistVis['open_at'] !== null && strtotime($setlistVis['open_at']) > time()))
    : $setlistVis['private'];
if (is_post() && !$fromSheet) {
    $private = !empty($_POST['setlist_private']); // 保存に失敗して画面を出し直すときは、送られてきた値のまま
}
$songCountValue = is_post() && !$fromSheet ? (string)($_POST['song_count'] ?? '') : (string)($band['song_count'] ?? '');
$omnibus = (bool)$band['is_omnibus'];
// 「出演まで非公開」のチェックは、ライブの日が今日か、これから（または日付未定）のときだけ出す。
//   終わったライブを今から非公開にする意味はないので。held_on は 'YYYY-MM-DD' の文字列なので >= でそのまま日付を比べられる
$canPrivate = $band['held_on'] === null || $band['held_on'] >= date('Y-m-d');
$artistNames = $pdo->query('SELECT name FROM artist ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

// アイコン（Material Symbols のアイコン名。lib/bootstrap.php の icon() で出す）
const ICON_TRASH = 'delete';
const ICON_UNLINK = 'link_off';

render_header('セットリストを編集', 'lives');
?>
<nav class="crumbs"><a href="<?= h($liveUrl) ?>"><?= h($band['live_name']) ?> <?= h($band['label']) ?></a><span>/</span><a href="<?= h($backUrl) ?>"><?= h($band['name']) ?></a></nav>
<h1 class="display display--sm"><?= h($band['name']) ?> のセットリスト</h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<?php if (!$members): ?>
    <div class="flash flash--warn">先にバンドのメンバーを登録してください（<a href="band_edit?id=<?= $bandId ?>">バンドを編集</a>）</div>
<?php endif; ?>
<p class="muted small">曲ごとに演奏者と担当楽器が記録できます。 曲を検索して紐づけると対応アプリで開くリンクが作成されます。</p>
<?php /* 一時: 登録済みの YouTube リンクから取り込み直す入口。曲名の羅列に対応した後の取りこぼし分を入れ終えたら、このブロックごと消す */ ?>
<?php if (!$songs && !$fromSheet && youtube_video_id((string)$band['youtube_url']) !== null): ?>
    <p><a class="btn btn--ghost btn--sm" href="songs_edit?band=<?= $bandId ?>&amp;from_yt=1">YouTube の概要欄から取り込む</a></p>
<?php endif; ?>
<?php if (!$fromSheet && is_admin() && gemini_setlist_config() !== null && youtube_video_id((string)$band['youtube_url']) !== null): // 送るたびに Gemini の料金がかかるので管理者だけ ?>
    <!-- 押すと assets/setlist_detect.js が api_setlist_detect に送る（サーバーが裏でバンドの YouTube から音声を落として Gemini に聴かせる）。終わったらこのページの確認画面（from_detect=1）へ -->
    <!-- data-pending: 裏で解析中（または終わったが、まだ見ていない）。開いたらすぐ途中経過を見に行く -->
    <div data-setlist-detect data-band="<?= $bandId ?>" data-done-url="songs_edit?band=<?= $bandId ?>&amp;from_detect=1"<?= setlist_job_get($bandId) !== null ? ' data-pending' : '' ?>>
        <!-- 低速 = Gemini の Flex（半額。返事まで最大15分） -->
        <p class="row-form"><?= icon('smart_display') ?> YouTube から曲を検出
            <button type="button" class="btn btn--ghost btn--sm" data-flex="0"><?= icon('bolt') ?> 高速</button>
            <button type="button" class="btn btn--ghost btn--sm" data-flex="1"><?= icon('timer') ?> 低速・半額</button></p>
        <p class="muted small" data-setlist-status role="status" hidden></p>
    </div>
    <script type="module" src="<?= asset_url('assets/setlist_detect.js') ?>"></script>
<?php endif; ?>
<?php if ($fromSheet): ?>
    <div class="flash flash--info"><?= icon('description') ?> データベースにはまだ保存されていません。<br><?= icon('search') ?> を押して楽曲情報を登録し、演奏者と担当楽器を確認したら保存してください。</div>
    <?php if ($detectShort): ?>
        <div class="flash flash--warn">曲数は<?= (int)$band['song_count'] ?>曲ですが、検出できたのは<?= count($sheetTitles) ?>曲です</div>
    <?php endif; ?>
    <?php if ($droppedTitles): ?>
        <div class="flash flash--warn">シートに無い登録済みの曲は、保存すると消えます: <?= h(implode('、', $droppedTitles)) ?></div>
    <?php endif; ?>
<?php endif; ?>

<form method="post" class="songs-form" data-tip-lazy<?= unsaved_attr() ?>><?php // data-tip-lazy: AI のエラーの ⓘ をあとから足すので、ポップアップを準備しておく ?>
    <?= csrf_field() ?>
    <input type="hidden" name="band_id" value="<?= $bandId ?>">
    <!-- チェックなし: アーティスト欄はバンドのアーティストで固定（薄く表示）。チェックあり: 曲ごとに書ける（assets/app_songs.js の setupSongs） -->
    <label class="check song-omnibus"><input type="checkbox" name="omnibus" value="1"<?= $omnibus ? ' checked' : '' ?> data-omnibus> オムニバス （複数アーティストを演奏）</label>
    <!-- 出演まで非公開: 終演30分後まで、メンバーと管理者以外には曲名を見せない（lib/repository.php の setlist_hidden_sql） -->
    <!-- スマホでは「（終演30分後に公開…）」を2行目に（assets/app.css の .song-private__note）。過去のライブには出さない（$canPrivate） -->
    <?php if ($canPrivate): ?>
    <label class="check song-omnibus song-private"><input type="checkbox" name="setlist_private" value="1"<?= $private ? ' checked' : '' ?>> <?= icon('lock') ?> 出演まで非公開 <span class="muted small song-private__note">（終演30分後に公開<?= $setlistVis['open_at'] ? '：' . h(fmt_datetime($setlistVis['open_at'])) : '' ?>）</span></label>
    <?php endif; ?>
    <label class="field song-count"><span>曲数</span><input type="number" class="input-num" min="0" max="255" name="song_count" value="<?= h($songCountValue) ?>"></label>
    <button type="button" class="btn btn--ghost btn--sm song-add-se" data-add-song-se>＋ SE を追加</button>
    <?php if ($detectVideo !== null): ?>
    <!-- 曲の場面があるとき: バンドの動画を上に残し、曲ごとの ▶（data-seek）でその場面へ飛ぶ（assets/app_songs.js の setupDetectPlayer） -->
    <div class="detect-player" data-detect-player data-band="<?= $bandId ?>"><iframe src="https://www.youtube.com/embed/<?= h($detectVideo) ?>?enablejsapi=1&amp;playsinline=1&amp;rel=0" title="<?= h($band['name']) ?>" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>
    <?php endif; ?>
    <datalist id="artists"><?php foreach ($artistNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>
    <div class="song-list" data-song-list<?= !empty($fromYt) ? ' data-auto-search' : '' ?> data-default-artist="<?= h($defaultArtist) ?>">
        <?php foreach ($cards as $k => $song):
            $isNew = $song['song_id'] === null;        // まだ DB に無い曲（シートから来た曲も含む）
            $isTemplate = !empty($song['template']);     // 「＋ 曲を追加」「＋ SE を追加」用のひな形（画面には出ない）
            $isSe = !empty($song['is_se']);              // SE（演奏者・アーティスト欄なし）
            $base = "songs[$k]";
            $artist = $omnibus ? ($song['artist_name'] ?? '') : $defaultArtist; // オムニバスはバンドのアーティストが無いので、曲に付いていなければ空欄
            $trackKey = $song['track_id'] === null ? '' : album_key($song['track_source'], $song['track_id']); ?>
            <?php if ($isTemplate): ?><template <?= $isSe ? 'data-song-template-se' : 'data-song-template' ?>><?php endif; ?>
            <!-- data-unsaved-blank: 曲名が空の新しい曲は保存されないので、未保存の警告の対象にしない（assets/app_form.js の setupUnsavedGuard） -->
            <!-- data-candidates: 動画から曲を検出して自信が低かった曲の候補 [{title, artist}]。🔍 の候補欄に並べる（assets/app_songs.js の setupTrackSearch） -->
            <section class="card song-card<?= $isNew ? ' song-card--new' : '' ?><?= $isSe ? ' song-card--se' : '' ?>" data-song-card<?= $isNew ? ' data-unsaved-blank' : '' ?><?= empty($song['candidates']) ? '' : ' data-candidates="' . h(json_encode($song['candidates'], JSON_UNESCAPED_UNICODE)) . '"' ?>>
                <div class="song-card__head">
                    <!-- ≡: ドラッグ（↑↓キーでも）で曲の順番を入れ替える。pos = 上から何番目か（JS が振り直す。保存はこの順） -->
                    <button type="button" class="drag-handle" data-song-handle aria-label="ドラッグで並び替え（↑↓キーでも動く）" title="ドラッグで並び替え"><?= icon('drag_indicator') ?></button>
                    <input type="hidden" name="<?= $base ?>[pos]" value="<?= (int)$k ?>" data-song-pos>
                    <input type="hidden" name="<?= $base ?>[se]" value="<?= $isSe ? '1' : '' ?>">
                    <?php if ($isSe): ?><span class="song-card__no" title="SE"><span class="song-card__se-sp" aria-hidden="true">0</span><span class="song-card__se">SE</span></span><?php else: ?><span class="song-card__no"><span data-song-no><?= (int)$song['track_no'] ?></span>曲目</span><?php endif; ?>
                    <!-- ジャケット: 紐付けた曲の画像。紐付けていなければ ♪。
                         紐付けているときは、マウスを乗せると「リンクが切れるマーク」が重なり、押すと紐付けを外す -->
                    <span class="song-thumb" title="<?= $trackKey === '' ? '' : h($song['track_title'] . ' / ' . $song['track_artist']) ?>">
                        <span class="song-thumb__art" data-track-thumb><?php if ($trackKey !== ''): ?><img src="<?= h($song['artwork_url']) ?>" alt="" loading="lazy"><?php else: ?><?= icon('music_note') ?><?php endif; ?></span>
                        <button type="button" class="song-thumb__clear" data-track-clear aria-label="紐付けを外す"<?= $trackKey === '' ? ' hidden' : '' ?>><?= icon(ICON_UNLINK) ?></button>
                    </span>
                    <input autocomplete="off" name="<?= $base ?>[title]" value="<?= h($song['title']) ?>" maxlength="100" placeholder="<?= $isNew ? ($isSe ? 'SE名を入力して追加' : '曲名を入力して追加') : ($isSe ? 'SE名' : '曲名') ?>" class="song-card__title" aria-label="曲名" data-unsaved-key>
                    <?php if ($isSe): ?><input type="hidden" name="<?= $base ?>[artist]" value="">
                    <?php else: ?><input name="<?= $base ?>[artist]" value="<?= h($artist) ?>" maxlength="100" data-suggest-list="artists" autocomplete="off" placeholder="アーティスト" class="song-card__artist" aria-label="アーティスト" data-song-artist<?= $omnibus ? '' : ' readonly' ?>><?php endif; ?>
                    <!-- data-artist: 紐付けた曲のアーティスト（先頭の1人）。「オムニバス」にチェックを付けたとき、連携済みの曲はこの名前をアーティスト欄に残す（assets/app_songs.js の setupSongs） -->
                    <input type="hidden" name="<?= $base ?>[track]" value="<?= h($trackKey) ?>" data-track-key data-artist="<?= h($trackKey === '' ? '' : explode(', ', (string)$song['track_artist'])[0]) ?>">
                    <!-- data-video-start: バンドの YouTube で始まる秒（空 = わからない）。保存で残す。↻ の区間は assets/app_songs.js の renumber が前後の曲から決める -->
                    <input type="hidden" name="<?= $base ?>[vs]" value="<?= $song['video_start'] ?? '' ?>" data-video-start>
                    <?php if ($detectVideo !== null && $song['video_start'] !== null): $vs = (int)$song['video_start']; ?><button type="button" class="btn btn--ghost btn--sm song-seek-btn" data-seek="<?= $vs ?>" aria-label="動画のこの曲の場面を再生" title="動画のこの曲の場面を再生"><?= icon('smart_display') ?><?= sprintf('%d:%02d', intdiv($vs, 60), $vs % 60) ?></button><?php endif; ?>
                    <?php if ($canRetry): ?><button type="button" class="btn btn--ghost btn--sm" data-detect-retry hidden aria-label="この曲をもう一度検出" title="この曲をもう一度検出"><?= icon('refresh') ?></button><?php endif; ?>
                    <button type="button" class="btn btn--ghost btn--sm song-search-btn" data-track-search aria-label="曲を探す" title="曲を探す"><?= icon('search') ?></button>
                    <input type="hidden" name="<?= $base ?>[id]" value="<?= $isNew ? '' : (int)$song['song_id'] ?>">
                    <!-- 🗑 保存済みの曲: 押すと「削除する」印（隠し項目を 1）が付いてカードが薄くなる。もう一度押すと取り消し。消えるのは保存したとき
                         追加したばかりの曲: まだ保存していないので、押すとカードごとその場で消える -->
                    <?php if (!$isNew): ?><input type="hidden" name="<?= $base ?>[delete]" value="" data-song-delete><?php endif; ?>
                    <button type="button" class="song-card__delete" data-song-delete-btn aria-label="この曲を削除" title="この曲を削除"><?= icon(ICON_TRASH) ?></button>
                    <?php if (!$isSe && is_admin()): ?>
                    <div class="song-lyrics-search">
                        <input type="search" maxlength="100" autocomplete="off" placeholder="歌詞の一部を入力" aria-label="歌詞の一部" data-lyrics-query>
                        <button type="button" class="btn btn--ghost btn--sm" data-lyrics-search>歌詞で検索</button>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- 🔍 の検索結果（assets/app_songs.js の setupTrackSearch が中身を入れる） -->
                <div class="track-results" data-track-results hidden></div>
                <?php if (!$isSe && is_admin()): ?>
                <div class="track-results lyrics-results" data-lyrics-results hidden></div>
                <?php endif; ?>
                <div class="performers"<?= $isSe ? ' hidden' : '' ?>>
                    <?php foreach ($isSe ? [] : $members as $memberId => $m):
                        // 既存の曲: 記録どおり / 新しい曲: バンドの担当を初期値にして全員ON
                        $insts = $isNew ? $m['roles'] : ($played[$song['song_id']][$memberId] ?? []);
                        $on = $isNew || $insts !== [];
                        $choices = $choicesOf($insts ?: $m['roles']); ?>
                        <div class="performer<?= $on ? '' : ' is-off' ?>">
                            <label class="check performer__name"><input type="checkbox" name="<?= $base ?>[p][<?= $memberId ?>][on]" value="1"<?= $on ? ' checked' : '' ?> data-performer-on> <?= h($m['name']) ?></label>
                            <?= chorus_toggle("{$base}[p][{$memberId}][cho]", $choices[0]['cho'] ?? false, $choices[0]['choice'] ?? '') ?>
                            <?php $hasSecond = isset($choices[1]); // 2つ目の楽器がある人は、最初から2段で出す ?>
                            <!-- 楽器1は横いっぱい。右端の ＋ で下に楽器2を出す（3つ目以降は無し。JS: setupSongs の data-add-inst） -->
                            <div class="performer__inst">
                                <select name="<?= $base ?>[p][<?= $memberId ?>][i1]" class="select-sm" aria-label="楽器1"><?= instrument_choice_options($choices[0]['choice'] ?? '') ?></select>
                                <button type="button" class="performer__add" data-add-inst aria-label="楽器を追加" title="楽器を追加"<?= $hasSecond ? ' hidden' : '' ?>><?= icon('add') ?></button>
                            </div>
                            <!-- 楽器2: 隠れていても送信される。値が「—」（空）なら保存されない -->
                            <div class="performer__inst" data-inst2<?= $hasSecond ? '' : ' hidden' ?>>
                                <select name="<?= $base ?>[p][<?= $memberId ?>][i2]" class="select-sm" aria-label="楽器2"><option value="">—</option><?= instrument_choice_options($choices[1]['choice'] ?? '') ?></select>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php if ($isTemplate): ?></template><?php endif; ?>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn--ghost btn--sm" data-add-song>＋ 曲を追加</button>

    <!-- 保存前の操作を戻す / やり直す（assets/app_songs.js の setupSongs）。見た目はタイムテーブル作成と同じ左下の浮きボタン -->
    <div class="ttb-float">
        <button type="button" class="btn btn--sm" data-song-undo aria-label="元に戻す" title="元に戻す" disabled><?= icon('undo') ?></button>
        <button type="button" class="btn btn--sm" data-song-redo aria-label="やり直す" title="やり直す" disabled><?= icon('redo') ?></button>
    </div>

    <div class="sticky-actions">
        <a class="btn btn--ghost" href="<?= h($backUrl) ?>">キャンセル</a>
        <button class="btn btn--primary" type="submit">保存する</button>
    </div>
</form>
<?php render_footer();

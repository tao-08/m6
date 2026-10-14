<?php
/**
 * =====================================================================
 *  lib/setting_sheet.php — セッティングシート（ステージプロット）の「型紙」の設定
 * =====================================================================
 *  ライブハウスごとに配られる PDF（セッティングシート）は、どれも
 *    「バンド名を書く線」「メンバー表」「セット図の枠」「セットリストの表」
 *  がある、という点は同じで、違うのは「それがページのどこにあるか（座標）」だけ。
 *
 *  そこで「会場ごとの違い＝座標」だけをこのファイルに集めて、
 *  書き込む処理（assets/setting_sheet.js）は全会場で共通にしている。
 *  → 新しい会場を増やすときは、PDF を assets/sheets/ に置いて、
 *    下の SHEET_TEMPLATES に1ブロック足すだけ（JS は触らなくていい）。
 *
 *  業界では「データ（設定）とロジック（処理）を分ける」と言う。
 *  帳票（請求書・申込書など）を作るシステムはだいたいこの作り。
 *
 *  ---- 座標のルール（全部この単位で書く）----
 *    単位      : pt（ポイント）。1pt = 1/72 インチ。A4 は 595 × 842 pt。PDF の世界の標準単位
 *    原点      : ページの「左上」。x は右へ、y は「下へ」増える
 *               （PDF 本来の原点は左下で y は上へ増えるが、人が測りやすいように上から測る。
 *                 JS の側で「ページの高さ − y」をして PDF の座標に直している）
 *    箱 [x0, y0, x1, y1] : 左上 (x0, y0) と 右下 (x1, y1) の2点で四角を表す
 *
 *  座標の測り方: Python の pdfplumber で線や文字の位置を出すのが早い
 *      import pdfplumber
 *      pg = pdfplumber.open('xxx.pdf').pages[0]
 *      for w in pg.extract_words(): print(w['x0'], w['top'], w['x1'], w['bottom'], w['text'])
 *      for r in pg.rects: print(r['x0'], r['top'], r['x1'], r['bottom'])   # 表の罫線
 * =====================================================================
 */
declare(strict_types=1);

/**
 * 型紙の一覧。キー（'club_voice'）は URL や保存データで使う ID なので、あとから変えないこと。
 *
 * 各項目の意味:
 *   label        : 画面のプルダウンに出す名前
 *   venue_words  : 会場名（venue.name）にこの文字のどれかが含まれていたら、この型紙を自動で選ぶ
 *                  （画面から追加した型紙は venue_words ではなく venues = つないだ会場の名前。sheet_template_venue）
 *   pdf          : 元の PDF（この上に書き込む）
 *   stage_image  : セット図の枠の中を切り抜いた画像（画面のドラッグ編集の背景に使うだけ。PDF には使わない）
 *   fields       : 1行の文字を書く場所（箱）。align = left / center
 *                  キー: band / kana / date / order / rep（代表者氏名）/ count（出演人数。メンバー表の名前の数から自動）/ year・month・day（年・月が印刷済みのシート用。出演日から数字だけ書く）
 *                  kana の label: 作成画面の欄の名前（省略 = フリガナ。シートが「ふりがな」なら 'ふりがな'）
 *   members     : メンバー表（無いシートは省く。メンバーの入力はセット図の自動配置・出演人数に使う）。1行目の上端・行の高さ・行数と、パート列・氏名列の左右
 *   stage        : セット図の枠（この中にマイクや箱を描く）
 *   stage_auto   : 自動配置で部品を並べる範囲（stage の内側。無ければ stage 全体。型紙エディタの「＋ 自動配置の枠」）
 *   setlist      : セットリストの表。1行目の上端・行の高さ・行数と、列ごとの左右
 *                  列: no（曲順。印刷済みなら書かない）/ title（曲名）/ sound（音響）・light（照明）
 *                      音響と照明が1つの「備考」欄なら、sound・light の代わりに request（「音響 / 照明：…」とつないで書く）
 *                      time（分数。作成画面でサブスクの曲を紐付けると曲の長さが入る）/ time_colon => true:「:」が印刷済み（分と秒を左右に書く）
 *                      cols => [['key' => 'c1', 'label' => 'コーラス/CD', 'col' => [左, 右]], ...]: 曲ごとの追加の列（label が入力欄の名前）
 *                      time と cols のうち左右がそろった列（1pt 以内）は、行を上下に等分して上から順に積む（分数はいちばん下）
 *   extras_title : 「その他の項目」の見出しを変えるとき（無ければ「その他の項目」）
 *   headings     : 見出し [['key' => 'h1', 'label' => 'SE'], ...]。extras_order の 'h:h1' の所から、作成画面では別のカードにする
 *   extras_order : その他の項目の並び。['t:欄のキー', 'c:グループ', 'h:見出し', ...]（入れ子の子も含めて上から。無いものは 自由記入欄 → グループ の順で後ろに）
 *                  入れ子は show_if（グループ）/ with_choice + show_if（自由記入欄）で、親のグループのすぐ下に出す（型紙エディタの ☰ の一覧で並べ替える）
 *   show_if      : [グループ => [見るグループ, [この選択肢のときだけ見せる]]]。隠れている項目は PDF にも書かない
 *   choice_labels: [グループ => 見出し]。型紙ごとに見出しを変えるとき（無ければ SHEET_CHOICE_LABELS → キーそのまま）
 *   choices      : ○で囲む選択肢（SE 有/なし など）。選ばれたものの文字の箱を楕円で囲む
 *                  箱の代わりに ['box' => [...], 'mark' => 'check'] と書くと、○ではなく ✓ を書く（チェック欄用）
 *                  'mark' => 'none' は印なし。'text' => '（2Tom）' で、選んだとき選択肢の箱の中に文字も書く（前の版の 'text_box' は使わない）
 *   stamps       : いつも書く文字（入力欄なし）。[['text' => '〇〇大学 軽音', 'box' => [...], 'size' => 10, 'align' => 'left'], ...]
 *                  'shape' => 'rect' | 'ellipse' で box の大きさの四角・丸で囲む（'line' は線の太さ）。text が空なら図形だけ書く
 *   texts        : そのほかの自由記入欄（その他の要望・SE の曲名 など）。auto => 'mic' は「空欄ならマイク〇の数」
 *                  with_choice => グループ: そのグループの下に出す / show_if => [グループ, [選択肢]]: その選択のときだけ出す
 *                  digits => true: 数字だけ / placeholder: 入力欄の薄い説明 / pad: PDF の箱の左右の余白（狭い箱用）
 *   drum         : 印刷済みのドラムの箱（自動配置でアンプを避ける）
 *   gt_amps      : 備え付けギターアンプ（無ければ JC, Marshall）。作成画面の四角の候補（+ Ba Amp / Key / E.Pf）と、
 *                  自動配置のギターアンプの名前（並んでいる順に、ドラムに近い方から。足りない分は Gt Amp）
 *   （setlist の no / sound・light / request は任意。無いシートには書かない。setlist / stage は必須（members は任意）で、型紙エディタも無いと保存しない）
 *   amps_printed : true ならセット図のアンプが印刷済み。自動配置でギター・ベースのアンプの四角を置かない（型紙エディタの詳細設定）
 *   stage_scale  : セット図の部品の基準の大きさの倍率（枠が大きいシートは大きくする）
 *   text_scale   : 欄に書く文字の、いちばん大きいサイズの倍率（欄に入りきらなければ自動で小さくなる）
 *   font_scale   : メンバー表・セットリスト以外の欄（fields / texts / stamps / 選択肢の文字）だけの文字の倍率（型紙エディタで変える）
 *
 *   members の書き方は2種類:
 *     mode なし（list）: 「パート」「氏名」を1行ずつ自由に書く表（Club Voice）
 *                        part2 / name2 も書くと、右にもう1組ある表（パート・氏名・パート・氏名の4列）。左の組が埋まったら右の組に書く
 *                        fixed => ['0' => 'Vo', '4' => 'Dr']: 一部の行だけパートが印刷済み（行の番号は 0 から。2組なら左の組の続きの番号）。
 *                          そのパートの人をその行に入れて、パートは書かない。パートが違う人が入ったら「名前（Vo/Gt）」
 *                        入りきらない人は、印刷済みの行に同じパートで足し、それでも多ければ同じパートの人を1行にまとめる（「・」でつなぐ）
 *     mode => 'slots' : パートが印刷済み（Vo / Gt.1 / Ba …）。メンバーをパートに合わせて振り分ける（サークル共通）
 *   setlist は top + row_h（全部同じ高さ）か、lines（行の境目の y を全部並べる。高さがバラバラな表用）のどちらか
 */
// 初期の2つ（club_voice・club_common）は migrations/037_sheet_template_builtin.sql で DB に移した。
// 今は型紙は全部 DB（sheet_templates.php から登録・編集）。上の説明は DB の config の JSON の形としてそのまま使える
const SHEET_TEMPLATES = [];

/** choices のグループ名を画面に出すときの見出し */
const SHEET_CHOICE_LABELS = [
    'se'        => 'SEの有無',
    'se_src'    => 'SEの再生',
    'start'     => '流し始め',
    'end'       => '流し終わり',
    'tom'       => 'タム',
    'sync'      => '同期使用',
    'key_bring' => 'キーボード',
    'key_type'  => '鍵盤の種類',
    'se_end'    => 'SEの終わり',
    'mix'       => 'ミックス希望',
    'live_feel' => 'ライブ感',
];

/**
 * 型紙を全部返す = コードの SHEET_TEMPLATES ＋ 画面から追加した DB の型紙（sheet_template）。
 *   DB の型紙のキーは 'db_' . template_id（コードのキーとぶつからないように）。
 *   config の JSON に label / venue_words / pdf を足して、SHEET_TEMPLATES と同じ形にそろえる
 *   → JS（assets/setting_sheet.js）はどちらから来た型紙か気にしなくていい
 */
function all_sheet_templates(): array
{
    static $all = null; // 1回のリクエストで何度呼ばれても、DB を読むのは1回だけ
    if ($all !== null) {
        return $all;
    }
    $all = SHEET_TEMPLATES;
    try {
        $rows = db()->query('SELECT template_id, label, pdf_path, config
                               FROM sheet_template ORDER BY label')->fetchAll();
        // 型紙ごとにつながっている会場の名前（今の名前。会場の名前を変えても venue_id でつながったまま）
        $venueNames = [];
        foreach (db()->query('SELECT tv.template_id, v.name FROM sheet_template_venue tv
                                JOIN venue v ON v.venue_id = tv.venue_id ORDER BY v.sort_order, v.name') as $v) {
            $venueNames[$v['template_id']][] = $v['name'];
        }
    } catch (PDOException $e) {
        $rows = []; // sheet_template がまだ無い（migrations/031・033 を流していない）DB でも止まらないように
    }
    foreach ($rows as $r) {
        $config = json_decode($r['config'], true);
        if (!is_array($config)) {
            continue; // 壊れた JSON の型紙は出さない（1つのせいでページ全体が止まらないように）
        }
        $all['db_' . $r['template_id']] = [
            'label'       => $r['label'],
            'venue_words' => [],                                  // 一部が含まれていたら…はコードの型紙だけ
            'venues'      => $venueNames[$r['template_id']] ?? [], // 会場名がぴったり同じなら選ぶ
            'pdf'         => $r['pdf_path'],
        ] + $config; // + : 左に無いキーだけ右から足す（config に label などが紛れていても DB の列が勝つ）
    }
    return $all;
}

/**
 * 会場名から型紙のキーを選ぶ。見つからなければ null。
 *   str_contains($haystack, $needle): $haystack の中に $needle が含まれていれば true（PHP 8 から）
 */
function sheet_template_for_venue(?string $venueName): ?string
{
    if ($venueName === null || $venueName === '') {
        return null;
    }
    // 画面から会場を選んでつないだ型紙（ぴったり同じ名前）を先に見る。「ROKS」のような一部一致のコードの型紙より優先
    foreach (all_sheet_templates() as $key => $tpl) {
        if (in_array($venueName, $tpl['venues'] ?? [], true)) {
            return $key;
        }
    }
    foreach (all_sheet_templates() as $key => $tpl) {
        foreach ($tpl['venue_words'] as $word) {
            if (str_contains($venueName, $word)) {
                return $key;
            }
        }
    }
    return null;
}

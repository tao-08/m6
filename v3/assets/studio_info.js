/* =====================================================================
 *  assets/studio_info.js — スタジオ情報（studio_list.php / studio_info.php）の動き
 * =====================================================================
 *  1. 地図（#studio-map）… Leaflet で OpenStreetMap を出し、#studio-pins の JSON からピンを立てる
 *  2. 座標の取得（[data-geo-lookup]）… 住所 → 緯度・経度（国土地理院の住所検索 API）
 *  3. 料金シミュレーション（#studio-sim）… 入力が変わるたびに合計・1人あたり・内訳・パック・+1時間延長を計算して表示
 *  4. ✨AI（#studio-ai）… タブ「AIで検索」（ウェブ検索・画像で調べる）／「AIで編集」（今のデータを指示どおりに書き換える）。
 *       どちらも AI の提案をプレビュー（編集は今との差を 旧 → 新 で出す）して、反映する項目を選ぶ
 *  5. ＋／－ボタン（[data-step]）… 数字入力をタッチで増減（シミュレーションの人数・一覧の広さ）
 *  6. 広さの絞り込み（#studio-filter）… 一覧のカードと地図のピンを「N帖以上の部屋がある店」だけにする
 *  7. 部屋のチェック欄（.sinfo-chips）… 「全部屋」と個別の部屋を同時に選ばせない
 *  8. 料金の編集（[data-rate-add]）… 見出し（対象・曜日）の＋で空の行を足す / ＋ 見出し / ＋ 時間帯
 *  9. 行の削除（[data-row-del]）… 行の主な欄を空にして隠す（保存で削除される。編集フォームは自動保存なので、押すとすぐ保存）
 * 10. 自動保存（form[data-autosave]）… 編集フォームの入力・選択・削除・並び替えのたびに、画面を移動せず fetch で保存する
 *       ・入力 = 1.5 秒、選択・チェック = 0.3 秒まとめてから送る / 削除ボタン・並び替えの直後も送る
 *       ・「保存」ボタンは隠れ、状態のアイコン（保存済み・保存中・未保存・空欄・失敗）に替わる（PHP: studio_save_button）
 *       ・既存の行の主な欄が空のとき・入力が不正なときは「保留」（送らない。行の削除はゴミ箱 / × のボタンだけ）
 *       ・新しい行（id が 0 で中身がある行）は、フォームから離れる・開閉を閉じる・ページを離れるまで送らない。保存後はフォームを
 *         サーバーの最新に差し替えて、新しい行に DB の番号を持たせる（二重に追加しないため）
 *       ・保存のたびに、カードの読み取り用の表示（料金表・パック・機材など）もサーバーの最新に差し替える
 *       ・エリアの名前変更（save_areas）だけは対象外（全スタジオに及び、同名で統合されるので「保存」ボタンのまま）
 *       ・料金シミュレーション（#studio-sim）は最初に1回だけ料金を読むので、保存では更新しない（開き直すと最新になる）
 *
 *  ■ IIFE（即時実行関数）とは
 *    (() => { ... })() の形。関数を作ってすぐ実行する。中で作った変数が外（ほかの JS）とぶつからない。
 *    モジュール（import/export）が無かった時代からの定番の書き方。
 *
 *  ■ XSS 対策（JS 側）
 *    スタジオ名など DB の文字は innerHTML に入れず、textContent で入れる（HTML として解釈されない）
 * ===================================================================== */
(() => {
  'use strict'; // 厳格モード: うっかりミス（宣言していない変数への代入など）をエラーにしてくれる

  /** JSON を埋め込んだ <script type="application/json"> を読む。無ければ null */
  const readJson = (id) => {
    const el = document.getElementById(id);
    if (!el) return null;
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  };

  /** 要素を作る小さな関数。el('span', 'muted', '文字') → <span class="muted">文字</span>（文字は textContent で安全に入れる） */
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  };

  /** 1234.5 → "¥1,235"。toLocaleString = 3桁ごとにカンマを付ける */
  const yen = (n) => '¥' + Math.round(n).toLocaleString('ja-JP');

  /** 0時からの分 → "H:MM"。長さの表示用（PHP の studio_fmt_min と同じ）。時刻には下の clock を使う */
  const hm = (min) => Math.floor(min / 60) + ':' + String(min % 60).padStart(2, '0'); // 長さ（2時間30分 → 2:30）
  // 時計の時刻（24時間で一周。25:00 → 1:00 / 24:00 → 0:00）。% 1440 = 1日（1440分）で割った余り
  const clock = (min) => hm(min % 1440);

  // ===================================================================
  //  1. 地図
  // ===================================================================
  const setupMap = () => {
    const box = document.getElementById('studio-map');
    const pins = readJson('studio-pins');
    // window.L = Leaflet 本体。読み込みに失敗していたら何もしない（ページの他の部分は動くように）
    if (!box || !pins || !pins.length || !window.L) return null;
    const L = window.L;

    // scrollWheelZoom: false → ページをスクロールしているだけで地図が拡大縮小してしまうのを防ぐ
    const map = L.map(box, { scrollWheelZoom: false });

    // タイル（地図の絵）は OpenStreetMap から借りる。attribution（出典の表示）は OSM の利用条件で必須
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    }).addTo(map);

    // ピンは画像ではなく divIcon（CSS で描いた丸）にする → サイトの赤色にそろい、画像のパス問題も起きない
    const icon = L.divIcon({ className: 'sinfo-pin', html: '<span></span>', iconSize: [26, 26], iconAnchor: [13, 26], popupAnchor: [0, -24] });
    const single = pins.length === 1 && document.querySelector('.sinfo-map--one');

    const markers = pins.map((p) => {
      // ポップアップの中身も DOM で作る（名前は textContent）
      const pop = el('div', 'sinfo-pop');
      if (single) {
        pop.append(el('strong', '', p.name));
      } else {
        const a = el('a', '', p.name);
        a.href = 'studio_info?id=' + encodeURIComponent(p.id);
        pop.append(a);
      }
      if (p.sub) pop.append(el('div', 'muted small', p.sub));
      return L.marker([p.lat, p.lng], { icon, title: p.name }).bindPopup(pop).addTo(map);
    });

    // 大学の吹き出し（一覧の地図だけ）。ロゴ画像の吹き出し = divIcon。fit: false（生田）は最初の表示範囲に入れない
    const campusEl = document.getElementById('studio-campuses');
    const campuses = readJson('studio-campuses') || [];
    const fitMarkers = [];
    campuses.forEach((c) => {
      const html = '<span class="sinfo-campus__bubble"><img src="' + encodeURI(campusEl.dataset.logo) + '" alt=""></span>';
      const cIcon = L.divIcon({ className: 'sinfo-campus', html, iconSize: [44, 50], iconAnchor: [22, 50] });
      const m = L.marker([c.lat, c.lng], { icon: cIcon, title: '明治大学 ' + c.name, keyboard: false }).addTo(map);
      if (c.fit) fitMarkers.push(m);
    });

    if (single || (pins.length === 1 && !campuses.length)) { // 詳細ページ（single）は、キャンパスが遠くても表示範囲はスタジオだけ
      map.setView([pins[0].lat, pins[0].lng], 15); // 1本だけ → 周辺も見える拡大率（16 から1段階縮小）
    } else {
      // fitBounds = 全部のピンが入るように拡大率と中心を自動で決める。padding = 端に余白
      map.fitBounds(L.featureGroup([...markers, ...fitMarkers]).getBounds(), { padding: [30, 30], maxZoom: 16 });
    }

    // 一覧のカードにマウスを乗せたら、そのピンのポップアップを開く（どのカードがどこか分かるように）
    document.querySelectorAll('[data-studio-id]').forEach((card) => {
      const i = pins.findIndex((p) => String(p.id) === card.dataset.studioId);
      if (i < 0) return;
      card.addEventListener('mouseenter', () => markers[i].openPopup());
    });

    // 絞り込み（setupFilter）から呼ばれる: visibleIds（Set）に入っているスタジオのピンだけを地図に残す
    //   removeLayer / addTo = 地図からピンを外す / 戻す。残ったピンが全部入るように表示範囲も取り直す
    const setVisible = (visibleIds) => {
      const shown = [];
      markers.forEach((m, i) => {
        if (visibleIds.has(String(pins[i].id))) {
          if (!map.hasLayer(m)) m.addTo(map);
          shown.push(m);
        } else if (map.hasLayer(m)) {
          map.removeLayer(m);
        }
      });
      if (shown.length > 1) map.fitBounds(L.featureGroup(shown).getBounds(), { padding: [30, 30], maxZoom: 16 });
      else if (shown.length === 1) map.setView(shown[0].getLatLng(), 15);
    };
    return { setVisible };
  };

  // ===================================================================
  //  5. ＋／－ボタン（data-step="-1" / "1"）: 同じ枠の中の number 入力を増減する
  //     イベント委譲 = ボタン1つずつではなく document 全体で click を受け、押された所が [data-step] かを調べる方式
  //     （ボタンが増えても、別のページに置いても書き足し不要。業界でよく使う書き方）
  // ===================================================================
  const setupStepper = () => {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-step]');
      if (!btn) return;
      const input = btn.parentElement.querySelector('input[type=number]');
      if (!input) return;
      const min = Number(input.min) || 0;
      const max = Number(input.max) || Infinity;
      // 空欄や不正な値は min 扱い → 範囲 [min, max] に収める（Math.min/max で挟む = clamp）
      const next = Math.min(max, Math.max(min, (Number(input.value) || min) + Number(btn.dataset.step)));
      if (String(next) === input.value) return; // 上限・下限で変わらないなら再計算しない
      input.value = String(next);
      input.dispatchEvent(new Event('input', { bubbles: true })); // 手入力と同じ経路で再計算・再絞り込みさせる
    });
  };

  // ===================================================================
  //  6. 絞り込み（スタジオ一覧）: 広さ と エリア（両方の条件が同時に効く）
  //     ・広さ: 各カードの data-max-area（そのスタジオでいちばん広い部屋の帖数。不明は空）が、入力した帖数以上のものだけ残す
  //       0 = 絞り込みなし。広さが不明のスタジオは、絞り込み中は出さない（条件を満たすか分からないため）
  //     ・エリア: 各カードの data-area が、選んだエリアと同じものだけ残す（空 = すべて。エリア未入力のスタジオは、選んでいる間は出さない）
  //     ・エリアと広さの両方があるときは、タブ（name="filter_tab"）で入力欄を切り替える。切り替えても条件は残る
  //     ・値は URL（?min=12&area=新宿）にも入れる → 絞り込んだ状態のまま URL を共有できる。既定の値（広さ 10帖・エリアなし）は書かない
  // ===================================================================
  const setupFilter = (mapApi) => {
    const box = document.getElementById('studio-filter');
    if (!box) return;
    const size = box.querySelector('input[name="size"]'); // 広さの入力（無ければ null）
    const area = box.querySelector('select[name="area"]'); // エリアの選択（無ければ null）
    const count = box.querySelector('[data-filter-count]');
    const people = box.querySelector('[data-filter-people]');
    const empty = document.querySelector('[data-filter-empty]');
    const cards = [...document.querySelectorAll('.sinfo-list [data-studio-id]')]; // [...NodeList] = 配列に変換
    const params = new URL(location.href).searchParams;
    const sizeDefault = size ? Number(size.defaultValue) || 0 : 0; // HTML に書いた初期値。この値のときは URL に書かない

    // ---- タブ（エリア / 広さ）----
    const tabLabel = (v) => box.querySelector(`input[name="filter_tab"][value="${v}"]`)?.closest('label');
    const showTab = () => {
      const v = box.querySelector('input[name="filter_tab"]:checked')?.value;
      if (!v) return; // タブが無い（片方だけ）ときは、そのまま見せる
      box.querySelectorAll('[data-filter-panel]').forEach((p) => { p.hidden = p.dataset.filterPanel !== v; });
    };

    const apply = () => {
      const min = size ? Math.max(0, Number(size.value) || 0) : 0;
      const areaName = area ? area.value : '';
      const visible = new Set();
      cards.forEach((card) => {
        const max = card.dataset.maxArea === '' ? null : Number(card.dataset.maxArea);
        const ok = (min <= 0 || (max !== null && max >= min)) && (areaName === '' || card.dataset.area === areaName);
        card.closest('li').hidden = !ok; // hidden 属性 = 表示から外す（HTML 標準）
        if (ok) visible.add(card.dataset.studioId);
      });
      count.textContent = visible.size < cards.length ? visible.size + ' / ' + cards.length : ''; // 隠れたスタジオがあるときだけ
      if (people) {
        // 目安の人数（定員ではなく、バンドで無理なく入れる人数）。サークルの実感とスタジオ各社の目安から決めた表
        const guide = min < 8 ? '2人' : min < 10 ? '3人' : min < 11 ? '3〜4人' : min < 12 ? '4〜5人' : min < 15 ? '5〜6人' : min < 20 ? '6〜7人' : '7人〜';
        people.textContent = min > 0 ? '目安' + guide : '';
      }
      // 今開いていないタブで条件が効いているとき、そのタブに印を付ける（CSS: data-on）
      tabLabel('area')?.toggleAttribute('data-on', areaName !== '');
      tabLabel('size')?.toggleAttribute('data-on', min !== sizeDefault);
      if (empty) empty.hidden = visible.size > 0;
      if (mapApi) mapApi.setVisible(visible);
      // history.replaceState = ページを移動せず URL だけ書き換える（戻るボタンの履歴は増やさない）
      const url = new URL(location.href);
      if (size && min !== sizeDefault) url.searchParams.set('min', String(min));
      else url.searchParams.delete('min');
      if (areaName) url.searchParams.set('area', areaName);
      else url.searchParams.delete('area');
      history.replaceState(null, '', url);
    };

    box.addEventListener('change', (e) => {
      if (e.target.name === 'filter_tab') switchPanels(box.querySelectorAll('[data-filter-panel]'), showTab);
      else if (e.target.name === 'area') apply();
    });
    size?.addEventListener('input', apply);

    // URL の値を入力欄へ（共有された URL・リロードで同じ絞り込みに戻す）
    const m = params.get('min');
    if (size && m !== null && m !== '' && Number.isFinite(Number(m))) {
      size.value = String(Math.min(Number(size.max) || 100, Math.max(0, Number(m))));
    }
    const a = params.get('area');
    if (area && a && [...area.options].some((o) => o.value === a)) {
      area.value = a;
      area.dispatchEvent(new Event('change', { bubbles: true })); // 独自の選択 UI の表示文字も更新させる
      const t = box.querySelector('input[name="filter_tab"][value="area"]');
      if (t) t.checked = true; // エリアで絞り込んだ URL は、エリアのタブから開く
    }
    showTab();
    apply();
  };

  // ===================================================================
  //  2. 住所 → 座標（国土地理院 住所検索 API。無料・API キー不要）
  //     返ってくる形: [{ geometry: { coordinates: [経度, 緯度] }, properties: { title: "住所" } }, ...]
  //     ※ GeoJSON という地図データの標準形式で、座標は「経度, 緯度」の順（緯度が先ではない）なので注意
  // ===================================================================
  const setupGeo = () => {
    const btn = document.querySelector('[data-geo-lookup]');
    if (!btn) return;
    const form = btn.closest('form');
    const addr = form.querySelector('[data-geo-address]');
    const coords = form.querySelector('[data-geo-coords]');

    btn.addEventListener('click', async () => {
      // 頭の郵便番号（〒101-0051 など）は取る。付いていると住所検索が 0 件になるため（サーバー側も保存時に同じく取る: studio_strip_zip）
      const q = addr.value.replace(/^\s*(〒\s*)?[0-9０-９]{3}\s*[-−‐ー－]?\s*[0-9０-９]{4}(?![0-9０-９])[\s　]*/u, '').trim();
      if (!q) {
        addr.focus();
        return;
      }
      btn.disabled = true; // 二度押し防止
      try {
        // async / await: 通信が終わるのを待ってから次の行へ進む書き方（コールバックが入れ子にならない）
        const res = await fetch('https://msearch.gsi.go.jp/address-search/AddressSearch?q=' + encodeURIComponent(q));
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const list = await res.json();
        if (!Array.isArray(list) || !list.length) {
          alert('住所が見つかりませんでした');
          return;
        }
        const [lng, lat] = list[0].geometry.coordinates; // 分割代入: 配列の1つ目を lng、2つ目を lat に入れる
        coords.value = lat.toFixed(6) + ', ' + lng.toFixed(6);
        coords.dispatchEvent(new Event('change', { bubbles: true })); // 値を JS で入れたので、自動保存（10.）にも知らせる
      } catch (e) {
        alert('座標を取得できませんでした');
      } finally {
        btn.disabled = false; // finally = 成功しても失敗しても最後に必ず実行される
      }
    });
  };

  // ===================================================================
  //  3. 料金シミュレーション
  //     ・バンド / 個人練習（人数ごとの料金）
  //     ・パック: 予約が収まるパックのうち、通常料金より安いものを自動で使う
  //     ・+1時間延長したら、合計がいくら増えるか
  // ===================================================================
  /** 料金の計算。本体は assets/studio_sim.js（日程調整 sched_poll.php でも同じ計算を使うので、別ファイルに切り出した） */
  const simEngine = window.studioSimEngine;

  const setupSim = () => {
    const form = document.getElementById('studio-sim');
    const data = readJson('studio-sim-data');
    const out = document.getElementById('studio-sim-result');
    if (!form || !data || !out) return;

    const { calc, dayOk } = simEngine(data);

    /** 区分 id → 区分 {id, name, day}（無ければ null）。day = 使える曜日 0 全日 / 1 平日のみ / 2 土日祝のみ / 3 土のみ / 4 日祝のみ */
    const discOf = (id) => data.discounts.find((d) => d.id === id) || null;
    const DAY_ONLY = ['', '平日のみ', '土日祝のみ', '土のみ', '日祝のみ'];

    /** 入力の値を読む（FormData = フォームの入力をまとめて取れるブラウザ標準の仕組み） */
    const read = () => {
      const fd = new FormData(form);
      const people = Math.max(1, Number(fd.get('people')) || 1);
      return {
        solo: fd.get('mode') === 'solo',
        ppl: fd.get('mode') === 'solo' ? people : 0, // 料金表の people と比べる値（0 = バンド）
        room: Number(fd.get('room') || 0),
        day: Number(fd.get('day') || 1),
        start: Number(fd.get('start')),
        dur: Number(fd.get('dur')),
        people,
        disc: Number(fd.get('disc') || 0), // 選んだ割引区分の id（0 = なし。割引の無いスタジオは選択欄ごと無い → 0）
        rentals: data.rentals.map((r) => ({ ...r, qty: Math.max(0, Number(fd.get('rental_' + r.id)) || 0) })),
      };
    };

    /** 「ラベル」と「金額」の2列の行 */
    const line = (label, value, cls) => {
      const li = el('li', cls || '');
      li.append(el('span', '', label), el('span', 'muted', value));
      return li;
    };

    const render = () => {
      const v = read();
      // ---- 割引の判定: 選んだ区分が、選んだ曜日（平日 / 土 / 日祝 / 土日祝）で使えるか ----
      //   区分の day が 0（全日）か、曜日が合えば使える（dayOk。「土日祝」の区分は土でも日祝でも使える）。使えなければ割引なし（0）で計算し、下に理由を出す
      const d = discOf(v.disc);
      const discOk = !!d && dayOk(d.day, v.day);
      const disc = discOk ? v.disc : 0;
      // 割引チップ: 有効（色付き）= 適用中 / 無効・曜日が合わない = 色なし（曜日が合わない区分は data-off で薄く）
      form.querySelectorAll('input[name="disc"]').forEach((c) => {
        const x = discOf(Number(c.value));
        c.closest('label').toggleAttribute('data-off', !!x && !dayOk(x.day, v.day));
      });
      const r = calc(v, disc, v.dur);
      const perPerson = (yenValue) => Math.ceil(yenValue / v.people); // 割り勘は1円単位で切り上げ（足りなくならないように）

      // ---- レンタル欄の見出しバッジ（閉じていても「いくつ選んでいるか」がわかるように）----
      //   r.rentals = 数が1以上のレンタルだけの配列なので、その長さ = 選んでいる種類の数
      //   hidden 属性 = 画面から隠す HTML 標準の属性（0 件のときはバッジごと隠す）
      const badge = form.querySelector('[data-rental-count]');
      if (badge) {
        badge.textContent = r.rentals.length;
        badge.hidden = r.rentals.length === 0;
      }

      out.replaceChildren(); // 中身を全部消してから作り直す

      // ---- 合計と1人あたり ----
      const head = el('div', 'sinfo-sim__total');
      const t1 = el('div');
      t1.append(el('span', 'muted small', '合計'), el('strong', 'sinfo-sim__yen', yen(r.total)));
      const t2 = el('div');
      t2.append(el('span', 'muted small', '1人あたり（' + v.people + '人）'), el('strong', 'sinfo-sim__yen', yen(perPerson(r.total))));
      head.append(t1, t2);
      out.append(head);

      // ---- 内訳 ----
      const ul = el('ul', 'sinfo-sim__lines');
      if (r.pack) {
        ul.append(line(r.pack.name, yen(r.pack.price), 'sinfo-sim__pack'));
      } else {
        r.normal.segs.forEach((s) => {
          const hours = (s.to - s.from) / 60;
          ul.append(line(clock(s.from) + '〜' + clock(s.to), yen(s.perHour) + '/h × ' + hours + 'h = ' + yen(s.perHour * hours)));
        });
      }
      r.rentals.forEach((x) => ul.append(line(x.name + ' × ' + x.qty, yen(x.cost))));
      out.append(ul);

      // ---- お得情報（パック・割引区分） ----
      if (r.packSave > 0) out.append(el('p', 'sinfo-sim__save', r.pack.name + 'で ' + yen(r.packSave) + ' お得'));
      if (disc) {
        const diff = calc(v, 0, v.dur).total - r.total; // 割引なしで計算した合計との差
        out.append(el('p', 'sinfo-sim__save', d.name + '適用中' + (diff > 0 ? '（' + yen(diff) + ' お得）' : '')));
      } else if (d) {
        // 区分を選んだのに、この曜日では使えない → 通常料金で計算したことを知らせる
        out.append(el('p', 'sinfo-sim__warn', d.name + 'は' + DAY_ONLY[d.day] + '（通常料金で計算）'));
      }

      // ---- +1時間延長したら（差額と1人あたりの差額） ----
      //   パックの時間を超える・料金の高い時間帯に入る、なども全部 calc() が計算し直すので、差を取るだけでよい
      //   今の予約にもう未登録の時間があるときは、差額を出しても意味がないので出さない
      const ext = calc(v, disc, v.dur + 60);
      const extBox = el('div', 'sinfo-sim__ext');
      if (r.missing) {
        // 何もしない（下の注意だけ出す）
      } else if (ext.missing) {
        extBox.append(el('span', 'muted', '+1時間延長: 料金未登録'));
      } else {
        const diff = ext.total - r.total;
        extBox.append(
          el('span', '', '+1時間延長'),
          el('strong', '', '+' + yen(diff)),
          el('span', 'muted small', '1人 +' + yen(perPerson(ext.total) - perPerson(r.total))),
        );
      }
      if (extBox.childElementCount) out.append(extBox); // 中身があるときだけ出す

      // ---- 料金表に無い時間が混ざっていたら注意 ----
      if (r.missing) {
        out.append(el('p', 'sinfo-sim__warn', (v.solo ? '個人' + v.people + '人の' : '') + '料金未登録の時間があります（' + r.missing * 30 + '分）'));
      }
    };

    // 部屋ごとの設定（30分スタート可 hs / 30分単位で利用可 hu）に合わせて、「開始」「時間」の選択肢を絞る
    //   最初に全部の選択肢を覚えておき（allOpts）、部屋が変わるたびに「毎時ちょうど（60の倍数）だけ」などに作り直す
    //   作り直したあと、元の値が無くなっていたら 1つ前の「ちょうどの値」（切り捨て）に寄せる。change を出すのは、独自プルダウンの表示文字を更新させるため
    const startSel = form.elements.start;
    const durSel = form.elements.dur;
    const allOpts = new Map([startSel, durSel].map((sel) => [sel, [...sel.options].map((o) => [o.value, o.textContent])]));
    const applyRoomRule = () => {
      const room = data.rooms.find((r) => r.id === Number(form.elements.room?.value || 0));
      const hs = room ? room.hs : 1; // 部屋の無いスタジオ = 制限なし
      const zs = room ? (room.zs ?? !room.hs) : 1; // 0分開始（古いデータに無ければ「30分開始でなければ 0分開始」）
      const hu = room ? room.hu : 1;
      // 開始の選択肢: 30分開始だけ → 30分だけ（9:30・10:30 …）/ 0分開始だけ → ちょうどだけ / 両方 → 30分刻み
      //   時間の選択肢: 30分単位なら30分刻み / 1時間単位ならちょうどだけ
      const halfOnly = !!room && hs && !zs; // 30分始まりだけの部屋は、30分単位でも開始を :30 だけにする（部屋の無いスタジオは制限なし）
      [[startSel, hs && zs], [durSel, hu]].forEach(([sel, half]) => {
        const keep = Number(sel.value);
        const only30 = sel === startSel && halfOnly;
        const opts = allOpts.get(sel).filter(([v]) => (only30 ? Number(v) % 60 === 30 : half || Number(v) % 60 === 0));
        sel.replaceChildren(...opts.map(([v, txt]) => new Option(txt, v)));
        const val = only30 ? keep - (keep % 60) + 30 : half ? keep : keep - (keep % 60); // 30分だけ → 同じ時の:30 / 30分が使えないとき → 毎時ちょうどに切り捨て
        sel.value = String(val);
        if (sel.value === '') sel.selectedIndex = 0; // 時間が最小（1:00）を割る場合など
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      });
    };
    form.elements.room?.addEventListener('change', applyRoomRule);

    // バンド ⇔ 個人を切り替えたら人数をそれっぽい値に（バンド 4人 / 個人 1人）。手で変えた人数は覚えておいて戻す
    const people = form.elements.people;
    const remembered = { band: people.value, solo: '1' };
    let lastMode = form.elements.mode.value;
    form.addEventListener('change', (e) => {
      if (e.target.name !== 'mode') return;
      remembered[lastMode] = people.value;
      lastMode = e.target.value;
      people.value = remembered[lastMode];
    });

    // 割引チップは同時に1つだけ有効（1つ入れたら他は外す）。全部外す = 割引なし
    form.addEventListener('change', (e) => {
      if (e.target.name !== 'disc' || !e.target.checked) return;
      form.querySelectorAll('input[name="disc"]').forEach((c) => { if (c !== e.target) c.checked = false; });
    });

    // input イベント = 値が変わるたび（数字を打つたび）に発生。change より反応が早い
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    if (form.elements.mode.value === 'solo') people.value = '1'; // 個人練習の料金しか無いスタジオ
    applyRoomRule(); // 最初の部屋の設定で選択肢を絞る（change → render も走る）
    render(); // 最初に1回表示
  };

  // ===================================================================
  //  4. ✨AI（AIで検索 / AIで編集。編集できる人）
  //     api_studio_ai.php に頼む（検索 = 既定 / 編集 = mode=edit + instruction）→ 提案をプレビュー → 項目ごとにチェックして「反映」（studio_info.php の apply_ai）
  //     提案の中身はサーバーのセッションにあるので、ここから送るのは「どの項目を使うか」のチェックだけ
  // ===================================================================
  const setupAi = () => {
    const box = document.getElementById('studio-ai');
    if (!box) return;
    const btn = box.querySelector('[data-ai-run]');
    const status = box.querySelector('[data-ai-status]');
    // モデルの選択は検索・編集の実行ボタンの横にそれぞれあるので、片方を変えたらもう片方も同じ値にする
    box.addEventListener('change', (e) => {
      if (!e.target.matches('[data-ai-model]')) return;
      box.querySelectorAll('[data-ai-model]').forEach((s) => { s.value = e.target.value; s.dispatchEvent(new Event('change')); });
    });
    const form = document.getElementById('studio-ai-result');
    const body = form.querySelector('[data-ai-body]');

    const DAYS = ['全日', '平日', '土日祝', '土', '日祝'];
    const SECTIONS = { info: '基本情報', rooms: '部屋', discounts: '割引区分', rates: '料金', packs: 'パック', rentals: 'レンタル', equipment: '機材' };
    const INFO = { reserve_url: '予約ページ', website_url: 'サイト', address: '住所', access: '最寄り駅', hours: '営業時間', phone: '電話', student_note: '割引の条件', note: 'メモ' };
    const DAY_ONLY = ['全日', '平日のみ', '土日祝のみ', '土のみ', '日祝のみ'];
    const target = (p) => (p == null ? 'バンド練習' :'個人' + p + '人');
    const where = (r) => [target(r.people), r.rooms.length ? r.rooms.join('・') : '全部屋', DAYS[r.day_type]].join('・');
    // 割引区分ごとの料金 r.discounts = {区分名: 料金} → 「（学割 ¥1,650・U20 ¥1,100）」
    const price = (r) => {
      const parts = Object.entries(r.discounts || {}).map(([name, p]) => name + ' ' + yen(p)); // entries = [[キー, 値], …]
      return yen(r.price) + (parts.length ? '（' + parts.join('・') + '）' : '');
    };

    /** 機材の「メーカー 型番 メモ」（空の欄は飛ばす） */
    const equipText = (r) => [r.maker, r.model_name].filter(Boolean).join(' ') + (r.cabinet ? ' + ' + r.cabinet : '') + (r.note ? ' ' + r.note : '');

    /** 項目ごとに、プレビューの1行の文字を作る関数 */
    const LINES = {
      info: (p) => Object.keys(INFO).filter((k) => p.info[k]).map((k) => INFO[k] + ': ' + p.info[k]),
      discounts: (p) => p.discounts.map((d) => d.name + ': ' + DAY_ONLY[d.day_type]),
      rooms: (p) => p.rooms.map((r) => r.name + ' ' + [r.area_jo != null ? r.area_jo + '帖' : '', r.size_note || ''].filter(Boolean).join('・')),
      rates: (p) => p.rates.map((r) => where(r) + ' ' + clock(r.start_min) + '〜' + clock(r.end_min) + ' ' + price(r) + '/h'),
      packs: (p) => p.packs.map((r) => r.name + ': ' + where(r) + ' ' + clock(r.start_min) + '〜' + clock(r.end_min)
        + (r.minutes ? ' のうち ' + hm(r.minutes) : '') + ' ' + price(r)),
      rentals: (p) => p.rentals.map((r) => r.name + ' ' + yen(r.price) + (r.per_hour ? '/h' : '/回')),
      equipment: (p) => p.equipment.map((r) => (r.rooms.length ? r.rooms.join('・') + ' ' : '') + r.category + ': ' + equipText(r) + (r.quantity !== 1 ? ' × ' + r.quantity : '')),
    };

    // ---- 「AIで編集」のプレビュー: 今の内容（current）と提案を比べて、変わったところだけを出す ----
    //   提案の中身はサーバーが「本当に変わった項目」だけに絞ってある（lib/studio_ai.php の studio_ai_edit）。ここでは項目の中の行を比べる

    /** 行を「並びに関係なく比べられる」文字列にする（サーバーの studio_ai_row_keys と同じ考え方）。部屋名は並べ替え、キーの順も固定する */
    const canon = (row) => JSON.stringify(row, (k, v) => {
      if (Array.isArray(v) && k === 'rooms') return [...v].sort();
      if (v && typeof v === 'object' && !Array.isArray(v)) return Object.fromEntries(Object.keys(v).sort().map((x) => [x, v[x]])); // キーを並べ替えた新しい物に
      return v;
    });
    /** 行の「同じ所」の目印。今の行と提案の行を、価格など以外が同じもの同士で組にして「旧 → 新」と出すため */
    const rooms_ = (r) => [...r.rooms].sort().join(',');
    const SAME_SLOT = {
      rates: (r) => [rooms_(r), r.people, r.day_type, r.start_min, r.end_min].join('|'),
      packs: (r) => [r.name, rooms_(r), r.people, r.day_type, r.start_min, r.end_min].join('|'),
      rentals: (r) => r.name,
      equipment: (r) => [r.category, r.maker, r.model_name, r.cabinet, r.note, rooms_(r)].join('|'),
    };
    /** 1行の文字（検索のプレビューと同じ書き方） */
    const lineOf = (key, row) => LINES[key]({ [key]: [row] })[0];

    /**
     * 料金・パック・レンタル・機材（丸ごと置き換え）の差: 並びを無視して行を数え合わせる
     *   戻り値: items = 変わった行 [{ old, now }]（旧だけ = 削除 / 新だけ = 追加 / 両方 = 変更）, same = 変わらない行
     */
    const diffRows = (key, cur, prop) => {
      const left = new Map(); // 今の行: 目印 → [行, …]（同じ行が2つあれば2つ入る）
      cur.forEach((r) => { const k = canon(r); left.set(k, [...(left.get(k) || []), r]); });
      const added = [];
      const same = [];
      prop.forEach((r) => {
        const q = left.get(canon(r));
        if (q && q.length) { q.shift(); same.push(r); } else added.push(r);
      });
      // 今にしか無い行（= 削除か、変更の「旧」）。同じ所の追加行があれば組にする
      const pool = new Map();
      [...left.values()].flat().forEach((r) => { const k = SAME_SLOT[key](r); pool.set(k, [...(pool.get(k) || []), r]); });
      const items = added.map((r) => ({ old: pool.get(SAME_SLOT[key](r))?.shift() ?? null, now: r }));
      [...pool.values()].flat().forEach((r) => items.push({ old: r, now: null }));
      return { items, same };
    };

    /** 項目ごとの差 → { items: [{ head, old, now }]（文字）, same: [文字] }。old だけ = 削除 / now だけ = 追加 */
    const editDiff = (key, p) => {
      const cur = p.current?.[key];
      if (key === 'info') {
        const items = Object.keys(INFO).filter((k) => p.info[k]).map((k) => ({ head: INFO[k], old: cur?.[k] || null, now: p.info[k], plain: true }));
        return { items, same: [] };
      }
      if (key === 'rooms' || key === 'discounts') {
        // 提案にあるのは新しい行と値が変わった行だけ（部屋・区分は削除できない）。同じ名前の今の行があれば、空の欄は今の値で補って「旧 → 新」にする
        const items = p[key].map((r) => {
          const c = (cur || []).find((x) => x.name === r.name);
          // 空の欄（null）は「変えない」なので、今の値で補ってから「旧 → 新」にする
          const now = c ? Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v == null ? c[k] : v])) : r;
          return { old: c ? lineOf(key, c) : null, now: lineOf(key, now) };
        });
        return { items, same: [] };
      }
      const d = diffRows(key, cur || [], p[key]);
      return {
        items: d.items.map((it) => ({ old: it.old ? lineOf(key, it.old) : null, now: it.now ? lineOf(key, it.now) : null })),
        same: d.same.map((r) => lineOf(key, r)),
      };
    };

    /** 差の <li>: 旧 → 新（旧は取り消し線）/ 追加（新だけ）/ 削除（旧だけ）。文字は textContent で入れる（XSS 対策） */
    const diffItem = (it) => {
      const li = el('li');
      if (it.head) li.append(document.createTextNode(it.head + ': '));
      if (it.old && it.now) {
        li.append(el('span', 'sinfo-ai__old', it.old), document.createTextNode(' → '), el('span', 'sinfo-ai__new', it.now));
      } else if (it.now) {
        if (!it.plain) li.append(el('span', 'sinfo-ai__tag', '追加'));
        li.append(el('span', 'sinfo-ai__new', it.now));
      } else {
        li.append(el('span', 'sinfo-ai__tag', '削除'), el('span', 'sinfo-ai__old', it.old));
      }
      return li;
    };

    const show = (p, yenUsed) => {
      body.replaceChildren();
      const edit = p.edit === true; // 編集の提案か（changed の項目だけを、今との差で出す）
      if (!p.found && !edit) {
        body.append(el('p', 'sinfo-sim__warn', '情報が見つかりませんでした。サイトの URL を入れてから試すと見つかりやすくなります'));
      }
      let any = false;
      Object.keys(SECTIONS).forEach((key) => {
        let count;
        let content;
        if (edit) {
          if (!(p.changed || []).includes(key)) return;
          const d = editDiff(key, p);
          if (!d.items.length) return;
          count = d.items.length;
          content = el('div');
          const ul = el('ul', 'sinfo-plain sinfo-ai__lines');
          d.items.forEach((it) => ul.append(diffItem(it)));
          content.append(ul);
          if (d.same.length) { // 変わらない行は畳んでおく（数だけ見せる）
            const same = el('details', 'sinfo-ai__same');
            same.append(el('summary', 'muted small', '変更なし ' + d.same.length + '件'));
            const sul = el('ul', 'sinfo-plain sinfo-ai__lines');
            d.same.forEach((t) => sul.append(el('li', '', t)));
            same.append(sul);
            content.append(same);
          }
        } else {
          const lines = LINES[key](p);
          if (!lines.length) return; // 提案が無い項目は出さない（反映もできない）
          count = lines.length;
          content = el('ul', 'sinfo-plain sinfo-ai__lines');
          lines.forEach((t) => content.append(el('li', '', t)));
        }
        any = true;
        // <details> で畳む。見出しのチェックボックスが「この項目を反映するか」
        const d = el('details', 'sinfo-ai__sec');
        if (edit) d.open = true; // 編集は変わった所を見て確かめるので、最初から開く
        const sum = el('summary');
        const label = el('label', 'sinfo-check');
        const cb = el('input');
        cb.type = 'checkbox';
        cb.name = 'sections[]';
        cb.value = key;
        cb.checked = true;
        label.append(cb, document.createTextNode(' ' + SECTIONS[key]));
        const chev = el('span', 'icon togglecard__chevron', 'expand_more'); // 開閉の印は、サイト共通の ⌄（PHP の icon() と同じ作り）
        chev.setAttribute('aria-hidden', 'true');
        sum.append(label, el('span', 'muted small', count + '件'), chev);
        d.append(sum, content);
        body.append(d);
      });
      if (p.caution) body.append(el('p', 'sinfo-sim__warn', p.caution));
      if (p.sources.length) {
        const src = el('div', 'sinfo-ai__src muted small');
        src.append(el('span', '', '出典: '));
        p.sources.forEach((u) => {
          const a = el('a', '', new URL(u).hostname); // 長い URL はドメインだけ見せる
          a.href = u; // サーバーで http(s) だけに絞ってある
          a.target = '_blank';
          a.rel = 'noopener noreferrer';
          src.append(a, document.createTextNode(' '));
        });
        body.append(src);
      }
      form.querySelector('[type=submit]').hidden = !any;
      form.hidden = false;
      status.textContent = yenUsed != null ? '約' + yenUsed + '円' : '';
    };

    // ---- 画像（選ぶ・貼り付け）----
    //   images = 送る画像（File の配列）。サムネイル（×で外せる）を thumbs に出す
    const maxImages = Number(box.dataset.maxImages) || 5;
    const picker = box.querySelector('[data-ai-files]');
    const thumbs = box.querySelector('[data-ai-thumbs]');
    const images = [];

    const renderThumbs = () => {
      thumbs.replaceChildren();
      images.forEach((it, i) => {
        const wrap = el('span', 'sinfo-ai__thumb');
        const img = el('img');
        img.src = it.url; // URL.createObjectURL で作った、この端末の中だけで使える一時 URL
        img.alt = '画像' + (i + 1);
        const x = el('button', 'sinfo-ai__thumb-x', '×');
        x.type = 'button';
        x.setAttribute('aria-label', '画像を外す');
        x.addEventListener('click', () => {
          URL.revokeObjectURL(it.url); // 一時 URL は使い終わったら解放する（メモリを残さない）
          images.splice(images.indexOf(it), 1);
          renderThumbs();
        });
        wrap.append(img, x);
        thumbs.append(wrap);
      });
      thumbs.hidden = images.length === 0;
    };

    /**
     * 長辺 1568px・JPEG に縮小する（サーバーの縮小と同じ大きさ）。スマホの写真は数 MB あり、そのまま送ると
     * サーバーのアップロード上限（php.ini）を超えやすいので、送る前に小さくする。
     * createImageBitmap で読めない形式（ブラウザが未対応）は、そのまま送ってサーバーに任せる
     */
    const shrink = async (file) => {
      try {
        const bmp = await createImageBitmap(file);
        const scale = Math.min(1, 1568 / Math.max(bmp.width, bmp.height));
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(bmp.width * scale));
        c.height = Math.max(1, Math.round(bmp.height * scale));
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; // 透明な PNG は白背景に（JPEG は透明にできない）
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(bmp, 0, 0, c.width, c.height);
        const blob = await new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.85));
        return blob ? new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
      } catch (e) {
        return file;
      }
    };

    const addImages = async (files) => {
      const list = [...files].filter((f) => f.type.startsWith('image/'));
      for (const f of list) {
        if (images.length >= maxImages) {
          status.textContent = '画像は' + maxImages + '枚までです';
          break;
        }
        const small = await shrink(f);
        images.push({ file: small, url: URL.createObjectURL(small) });
      }
      renderThumbs();
    };

    box.querySelector('[data-ai-pick]').addEventListener('click', () => picker.click());
    picker.addEventListener('change', async () => {
      await addImages(picker.files);
      picker.value = ''; // 同じ画像をもう一度選んでも change が起きるように空に戻す
    });
    // 貼り付け（Ctrl+V / スクリーンショット）。入力欄への文字の貼り付けは邪魔しない（画像があるときだけ受け取る）
    document.addEventListener('paste', (e) => {
      const files = [...(e.clipboardData?.files || [])].filter((f) => f.type.startsWith('image/'));
      if (!files.length || box.querySelector('[data-ai-panel="search"]').hidden) return; // 編集のタブでは画像を使わないので、貼り付けは受け取らない
      e.preventDefault();
      addImages(files);
    });

    // ---- タブ（AIで検索 / AIで編集）----
    //   選択中のタブに .is-active、もう一方の中身は hidden。赤い丸の動きは app_ui.js の setupTabGlide が .tabs に自動で付ける
    //   リクエストの最中に切り替えてもよい（結果は届いたらプレビューに出る）
    const tabButtons = [...box.querySelectorAll('[data-ai-tab]')];
    const panels = [...box.querySelectorAll('[data-ai-panel]')];
    tabButtons.forEach((tab) => {
      tab.addEventListener('click', () => {
        tabButtons.forEach((t) => {
          t.classList.toggle('is-active', t === tab);
          t.setAttribute('aria-selected', String(t === tab));
        });
        panels.forEach((p) => { p.hidden = p.dataset.aiPanel !== tab.dataset.aiTab; });
      });
    });

    // ---- 実行（検索 / 編集 共通）----
    //   mode 'search' … ウェブ検索（か画像）で調べる / 'edit' … 今のデータを指示どおりに書き換える
    //   どちらも同じ API（api_studio_ai.php）に送り、同じプレビュー（show）に出す。待っている間は両方の実行ボタンを止める
    const editBtn = box.querySelector('[data-ai-edit-run]');
    const instruction = box.querySelector('[data-ai-instruction]');
    const run = async (mode) => {
      btn.disabled = true;
      editBtn.disabled = true;
      form.hidden = true;
      // 待ち時間（数十秒〜1分）を秒で見せる。setInterval = 決まった間隔でくり返し実行
      const t0 = Date.now();
      const verb = mode === 'edit' ? '編集しています' : images.length ? '読んでいます' : '調べています'; // 画像があれば画像だけを読む（検索しない）
      const tick = () => { status.textContent = verb + '… ' + Math.round((Date.now() - t0) / 1000) + '秒'; };
      tick();
      const timer = setInterval(tick, 1000);
      try {
        // FormData = フォームと同じ形式（multipart）で送る。画像ファイルを送れる。
        // Content-Type は自分で書かない（ブラウザが境界の文字列つきで自動で付ける。手書きすると壊れる）
        const fd = new FormData();
        fd.append('studio_id', box.dataset.studioId);
        fd.append('model', box.querySelector('[data-ai-model]').value);
        if (mode === 'edit') {
          const text = instruction.value.trim();
          if (!text) throw new Error('指示を入力してください');
          fd.append('mode', 'edit');
          fd.append('instruction', text);
        } else {
          const picked = [...box.querySelectorAll('[data-ai-section]:checked')];
          if (!picked.length) throw new Error('調べる項目を選んでください');
          picked.forEach((c) => fd.append('sections[]', c.value));
          if (box.querySelector('[data-ai-official]')?.checked) fd.append('source', 'official');
          const extra = box.querySelector('[data-ai-extra]')?.value.trim();
          if (extra) fd.append('extra', extra);
          images.forEach((it) => fd.append('images[]', it.file, it.file.name));
        }
        const res = await fetch('api_studio_ai', {
          method: 'POST',
          headers: { 'X-CSRF-Token': box.dataset.csrf },
          body: fd,
        });
        const data = await res.json().catch(() => ({ error: 'サーバーの返事を読めませんでした' }));
        // 編集で何も変わらないとき（422）は、失敗ではなく「変更なし」のお知らせ。サーバーのメッセージをそのまま見せる
        if (!res.ok || !data.ok) throw new Error(data.error || 'HTTP ' + res.status);
        show(data.proposal, data.yen);
      } catch (e) {
        status.textContent = e.message;
      } finally {
        clearInterval(timer);
        btn.disabled = false;
        editBtn.disabled = false;
      }
    };
    btn.addEventListener('click', () => run('search'));
    editBtn.addEventListener('click', () => run('edit'));
    // 指示の入力欄: Ctrl（Mac は Cmd）+ Enter で実行。日本語入力の変換中の Enter（isComposing）は無視する
    instruction.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !e.isComposing && !editBtn.disabled) {
        e.preventDefault();
        editBtn.click();
      }
    });
  };

  // ===================================================================
  //  7. 料金・パック・機材の編集: 部屋のチェック欄
  //     「全部屋」にチェック → 個別の部屋は外す / 個別の部屋にチェック → 「全部屋」は外す
  //     何も選ばないまま保存しても「全部屋」として扱われる（サーバー側 studio_post_rooms）
  // ===================================================================
  const setupRoomChips = () => {
    document.addEventListener('change', (e) => {
      const box = e.target;
      const group = box.closest?.('.sinfo-chips');
      if (!group || box.type !== 'checkbox' || !box.checked) return; // チェックを外したときは何もしない
      group.querySelectorAll('input[type=checkbox]').forEach((c) => {
        // 今チェックした「全部屋」以外を外す / 今チェックした個別の部屋なら「全部屋」だけ外す
        if (c !== box && (box.hasAttribute('data-room-all') || c.hasAttribute('data-room-all'))) c.checked = false;
      });
    });
  };

  // ===================================================================
  //  8. 料金の編集: 見出し（対象・曜日）の「＋」で、同じ見出しに空の行を足す
  //     <template data-rate-tpl> の中身（hidden の対象・曜日入り）を複製して、template の直前に入れる
  //     ・template.content = 画面に出ていない HTML の部品。cloneNode(true) = 中身ごと丸ごと複製
  //     ・入れた行の <select> は app.js の MutationObserver が見つけて、独自の選択 UI に変えてくれる
  //     ・足した行は、時間を「直前の行の終了」から始める（よくある「続きの時間帯」をすぐ入れられるように）
  //     ・部屋のチェックも直前の行と同じにする（同じ部屋たちの続きの時間帯を入れることが多いので）
  //     ・行のキー: 雛形の中の '__K__' を、ページ内で重複しない 'j1', 'j2' … に置き換える。
  //       サーバーは key[] と rooms[キー][] で「どの行のチェックか」を結ぶ（位置で結ぶと、途中に行を足したときにずれるため）
  // ===================================================================
  let rateRowSeq = 0; // 足した行の連番（ページを開いてから足した数。PHP 側の 'e'+id / 'n1' と被らないよう 'j' で始める）
  const setupRateEdit = () => {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-rate-add]');
      if (!btn) return;
      const group = btn.closest('[data-rate-group]');
      const tpl = group.querySelector('template[data-rate-tpl]');
      const row = tpl.content.firstElementChild.cloneNode(true);
      const key = 'j' + (++rateRowSeq);
      // name 属性の '__K__'（slot[__K__][…]）を本物のキーに
      row.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replaceAll('__K__', key); });
      row.dataset.key = key;
      const rows = group.querySelectorAll('.sinfo-slot-edit:not([hidden])');
      const prev = rows[rows.length - 1];
      if (prev) {
        // 直前の時間帯の「終了」から始める（続きの時間帯をすぐ入れられるように）
        // 開始・終了は <select>（従来）か hidden（時 + :30 の入力）のどちらか。name の末尾で探せば両方に使える
        const end = Number(prev.querySelector('[name$="[end]"]').value);
        row.querySelector('[name$="[start]"]').value = String(end);
        row.querySelector('[name$="[end]"]').value = String((end + 6 * 60) % (24 * 60)); // 6時間後（時計の時刻は 0:00〜23:30 だけなので、24時間で一周させる。例: 20:00 → 2:00）
        row.querySelectorAll('[data-tp]').forEach(tpLoad); // hidden に入れた値を、見える方（時・:30）にも反映
      }
      tpl.before(row);
      row.querySelector('input[data-price]')?.focus();
    });

    // 雛形の '__K__'（と '__N__'）を本物に置き換える。name と data-key
    const fill = (root, key, n) => {
      [root, ...root.querySelectorAll('[name]')].forEach((el) => {
        if (el.name) el.name = el.name.replaceAll('__K__', key).replaceAll('__N__', String(n));
      });
      [root, ...root.querySelectorAll('[data-key]')].forEach((el) => { if (el.dataset.key) el.dataset.key = key; });
    };

    let slotSeq = 0; // 追加の時間帯の番号（x[キー][番号][...]。ページ内で重複しなければ何でもいい）
    document.addEventListener('click', (e) => {
      // 「＋ 見出し」: 新しい見出しの行をもう1つ足す（保存せずに何個でも作れる）
      const newBtn = e.target.closest('[data-rate-new-add]');
      if (newBtn) {
        const tpl = newBtn.parentElement.querySelector('template[data-rate-new-tpl]');
        const group = tpl.content.firstElementChild.cloneNode(true);
        fill(group, 'j' + (++rateRowSeq), 0);
        newBtn.before(group);
        group.querySelector('select[name$="[people]"]').focus();
        return;
      }
      // 「＋ 時間帯」: 同じ料金で、曜日・開始・終了の行を足す
      const slotBtn = e.target.closest('[data-slot-add]');
      if (slotBtn) {
        const row = slotBtn.closest('[data-key]');
        const tpl = slotBtn.closest('form').querySelector('template[data-slot-tpl]');
        const line = tpl.content.firstElementChild.cloneNode(true);
        fill(line, row.dataset.key, ++slotSeq);
        slotBtn.before(line);
        return;
      }
      // 追加の時間帯の ×: 行ごと消す（消した欄は送られない）
      const delBtn = e.target.closest('[data-slot-del]');
      if (delBtn) delBtn.closest('.sinfo-rate-row__slot').remove();
    });
  };

  // ---- 9. 行の削除 ----
  //   サーバーは「主な欄が空の行 = 削除」なので、その欄を空にして行を隠すだけ（保存するまで DB は変わらない）
  //   フォームの action ごとに「主な欄」の名前が違う。料金表は1つの時間帯に部屋の数だけ料金欄があるので、全部空にする
  // ===================================================================
  //  時間帯の入力（時のプルダウン2つ + 「:30」チェック）… studio_time_pair() の相方
  //    実際に送るのは hidden（[data-tp-s] / [data-tp-e] = 0時からの分）。見える入力が変わったら、そこへ計算して入れる
  //    開始 = 開始の時 × 60 + (チェックなら 30) / 終了も同じ。→ チェックは開始・終了を一緒に30分ずらす
  // ===================================================================
  /** hidden の値 → 見える入力（時・チェック）に反映 */
  const tpLoad = (tp) => {
    const s = Number(tp.querySelector('[data-tp-s]').value);
    const e = Number(tp.querySelector('[data-tp-e]').value);
    tp.querySelector('[data-tp-hs]').value = String(Math.floor(s / 60));
    tp.querySelector('[data-tp-he]').value = String(Math.floor(e / 60));
    tp.querySelector('[data-tp-half]').checked = s % 60 === 30;
  };
  /* 部屋の編集: 「30分単位の予約」（全部屋まとめて）。部屋ごとに値が違う（混在）ときは「−」表示にする。
   *   押したら、部屋ごとの値を保つ印（half_unit_mixed）を外して、チェックの状態で全部屋に反映する */
  const setupHalfUnitAll = () => {
    // root の中のチェックに仕込む。自動保存でフォームの中身が差し替わったとき（autosave:replaced）は、新しいチェックにもう一度仕込む
    const init = (root) => {
      const box = root.querySelector('[data-half-unit-all]');
      if (!box) return;
      const mixed = root.querySelector('[data-half-unit-mixed]');
      if (mixed) box.indeterminate = true;
      box.addEventListener('change', () => root.querySelector('[data-half-unit-mixed]')?.remove());
    };
    init(document);
    document.addEventListener('autosave:replaced', (e) => init(e.target));
  };

  const setupTimePair = () => {
    document.addEventListener('change', (ev) => {
      const tp = ev.target.closest('[data-tp]');
      if (!tp) return;
      const half = tp.querySelector('[data-tp-half]').checked ? 30 : 0;
      tp.querySelector('[data-tp-s]').value = String(Number(tp.querySelector('[data-tp-hs]').value) * 60 + half);
      tp.querySelector('[data-tp-e]').value = String(Number(tp.querySelector('[data-tp-he]').value) * 60 + half);
    });
  };

  /**
   * 行の追加: [data-add="雛形のセレクタ"] のボタンを押すと、フォーム内の <template> を複製して、ボタンの入っている div の直前に入れる
   *   雛形の中の '__I__' = フォーム内の行の位置（id[] の数 = 末尾に足すので、送信時の位置と一致する。rooms[位置][] / half_start[位置] 用）
   *   '__B__' = パックのブロック番号（行を足すときは、ボタンの入っているブロックの番号 / 時間帯を足すときは新しい番号）
   *   data-add-max = 新しい行（id が 0 の行）の上限（割引区分）
   *   入れた <select> は app.js の MutationObserver が独自の選択 UI にしてくれる
   */
  const setupAddRow = () => {
    document.addEventListener('click', (ev) => {
      const btn = ev.target.closest('[data-add]');
      const form = btn?.closest('form');
      const tpl = form?.querySelector(btn.dataset.add);
      if (!tpl) return;
      const max = Number(btn.dataset.addMax) || 0;
      if (max && form.querySelectorAll('input[name="id[]"][value="0"]').length >= max) return;
      const block = btn.closest('.sinfo-edit__block');
      const b = block ? block.querySelector('input[name="blk[]"]')?.value : String(form.querySelectorAll('.sinfo-edit__block').length);
      const holder = btn.parentElement;
      holder.insertAdjacentHTML('beforebegin', tpl.innerHTML
        .replaceAll('__I__', String(form.querySelectorAll('input[name="id[]"]').length))
        .replaceAll('__B__', b ?? '0'));
      form.dispatchEvent(new Event('reorder-renumber'));
      holder.previousElementSibling.querySelector('input[type=text], input[type=number]')?.focus();
    });
  };

  const DEL_FIELD = {
    save_rates: 'input[data-price]', save_packs: 'input[name="price[]"]', save_rentals: 'input[name="name[]"]',
    save_equipment: 'input[name="maker[]"], input[name="model[]"]', save_rooms: 'input[name="name[]"]', save_discounts: 'input[name="name[]"]',
    save_areas: 'input[name="area_name[]"]',
  };

  /**
   * 並び替え（form[data-reorder] の中の [data-reorder-item] を、[data-reorder-handle] のドラッグ / ↑↓キーで入れ替える）
   *   保存では送られてきた順に sort_order を振るので、DOM の順番を変えるだけでよい。
   *   half_start[番号] のように「行の位置」を名前に持つ欄は、動かすたびに位置に合わせて付け直す
   *   ドラッグ中は、指の位置が上か下の行の真ん中を越えたら入れ替える（削除で隠した行は飛ばす）
   */
  const setupReorder = () => {
    document.querySelectorAll('form[data-reorder]').forEach((form) => {
      const items = () => [...form.querySelectorAll('[data-reorder-item]')];
      const visible = () => items().filter((el) => !el.hidden);
      const renumber = () => items().forEach((row, i) => {
        row.querySelectorAll('[name]').forEach((el) => {
          el.name = el.name.replace(/^(zero_start|half_start|half_unit_was)\[\d+\]$/, `$1[${i}]`);
        });
      });
      form.addEventListener('reorder-renumber', renumber); // 行を足したとき（setupAddRow）にも番号を付け直す
      // 並び替えが終わったことを知らせる（自動保存 10. が聞いて保存する）。ドラッグ中は何度も入れ替わるので、離したときに1回だけ
      const done = () => form.dispatchEvent(new Event('reorder-done'));
      let drag = null; // ドラッグ中だけ { row, pointerId, moved }
      form.addEventListener('pointerdown', (e) => {
        const handle = e.target.closest('[data-reorder-handle]');
        if (!handle || drag || (e.pointerType === 'mouse' && e.button !== 0)) return;
        e.preventDefault();
        drag = { row: handle.closest('[data-reorder-item]'), pointerId: e.pointerId };
        drag.row.classList.add('is-dragging');
        handle.setPointerCapture(e.pointerId);
      });
      form.addEventListener('pointermove', (e) => {
        if (!drag || e.pointerId !== drag.pointerId) return;
        const all = visible();
        const i = all.indexOf(drag.row);
        const mid = (el) => el.getBoundingClientRect().top + el.offsetHeight / 2;
        if (all[i - 1] && e.clientY < mid(all[i - 1])) {
          all[i - 1].before(drag.row);
          renumber();
          drag.moved = true;
        } else if (all[i + 1] && e.clientY > mid(all[i + 1])) {
          all[i + 1].after(drag.row);
          renumber();
          drag.moved = true;
        }
      });
      const end = (e) => {
        if (!drag || e.pointerId !== drag.pointerId) return;
        drag.row.classList.remove('is-dragging');
        const moved = drag.moved;
        drag = null;
        if (moved) done(); // 動かさずに離しただけなら保存しない
      };
      form.addEventListener('pointerup', end);
      form.addEventListener('pointercancel', end);
      form.addEventListener('keydown', (e) => {
        const handle = e.target.closest('[data-reorder-handle]');
        if (!handle || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
        e.preventDefault();
        const row = handle.closest('[data-reorder-item]');
        const all = visible();
        const i = all.indexOf(row);
        if (e.key === 'ArrowUp' && all[i - 1]) all[i - 1].before(row);
        if (e.key === 'ArrowDown' && all[i + 1]) all[i + 1].after(row);
        renumber();
        handle.focus();
        done();
      });
    });
  };
  const setupRowDelete = () => {
    document.addEventListener('click', (ev) => {
      const btn = ev.target.closest('[data-row-del]');
      if (!btn) return;
      const form = btn.closest('form');
      const row = btn.closest('.sinfo-slot-edit, .sinfo-rate-row, .sinfo-edit__row');
      const sel = form && row && DEL_FIELD[form.elements.action?.value];
      const inputs = sel ? row.querySelectorAll(sel) : [];
      if (!inputs.length) return;
      inputs.forEach((input) => { input.value = ''; });
      row.hidden = true;
    });
  };

  // ===================================================================
  //  タブの中身を切り替える共通処理（apply の中で hidden を切り替える）
  //     ・枠（パネルの親）の高さを滑らかに変える → 下の内容が一瞬で飛ばない
  //     ・新しく見えた要素を下からふわっと出す。入れ子のときは一番外側だけ動かす（二重にかけると動きが重なる）
  //     ・連打されても、動いている途中の高さから次へつなぐ
  //     ・「動きを減らす」設定のときは切り替えるだけ
  // ===================================================================
  const switchPanels = (panelList, apply) => {
    const panels = [...panelList];
    const hiddenBefore = new Set(panels.filter((p) => p.hidden));
    // <table> の中身（tbody）は親の高さを動かせないので、表の外側を枠にする
    const frames = [...new Set(panels.map((p) => p.closest('table')?.parentElement ?? p.parentElement))];
    const heights = new Map(frames.map((f) => [f, f.offsetHeight]));
    // 動作中の高さは測り終えたので止める（止めないと、切り替え後の本来の高さを測れない）
    frames.forEach((f) => {
      f.getAnimations().forEach((a) => { if (a.id === 'panel-height') a.cancel(); });
      f.classList.remove('is-panel-moving');
    });
    apply();
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const css = getComputedStyle(document.documentElement);
    const easing = css.getPropertyValue('--ease-out').trim() || 'ease-out';
    const slow = (parseFloat(css.getPropertyValue('--t-slow')) || 0.3) * 1000;
    const base = (parseFloat(css.getPropertyValue('--t-base')) || 0.2) * 1000;
    frames.forEach((f) => {
      const from = heights.get(f);
      const to = f.offsetHeight;
      if (Math.abs(from - to) < 1) return;
      f.classList.add('is-panel-moving'); // 動く間、枠の中身が高さに合わせて伸び縮みしないようにする（CSS）
      const a = f.animate([{ height: from + 'px', overflow: 'hidden' }, { height: to + 'px', overflow: 'hidden' }], { id: 'panel-height', duration: slow, easing });
      a.onfinish = () => f.classList.remove('is-panel-moving');
    });
    const shown = panels.filter((p) => hiddenBefore.has(p) && !p.hidden);
    shown.filter((p) => !shown.some((q) => q !== p && q.contains(p))).forEach((p) => {
      p.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: base, easing });
    });
  };

  // ===================================================================
  //  バンド練習 / 個人練習のタブ（料金表とパック専用）
  //     ・PHP が作った #studio-target-tabs（ラジオボタン）が切り替わったら、
  //       data-tab-panel="band|solo" を持つ要素（料金表・パックの行）のうち、選ばれていない方を hidden で隠す
  //     ・data-tab-has="band solo" を持つセクション（編集できない人のパック欄）は、選んだ対象が含まれないとき隠す
  //     ・料金シミュレーションには連動しない（シミュレーションは専用の切り替え name="mode" を持つ。setupSim が担当）
  //     ・両方の料金があるスタジオだけ #studio-target-tabs が出るので、無ければ何もしない
  // ===================================================================
  const setupTargetTabs = () => {
    const apply = (target) => {
      // dataset.tabPanel = HTML の data-tab-panel 属性（data- のあとのハイフン区切りは、JS ではキャメルケースになる）
      document.querySelectorAll('[data-tab-panel]').forEach((p) => {
        p.hidden = p.dataset.tabPanel !== target;
      });
      document.querySelectorAll('[data-tab-has]').forEach((s) => {
        s.hidden = !s.dataset.tabHas.split(' ').includes(target);
      });
    };
    /** 今選ばれているタブに表示を合わせる（動きなし） */
    const sync = () => {
      const on = document.querySelector('#studio-target-tabs input[name="tab_target"]:checked');
      if (on) apply(on.value);
    };

    // document で待ち受ける（イベントの委任）。自動保存（10.）でカードの表示が差し替わっても、タブが新しくなるだけで動く
    document.addEventListener('change', (e) => {
      if (e.target.name !== 'tab_target') return;
      switchPanels(document.querySelectorAll('[data-tab-panel], [data-tab-has]'), () => apply(e.target.value));
    });
    // ブラウザの「戻る」などでラジオの選択だけ復元されたときにも、表示を合わせておく
    sync();
    // 自動保存でカードの表示を差し替えたあと: 新しい要素は初期の状態（バンドが開いている）で来るので、選ばれているタブに合わせ直す
    document.addEventListener('studio:display-refreshed', sync);
  };

  // ===================================================================
  //  レンタルのジャンルのタブ（レンタルが10個を超えたスタジオだけ PHP がタブを出す）
  //     ・タブ（.sinfo-rent-tabs のラジオ）のすぐ後ろの箱の中で、data-rent-genre が選んだジャンルの行だけ見せる
  //     ・一覧（#rentals）と料金シミュレーションのレンタル欄は、ラジオの name が別なので別々に選べる
  //     ・document で待ち受ける（自動保存でカードが差し替わっても動く）
  // ===================================================================
  const setupRentalTabs = () => {
    document.addEventListener('change', (e) => {
      if (!e.target.matches('[data-rent-tab]')) return;
      const box = e.target.closest('.sinfo-rent-tabs')?.nextElementSibling;
      if (!box) return;
      box.querySelectorAll('[data-rent-genre]').forEach((row) => {
        row.hidden = row.dataset.rentGenre !== e.target.value;
      });
    });
  };

  // ===================================================================
  //  個人練習の料金表: 人数チップ（1人・2人 …）で表を切り替える
  //     ・PHP が人数ごとに <tbody data-ppl="n"> を作っている。選んだ人数以外の tbody を hidden で隠す
  //     ・data-ppl-has="1 2" を持つ部屋のブロックは、選んだ人数の料金が無ければ見出しごと隠す
  //     ・チップ（name="solo_ppl"）が無いページでは、change が来ないので何もしない
  // ===================================================================
  const setupSoloPeople = () => {
    const apply = (n) => {
      document.querySelectorAll('[data-ppl]').forEach((t) => {
        t.hidden = t.dataset.ppl !== n;
      });
      document.querySelectorAll('[data-ppl-has]').forEach((g) => {
        g.hidden = !g.dataset.pplHas.split(' ').includes(n);
      });
    };
    // document 全体で change を聞く（イベントの委任）。チップがどこにあっても1か所で受けられる
    document.addEventListener('change', (e) => {
      if (e.target.name !== 'solo_ppl') return;
      const n = e.target.value;
      switchPanels(document.querySelectorAll('[data-ppl], [data-ppl-has]'), () => apply(n));
    });
    // 自動保存（10.）でカードの表示を差し替えたあと: 選ばれている人数に表を合わせ直す（動きなし）
    document.addEventListener('studio:display-refreshed', () => {
      const on = document.querySelector('input[name="solo_ppl"]:checked');
      if (on) apply(on.value);
    });
  };

  // ===================================================================
  //  パック: 使える長さのタブ（3時間・4時間 …）で表を切り替える
  //     ・PHP が長さごとに [data-pack-len] の表を作っている。同じ .sinfo-pack-target の中で、選んだ長さ以外を hidden で隠す
  // ===================================================================
  const setupPackLen = () => {
    const apply = (radio) => {
      radio.closest('.sinfo-pack-target').querySelectorAll('[data-pack-len]').forEach((t) => {
        t.hidden = t.dataset.packLen !== radio.value;
      });
    };
    document.addEventListener('change', (e) => {
      if (!e.target.name?.startsWith('pack_len_')) return;
      switchPanels(e.target.closest('.sinfo-pack-target').querySelectorAll('[data-pack-len]'), () => apply(e.target));
    });
    // ブラウザの「戻る」などでラジオの選択だけ復元されたときにも、表示を合わせておく
    document.querySelectorAll('input[name^="pack_len_"]:checked').forEach(apply);
    // 自動保存（10.）でパックの表示を差し替えたあとも同じ（選ばれている長さに表を合わせ直す）
    document.addEventListener('studio:display-refreshed', () => {
      document.querySelectorAll('input[name^="pack_len_"]:checked').forEach(apply);
    });
  };

  // ===================================================================
  //  一覧の料金比較（#studio-list-sim）: 1つの条件で全スタジオを計算し、カードに最安の部屋を出して安い順に並べる
  //     ・開いている間だけ計算する。閉じると元の並びに戻す
  //     ・広さの絞り込み（#studio-filter）を満たす部屋だけで比べる
  //     ・割引スイッチ ON: 選んだ曜日に使える区分のうち、いちばん安くなるものを使う
  //     ・料金が足りない時間がある部屋は、合計が正しく出ないので候補から外す。候補が無い店は「料金未登録」で一番下
  // ===================================================================
  const setupListSim = () => {
    const box = document.getElementById('studio-list-sim');
    const all = readJson('studio-sim-all');
    if (!box || !all) return;
    const form = box.querySelector('form');
    const list = document.querySelector('.sinfo-list');
    const items = [...list.children]; // 元の並び（閉じたときに戻す）
    const minArea = document.querySelector('#studio-filter input[name="size"]');
    const engines = new Map(); // スタジオ id → 計算の部品（1回作って使い回す）

    /** 部屋の開始・時間の決まりに合わせる（詳細ページの applyRoomRule と同じ） */
    const fit = (room, start, dur) => {
      if (!room) return { start, dur };
      const zs = room.zs ?? !room.hs;
      // 30分始まりだけの部屋は、30分単位の予約でも開始は :30 だけ（:00 だと内訳が :00 で切れる）/ 両方開始できる部屋は 30分刻みのまま
      const s = room.hs && zs ? start - (start % 30) : start - (start % 60) + (room.hs ? 30 : 0);
      return { start: s, dur: room.hu ? dur : dur - (dur % 60) };
    };

    /** 1つのスタジオの最安（無ければ null） */
    const best = (id, c) => {
      const data = all[id];
      if (!engines.has(id)) engines.set(id, simEngine(data));
      const { calc, dayOk } = engines.get(id);
      const rooms = data.rooms.length ? data.rooms : [null];
      const discs = c.disc ? [{ id: 0 }, ...data.discounts.filter((d) => dayOk(d.day, c.day))] : [{ id: 0 }];
      let top = null;
      rooms.forEach((room) => {
        if (c.area > 0 && !(room?.a >= c.area)) return; // 広さ不明の部屋は、絞り込み中は候補にしない（絞り込みと同じ考え方）
        const { start, dur } = fit(room, c.start, c.dur);
        const v = { ppl: c.ppl, room: room ? room.id : 0, day: c.day, start, rentals: [] };
        discs.forEach((d) => {
          const r = calc(v, d.id, dur);
          if (r.missing || (!r.pack && !r.normal.segs.length)) return;
          if (!top || r.total < top.total) top = { total: r.total, room, start, dur, disc: d.name || '', pack: r.pack?.name || '' };
        });
      });
      return top;
    };

    // ---- カードの動き（FLIP = 動かす前の位置を覚え、並べ替えたあと「元の位置 → 今の位置」へ滑らせる）----
    //   時間・イージングは CSS の変数（--t-slow / --ease-out）から読む → 他の動きと同じ速さにそろう
    const reduce = matchMedia('(prefers-reduced-motion: reduce)'); // 「動きを減らす」設定のときは滑らせない
    const snap = () => new Map(items.map((li) => [li, li.getBoundingClientRect().top]));
    const glide = (before) => {
      if (reduce.matches) return;
      const css = getComputedStyle(document.documentElement);
      const duration = (parseFloat(css.getPropertyValue('--t-slow')) || 0.3) * 1000;
      const easing = css.getPropertyValue('--ease-out').trim() || 'ease-out';
      items.forEach((li) => {
        if (li.hidden) return;
        const dy = before.get(li) - li.getBoundingClientRect().top;
        if (Math.abs(dy) >= 1) li.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration, easing });
      });
    };

    const render = () => {
      const before = snap();
      const fd = new FormData(form);
      const solo = fd.get('mode') === 'solo';
      const people = Math.max(1, Number(fd.get('people')) || 1);
      const c = {
        ppl: solo ? people : 0, day: Number(fd.get('day')), start: Number(fd.get('start')), dur: Number(fd.get('dur')),
        disc: fd.get('disc') === '1', area: Math.max(0, Number(minArea?.value) || 0),
      };
      const rows = items.map((li, i) => {
        const card = li.querySelector('[data-studio-id]');
        const out = card.querySelector('[data-list-sim]');
        const b = best(card.dataset.studioId, c);
        out.hidden = false;
        out.replaceChildren();
        if (b) {
          const per = el('span', 'sinfo-list__per', '1人 ');
          per.append(el('strong', '', yen(Math.ceil(b.total / people))));
          out.append(el('strong', 'sinfo-list__yen', yen(b.total)), per);
          if (b.room) out.append(el('span', 'muted', b.room.name + (b.room.sz ? ' ' + b.room.sz : '')));
          if (b.start !== c.start || b.dur !== c.dur) out.append(el('span', 'muted', clock(b.start) + '〜 ' + b.dur / 60 + '時間'));
          if (b.disc) out.append(el('span', 'tag tag--plan', b.disc));
          if (b.pack) out.append(el('span', 'muted', b.pack));
        } else {
          out.append(el('span', 'muted', '料金未登録'));
        }
        return { li, i, total: b ? b.total : Infinity };
      });
      rows.sort((a, b) => a.total - b.total || a.i - b.i);
      list.append(...rows.map((r) => r.li)); // append = 既にある要素は移動になる
      glide(before);
    };

    const clear = () => {
      const before = snap();
      list.querySelectorAll('[data-list-sim]').forEach((o) => { o.hidden = true; });
      list.append(...items);
      glide(before);
    };
    const update = () => {
      list.classList.remove('is-enter'); // 読み込み時の登場アニメは1回だけ（要素を動かすと CSS のアニメが最初からやり直しになるため）
      if (box.open) render();
      else clear();
    };

    box.addEventListener('toggle', update);
    // バンド ⇔ 個人の切り替えは、下で人数を直してから計算する（先に計算すると、古い人数で一瞬だけ並びが変わってしまう）
    //   人数の変更は、広さの絞り込みを書き換えて（→ minArea の input で update が走る）から計算する。ここでは update しない
    const notMode = (e) => { if (e.target.name !== 'mode' && e.target.name !== 'people') update(); };
    form.addEventListener('input', notMode);
    form.addEventListener('change', notMode);
    minArea?.addEventListener('input', () => { showFilter(); update(); });
    // バンド ⇔ 個人を切り替えたら人数をそれっぽい値に（詳細ページの試算と同じ。手で変えた人数は覚えておいて戻す）
    const people = form.elements.people;
    // 人数 → 目安の広さ（帖）。絞り込み側の「目安◯人」の表（setupFilter）の逆引き。個人練習・2人以下は広さで絞らない（0 = 表示もしない）
    const joFor = (n) => (form.elements.mode.value === 'solo' ? 0 : n <= 2 ? 0 : n === 3 ? 8 : n === 4 ? 10 : n <= 6 ? 12 : n === 7 ? 15 : 20);
    const filterNote = form.querySelector('[data-sim-filter]'); // 人数の下の「絞り込み：◯帖以上」
    function showFilter() {
      const v = Math.max(0, Number(minArea?.value) || 0);
      if (filterNote) {
        filterNote.hidden = !(v > 0);
        filterNote.textContent = '絞り込み：' + v + '帖以上';
      }
    }
    const syncArea = () => {
      if (!minArea) return;
      const jo = String(joFor(Math.max(1, Number(people.value) || 1)));
      if (minArea.value !== jo) {
        minArea.value = jo;
        minArea.dispatchEvent(new Event('input', { bubbles: true })); // 絞り込み（setupFilter）と、ここの再計算が動く
      }
    };
    people.addEventListener('input', syncArea);
    showFilter();
    const remembered = { band: people.value, solo: '1' };
    let lastMode = 'band';
    form.addEventListener('change', (e) => {
      if (e.target.name !== 'mode') return;
      remembered[lastMode] = people.value;
      lastMode = e.target.value;
      people.value = remembered[lastMode];
      syncArea();
      showFilter();
      update();
    });
  };

  // ===================================================================
  //  料金の編集: 2段のタブ（対象 / 曜日）と 割引の一括入力
  //     ・対象（rate_target）と曜日（rate_day_対象）から [data-rate-panel="対象|曜日"] を選ぶ。隠れたタブの欄も一緒に保存される
  //     ・隠れた欄に不正な値があると、ブラウザは欄を見せられず送信を止めるので、その欄のタブを開く
  //     ・保存後は開いていたタブのまま、フォームを開き直す（sessionStorage は1回使ったら消す。使えない環境では先頭のタブのまま）
  //     ・「反映」は開いているタブの、料金が入っている部屋の割引欄を書き換える（保存を押すまで DB は変わらない）
  // ===================================================================
  const setupRateTabs = () => {
    const tools = document.querySelector('[data-rate-tools]');
    if (!tools) return;
    const form = tools.parentElement.querySelector('form.sinfo-rate-edit');
    // 自動保存（10.）でフォームの中身が差し替わるので、パネルは使うたびに探し直す（最初に1回だけ集めない）
    const panels = () => form.querySelectorAll('[data-rate-panel]');
    const radio = (name, value) => tools.querySelector(`input[name="${name}"][value="${value}"]`);

    /** ラジオの選択に合わせて、曜日のタブとパネルを出し分ける */
    const sync = () => {
      const target = tools.querySelector('input[name="rate_target"]:checked').value;
      tools.querySelectorAll('[data-rate-days]').forEach((d) => { d.hidden = d.dataset.rateDays !== target; });
      const day = tools.querySelector(`input[name="rate_day_${target}"]:checked`)?.value;
      const key = target === 'new' ? 'new' : target + '|' + day;
      panels().forEach((p) => { p.hidden = p.dataset.ratePanel !== key; });
    };
    /** 'new' か '対象|曜日' のタブを開く。そのタブが無ければ false */
    const open = (key) => {
      const [target, day] = key.split('|');
      const t = radio('rate_target', target);
      const d = target === 'new' ? t : radio('rate_day_' + target, day);
      if (!t || !d) return false;
      t.checked = true;
      d.checked = true;
      sync();
      document.dispatchEvent(new Event('seg:refresh')); // change が出ない切り替えなので、選択の帯に伝える（setupSegSlide）
      return true;
    };

    tools.addEventListener('change', (e) => {
      if (e.target.name.startsWith('rate_')) switchPanels([...panels(), ...tools.querySelectorAll('[data-rate-days]')], sync);
    });
    form.addEventListener('invalid', (e) => {
      const panel = e.target.closest('[data-rate-panel]');
      if (panel?.hidden) open(panel.dataset.ratePanel);
    }, true); // invalid は親へ伝わらないので、capture（外側から内側へ）で受ける

    const storeKey = 'rateEdit:' + tools.dataset.studio;
    form.addEventListener('submit', () => {
      try {
        sessionStorage.setItem(storeKey, form.querySelector('[data-rate-panel]:not([hidden])').dataset.ratePanel);
      } catch (e) { /* 保存できなくても、先頭のタブで開くだけ */ }
    });

    tools.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-rate-bulk] button');
      if (!btn) return;
      const bulk = btn.closest('[data-rate-bulk]');
      const kind = bulk.querySelector('select').value;
      const input = bulk.querySelector('input');
      input.max = kind === '2' ? '100' : '999999'; // ％引きは100まで（サーバー側と同じ）
      if (input.value === '' || !input.reportValidity()) return;
      const did = bulk.dataset.rateBulk;
      form.querySelectorAll('[data-rate-panel]:not([hidden]) .sinfo-rate-room:not(.sinfo-rate-room--head)').forEach((room) => {
        if (room.querySelector('[data-price]').value === '') return;
        room.querySelector(`select[name$="[dk][${did}]"]`).value = kind;
        room.querySelector(`input[name$="[dv][${did}]"]`).value = input.value;
      });
      form.dispatchEvent(new Event('change', { bubbles: true })); // 値を JS で入れたので、自動保存（10.）にも知らせる
    });

    // 自動保存（10.）が新しい行の番号を取り込むためにフォームの中身を差し替えたとき:
    //   上のタブ（対象・曜日。フォームの外にある）も新しい見出しに合わせて作り直し、開いていたタブを開き直す
    let keep = null;
    form.addEventListener('autosave:before-replace', () => {
      keep = form.querySelector('[data-rate-panel]:not([hidden])')?.dataset.ratePanel ?? null;
    });
    form.addEventListener('autosave:replaced', (e) => {
      const next = e.detail.doc.querySelector('[data-rate-tools]');
      if (next) tools.innerHTML = next.innerHTML;
      if (!(keep && open(keep))) sync(); // その見出しが無くなっていたら先頭のタブ
    });
    // ブラウザの「戻る」などでラジオの選択だけ復元されたときにも、表示を合わせておく
    sync();

    // 保存の直後: 開いていたタブとフォームを元に戻す
    let saved = null;
    try {
      saved = sessionStorage.getItem(storeKey);
      sessionStorage.removeItem(storeKey);
    } catch (e) { /* 読めなければ何もしない */ }
    if (saved) {
      open(saved);
      tools.closest('details').open = true;
      // URL の #rates へのジャンプが読み込みの最後に走るので、そのあとにタブの位置へ動かす
      const toTools = () => setTimeout(() => tools.scrollIntoView({ behavior: 'instant' }), 0);
      if (document.readyState === 'complete') toTools();
      else window.addEventListener('load', toTools, { once: true });
    }
  };

  // ===================================================================
  //  タブ・ボタン列（.sinfo-seg）の選択の帯を滑らせる
  //     ・選ばれている項目（label）の位置と幅を --seg-x / --seg-w として列に入れる。CSS の ::before（赤い帯）がそこへ動く
  //     ・列が隠れているとき（閉じた <details> の中・hidden のタブなど）は幅が 0 なので、表示されて大きさが変わったとき（ResizeObserver）にもう一度測る
  //     ・最初の配置では動かさない。測れた次のフレームで is-ready を付けて、それ以降の切り替えだけ動かす
  //     ・折り返す列（--disc）は縦の位置と高さ（--seg-y / --seg-h）も入れて、行をまたいで動かす / ラジオが無い列は対象外
  // ===================================================================
  const setupSegSlide = () => {
    const place = (seg) => {
      const on = seg.querySelector('input:checked')?.closest('label');
      if (!on || !on.offsetWidth) return; // 隠れているときは測れない
      // 先に has-pill（= position: relative）を付けてから測る。付ける前は offsetLeft の基準が列ではなく外側のカードになり、位置がずれる
      const first = !seg.classList.contains('has-pill');
      if (first) seg.classList.add('has-pill');
      seg.style.setProperty('--seg-x', on.offsetLeft + 'px'); // offsetLeft = 列（position: relative）の左端からの距離
      seg.style.setProperty('--seg-y', on.offsetTop + 'px');
      seg.style.setProperty('--seg-w', on.offsetWidth + 'px');
      seg.style.setProperty('--seg-h', on.offsetHeight + 'px');
      if (first) requestAnimationFrame(() => requestAnimationFrame(() => seg.classList.add('is-ready')));
    };
    const isRadioSeg = (s) => !!s.querySelector('input[type="radio"]');
    const watch = new ResizeObserver((entries) => entries.forEach((e) => place(e.target)));
    const watched = new WeakSet(); // もう見張っている列（同じ列を2回見張らない。WeakSet = 消えた列は自動で忘れる）
    // ページにある列を全部測る。自動保存（10.）でカードの表示が差し替わると新しい列が増えるので、そのたびに呼ぶ
    const scan = () => document.querySelectorAll('.sinfo-seg').forEach((s) => {
      if (!isRadioSeg(s)) return;
      if (!watched.has(s)) {
        watched.add(s);
        watch.observe(s);
      }
      place(s);
    });
    scan();
    document.addEventListener('change', (e) => {
      const seg = e.target.closest?.('.sinfo-seg');
      if (seg && isRadioSeg(seg)) place(seg);
    });
    document.addEventListener('seg:refresh', scan);
    document.addEventListener('studio:display-refreshed', scan);
  };

  // ===================================================================
  //  10. 自動保存（編集フォーム）
  //     PHP: studio_info.php の <form data-autosave> と studio_save_button()。サーバーの処理は今までの「保存」ボタンと同じ
  //     （検証 → 保存 → flash → redirect）で、JS が fetch で POST して、リダイレクト先の画面（GET）の HTML を受け取る。
  //
  //  ■ いつ送るか
  //     ・入力（input）… 1.5 秒まとめてから / 選択・チェック（change）… 0.3 秒まとめてから
  //     ・削除ボタン（ゴミ箱・×）・並び替えの直後 / Enter キー / フォームから離れたとき / ページを離れる・隠すとき
  //     ・同じフォームの保存は同時に1つだけ。送っている間にまた変わったら、終わってからもう1回送る
  //     ・全フォームの保存を1列に並べて送る（サーバーの flash は次の GET で消えるので、同時に走らせると別のフォームの結果が混ざる）
  //
  //  ■ 送らない（保留）
  //     ・既存の行（id > 0）の主な欄（DEL_FIELD。名前・料金など）が空 … サーバーは「空 = 削除」と読むので、入力の途中で消えないよう止める
  //       （行の削除はゴミ箱 / × のボタンだけ。押すと行が隠れるので、保留にならず保存される）
  //     ・必須・形式・範囲のエラー（checkValidity。吹き出しは出さない）/ 「新しいエリアを作る」で名前が空
  //  ■ 新しい行（id が 0 で主な欄に中身がある行）
  //     ・入力の途中（名前だけ・料金だけ）で保存するとエラーになるので、フォームから離れる・開閉を閉じる・ページを離れるまで待つ
  //     ・保存したら、フォームの中身をサーバーの最新に差し替える。新しい行に DB の番号（id）が入る
  //       （差し替えないと、次の保存で同じ行がもう1つ追加されてしまう）。送っている間はフォームを触れなくする（inert）
  //  ■ 保存したあと
  //     ・カードの読み取り用の表示（details.sinfo-edit 以外）をサーバーの最新に差し替える。選択中のタブ・人数・長さは覚えて戻す
  //     ・部屋・割引区分を変えたときは、それを使う料金・パック・機材のフォームも差し替える（行・列が変わるため）
  //     ・編集履歴の一覧も差し替える
  //  ■ 状態のアイコン（保存済み・保存中・未保存・空欄・失敗）は data-tip で説明する（押しても何も起きない表示なので）
  // ===================================================================
  const setupAutosave = () => {
    const forms = [...document.querySelectorAll('form[data-autosave]')];
    if (!forms.length) return;

    // 状態 → [アイコン名, 説明]。アイコン名は lib/icon_names.php に入れてある
    const STATES = {
      saved: ['cloud_done', '保存済み'],
      saving: ['sync', '保存中'],
      dirty: ['pending', '未保存'],
      held: ['warning', '空欄があるため未保存'],
      error: ['cloud_off', '保存失敗'],
    };
    // 保存したフォーム → 読み取り用の表示を差し替えるカード（'info' = id の無い基本情報のカード / 他は id）
    //   部屋は料金・パック・機材の表示（部屋名）、割引区分は料金・パックの表示（列）に出る
    const AFFECT = {
      save_info: ['info'], save_rooms: ['rates', 'packs', 'equipment'], save_discounts: ['rates', 'packs'],
      save_rates: ['rates'], save_packs: ['packs'], save_rentals: ['rentals'], save_equipment: ['equipment'],
    };
    // 保存したフォーム → 中身（行・列）が変わるので差し替えるほかのフォーム
    const DEPENDENTS = { save_rooms: ['save_rates', 'save_packs', 'save_equipment'], save_discounts: ['save_rates', 'save_packs'] };

    const states = new Map(); // フォーム → そのフォームの状態

    // ---- 小さな道具 ----
    const notify = (msg) => {
      // flashToast は app.js の共通のお知らせ（画面上部）。読み込めていなければ alert
      if (typeof flashToast === 'function') flashToast(msg, 'error');
      else alert(msg);
    };
    const actionOf = (form) => form.elements.action?.value ?? '';

    /** アイコンと説明を、今の状態に合わせる（フォームの中身が差し替わったあとにも呼ぶ） */
    const paint = (st) => {
      const box = st.form.querySelector('[data-autosave-status]');
      if (!box) return;
      const [name, text] = STATES[st.state];
      box.dataset.state = st.state;
      box.dataset.tip = text; // 共通のポップアップ（app_ui.js の setupTip）が読む
      box.setAttribute('aria-label', text);
      const icon = box.querySelector('.icon');
      if (icon) icon.textContent = name;
    };
    const setState = (st, state) => {
      st.state = state;
      paint(st);
    };

    /**
     * フォームの行を調べる（DEL_FIELD の「主な欄」を手がかりにする）
     *   held  … 既存の行（id > 0）なのに主な欄が空 = 送ると削除になってしまう
     *   fresh … 新しい行（id = 0）で主な欄に中身がある = 送ると追加される
     *   削除ボタンで隠した行（setupRowDelete が欄を空にして hidden にする）は、どちらでもない（削除したい行なので）
     */
    const rowsOf = (form) => {
      const out = { held: false, fresh: false };
      const sel = DEL_FIELD[actionOf(form)];
      if (!sel) return out;
      form.querySelectorAll(sel).forEach((input) => {
        if (input.closest('.sinfo-slot-edit, .sinfo-rate-row, .sinfo-edit__row')?.hidden) return; // 削除ボタンで隠した行（その行自身が hidden のとき）
        const row = input.closest('.sinfo-rate-room, .sinfo-edit__row'); // 料金表は部屋ごとの行 / ほかは1行
        const id = row?.querySelector('input[type="hidden"][name="id[]"], input[type="hidden"][name$="[id]"]');
        if (!id) return;
        const filled = [...row.querySelectorAll(sel)].some((el) => el.value.trim() !== ''); // 主な欄が2つ以上ある行（機材のメーカー・型番）は、どれか1つでも入っていればよい
        if (Number(id.value) > 0) {
          if (!filled) out.held = true;
        } else if (filled) {
          out.fresh = true;
        }
      });
      return out;
    };

    /** 送らずに待つ理由があるか（true = 保留）。吹き出しは出さない（checkValidity / reportValidity は使わない: invalid イベントでタブが切り替わってしまう） */
    const isHeld = (form) => {
      if ([...form.elements].some((el) => el.willValidate && !el.validity.valid)) return true;
      if (rowsOf(form).held) return true;
      // エリアの「＋ 新しいエリアを作る」を選んだのに、名前が空
      return [...form.querySelectorAll('select[data-new-select]')].some((sel) => {
        if (sel.value !== sel.dataset.newSelect) return false;
        return !(sel.closest('.sinfo-field')?.querySelector('[data-new-input]')?.value.trim());
      });
    };

    // ---- 送る ----
    let chain = Promise.resolve(); // 全フォームの保存を1列に並べる
    const enqueue = (job) => {
      const p = chain.then(job);
      chain = p.catch(() => {});
      return p;
    };

    /** POST して、リダイレクト先の画面を受け取る。成功 = { doc } / 失敗 = { error: 画面に出す文 } */
    const post = async (body, keepalive) => {
      try {
        const res = await fetch(location.pathname + location.search, {
          method: 'POST', body, headers: { 'X-Autosave': '1' }, credentials: 'same-origin', keepalive,
        });
        if (!res.ok) return { error: '保存できませんでした' };
        // fetch は 302 の移動先（GET）まで自動でたどる。その画面に、保存の結果の flash（エラーなら .flash--error）が載っている
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        if (!doc.querySelector('.sinfo-section')) return { error: '保存できませんでした' }; // ログイン切れなどで別の画面が返った
        const err = doc.querySelector('.flash--error');
        return err ? { error: err.textContent.trim() } : { doc };
      } catch (e) {
        return { error: '保存できませんでした' };
      }
    };

    /** 同じ action のフォームの中で、$form と同じ順番のものを doc から探す（action が同じフォームが複数あっても取り違えない） */
    const matchForm = (doc, form) => {
      const same = (root) => [...root.querySelectorAll('form[data-autosave]')].filter((f) => actionOf(f) === actionOf(form));
      return same(doc)[same(document).indexOf(form)] ?? null;
    };

    /** フォームの中身をサーバーの最新に差し替える（新しい行に DB の番号が入る / 部屋・割引区分の変更を反映する）。失敗 = false */
    const replaceForm = (st, doc) => {
      const next = matchForm(doc, st.form);
      if (!next) return false;
      st.form.dispatchEvent(new CustomEvent('autosave:before-replace', { bubbles: true }));
      st.form.className = next.className; // 割引区分の数で料金表の列の形（クラス）が変わる
      st.form.classList.add('is-autosave');
      st.form.innerHTML = next.innerHTML; // フォーム要素そのものは残す（付けた listener・setupReorder の仕込みが生きる）。<select> は app.js が独自の見た目にする
      paint(st);
      st.form.dispatchEvent(new CustomEvent('autosave:replaced', { bubbles: true, detail: { doc } }));
      return true;
    };

    /** カード（<section>）の読み取り用の表示を差し替える。編集の枠（details.sinfo-edit）は触らない */
    const cardOf = (root, key) => root.querySelector(key === 'info' ? 'section.sinfo-section:not([id])' : '#' + key);
    const swapCard = (card, next) => {
      const display = ':scope > :not(details.sinfo-edit)';
      // 選ばれているタブ（ラジオ）を覚えておいて、差し替えたあとに戻す
      const picked = new Map([...card.querySelectorAll(display + ' input[type="radio"]:checked')].map((r) => [r.name, r.value]));
      const olds = [...card.children].filter((n) => !n.matches('details.sinfo-edit'));
      const news = [...next.children].filter((n) => !n.matches('details.sinfo-edit'));
      const anchor = [...card.children].find((n) => n.matches('details.sinfo-edit')) ?? null;
      // 上から順に比べて、中身（HTML）が同じものは触らない（地図の <iframe> は、作り直すと読み込み直しになるので残す）
      news.forEach((n, i) => {
        if (olds[i]?.outerHTML === n.outerHTML) return;
        const fresh = document.importNode(n, true);
        if (olds[i]) olds[i].replaceWith(fresh);
        else card.insertBefore(fresh, anchor);
      });
      olds.slice(news.length).forEach((n) => n.remove());
      // タブを戻す（その値のタブがまだあるときだけ。無くなっていたら初期の選択のまま）
      const radios = [...card.querySelectorAll(display + ' input[type="radio"]')];
      picked.forEach((value, name) => {
        const hit = radios.find((r) => r.name === name && r.value === value);
        if (hit) hit.checked = true;
      });
    };

    /** 保存が成功したあと、ページの読み取り用の部分を最新にする（シミュレーション #sim は更新しない: 最初に1回だけ料金を読むため） */
    const refreshAfterSave = (st, doc) => {
      const action = actionOf(st.form);
      (AFFECT[action] ?? []).forEach((key) => {
        const card = cardOf(document, key);
        const next = cardOf(doc, key);
        if (card && next) swapCard(card, next);
      });
      if (action === 'save_info') {
        // 名前・おすすめ・深夜早朝は、ページの見出しとタブの題にも出る
        const h1 = document.querySelector('h1.display');
        const nextH1 = doc.querySelector('h1.display');
        if (h1 && nextH1) h1.innerHTML = nextH1.innerHTML;
        document.title = doc.title;
      }
      // 部屋・割引区分を変えたら、それを使うフォームも差し替える。入力の途中（未保存）のフォームは壊さず、そのまま残す
      (DEPENDENTS[action] ?? []).forEach((a) => {
        const other = [...states.values()].find((s) => actionOf(s.form) === a);
        if (other && !other.dirty && !other.saving) replaceForm(other, doc);
      });
      // 編集履歴（新しい行が増える）。一覧の中身だけ差し替える（開閉のアニメーションは <summary> に付いているので残す）
      const hist = document.getElementById('history');
      const nextHist = doc.getElementById('history');
      if (hist && nextHist) {
        const ul = hist.querySelector('ul');
        const nextUl = nextHist.querySelector('ul');
        if (ul && nextUl) ul.innerHTML = nextUl.innerHTML;
      } else if (!hist && nextHist) {
        (document.querySelector('form.sinfo-delete') ?? document.querySelector('main'))?.before(document.importNode(nextHist, true));
      }
      document.dispatchEvent(new Event('studio:display-refreshed')); // タブ・人数・長さ・選択の帯を合わせ直す
    };

    /** 送り終わったときの処理 */
    const finish = (st, seq, fresh, result) => {
      st.saving = false;
      st.form.inert = false;
      if (result.error) {
        setState(st, 'error');
        notify(result.error);
      } else {
        st.dirty = st.seq !== seq; // 送っている間に増えた入力があれば、まだ未保存
        try {
          if (fresh && !replaceForm(st, result.doc)) {
            // 新しい行の番号を受け取れなかった。このまま保存を続けると同じ行が重複するので、ここで止める
            st.dead = true;
            setState(st, 'error');
            notify('ページを再読み込みしてください');
            return;
          }
          refreshAfterSave(st, result.doc);
        } catch (e) {
          console.error('studio autosave refresh', e); // 表示の差し替えに失敗しても、保存そのものは終わっている
        }
        setState(st, st.dirty ? 'dirty' : 'saved');
      }
      if (st.dirty && st.seq !== seq && !st.timer) attempt(st); // 送っている間に変わった分を送る
    };

    const send = (st, keepalive = false) => {
      const seq = st.seq; // この送信に入る内容は、ここまでの入力
      const fresh = rowsOf(st.form).fresh;
      st.saving = true;
      setState(st, 'saving');
      if (fresh) st.form.inert = true; // 新しい行を送る間は触らせない（差し替えで入力が消えないように）
      const body = new FormData(st.form);
      const job = async () => finish(st, seq, fresh, await post(body, keepalive));
      return keepalive ? job() : enqueue(job); // ページを離れるときは、列に並ばず今すぐ（keepalive = 離れたあとも送り切る）
    };

    /** 送ってよいか見て、よければ送る。leave = フォームを離れた・閉じた（新しい行も待たずに送る） */
    const attempt = (st, leave = false) => {
      clearTimeout(st.timer);
      st.timer = 0;
      if (!st.dirty || st.saving || st.dead) return; // 送っている最中は、終わったあと（finish）で続きを見る
      if (isHeld(st.form)) {
        setState(st, 'held');
        return;
      }
      if (!leave && rowsOf(st.form).fresh) {
        setState(st, 'dirty'); // 新しい行は、フォームを離れるまで待つ
        return;
      }
      send(st);
    };

    /** 変わったと記録して、delay ミリ秒あとに送る（続けて変わったら数え直す） */
    const touch = (st, delay) => {
      if (st.dead) return;
      st.dirty = true;
      st.seq++;
      if (!st.saving) setState(st, 'dirty');
      clearTimeout(st.timer);
      st.timer = setTimeout(() => {
        st.timer = 0;
        attempt(st);
      }, delay);
    };

    // ---- フォームごとの仕込み ----
    forms.forEach((form) => {
      const st = {
        form, state: 'saved', dirty: false, saving: false, dead: false, seq: 0, timer: 0,
        // 料金表は、フォームの外にあるタブ・一括入力も「同じフォームの中」とみなす（そこを触っても「離れた」ことにしない）
        aside: form.classList.contains('sinfo-rate-edit') ? form.parentElement.querySelector('[data-rate-tools]') : null,
      };
      states.set(form, st);
      form.classList.add('is-autosave'); // 「保存」ボタンを隠して、状態のアイコンを出す（CSS）

      form.addEventListener('input', () => touch(st, 1500));
      form.addEventListener('change', () => touch(st, 300));
      form.addEventListener('reorder-done', () => touch(st, 300)); // 並び替え（setupReorder）
      // Enter キー: ふつうの送信（画面が移動する）にせず、今すぐ自動保存にする。確認ダイアログ（data-confirm）も出さない
      //   setupRateTabs の submit（タブを覚える）より先に動くよう、setupAutosave を先に呼んである
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        e.stopImmediatePropagation();
        touch(st, 0);
        attempt(st, true); // Enter は「今すぐ保存したい」ので、新しい行も待たずに送る
      });
      // 行の削除（ゴミ箱）: 部屋・割引区分は、消える範囲が広いので確認を出す（今までは送信のときに出していた data-confirm）
      //   capture（外側から先に受ける）で止めると、setupRowDelete が動かず行も消えない
      form.addEventListener('click', (e) => {
        if (!e.target.closest('[data-row-del]') || !form.dataset.confirm) return;
        if (!confirm(form.dataset.confirm)) {
          e.preventDefault();
          e.stopImmediatePropagation();
        }
      }, true);
      // 削除ボタン: 行を隠す処理（setupRowDelete / setupRateEdit は document で受ける = ここより後）が済んでから保存する
      form.addEventListener('click', (e) => {
        if (e.target.closest('[data-row-del], [data-slot-del]')) setTimeout(() => touch(st, 0), 0);
      });
    });

    // ---- フォームを離れたとき・閉じたとき ----
    const flushLeave = (st) => {
      if (st.dirty && !st.saving) attempt(st, true);
    };
    const left = (target) => {
      if (!(target instanceof Element) || target.closest('.live-pop')) return; // 独自のプルダウンのリスト（body の直下に出る）は、フォームの一部
      states.forEach((st) => {
        if (!st.form.contains(target) && !st.aside?.contains(target)) flushLeave(st);
      });
    };
    document.addEventListener('focusin', (e) => left(e.target)); // Tab・クリックで別の所へ
    document.addEventListener('pointerdown', (e) => left(e.target), true); // 何もない所をクリックしたときは focusin が来ないので、こちらでも
    // 開閉（details）を閉じたとき。toggle は伝わらない（バブリングしない）ので capture で受ける
    document.addEventListener('toggle', (e) => {
      const d = e.target;
      if (!(d instanceof HTMLDetailsElement) || d.open) return;
      states.forEach((st) => { if (d.contains(st.form)) flushLeave(st); });
    }, true);

    // ---- ページを離れる・隠すとき ----
    //   待っている分は今すぐ送る（keepalive = ページが閉じても送り切る）。保留のフォームは送らない
    const flushAll = () => {
      states.forEach((st) => {
        if (!st.dirty || st.saving || st.dead || isHeld(st.form)) return;
        clearTimeout(st.timer);
        st.timer = 0;
        send(st, true);
      });
    };
    window.addEventListener('pagehide', flushAll);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushAll(); });
    // 保存できていない分が残っているとき（保留・待機中・送信中・失敗）は、ブラウザ標準の「離れますか？」を出す
    window.addEventListener('beforeunload', (e) => {
      if ([...states.values()].some((st) => st.dirty)) {
        e.preventDefault();
        e.returnValue = ''; // 古いブラウザは returnValue に何か入れると出す
      }
    });
  };

  // DOMContentLoaded を待たなくても、このファイルはページの最後で読むので要素はもう全部ある
  const mapApi = setupMap();
  setupGeo();
  setupStepper();
  setupRoomChips();
  setupRateEdit();
  setupAutosave(); // setupRateTabs より先（フォームの submit を先に受けて止めるため）
  setupRateTabs();
  setupRowDelete();
  setupReorder();
  setupAddRow();
  setupTimePair();
  setupHalfUnitAll();
  setupFilter(mapApi);
  setupTargetTabs();
  setupRentalTabs();
  setupSoloPeople();
  setupPackLen();
  setupSim();
  setupListSim();
  setupAi();
  setupSegSlide();
})();

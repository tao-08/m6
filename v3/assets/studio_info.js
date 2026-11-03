/* =====================================================================
 *  assets/studio_info.js — スタジオ情報（studio_list.php / studio_info.php）の動き
 * =====================================================================
 *  1. 地図（#studio-map）… Leaflet で OpenStreetMap を出し、#studio-pins の JSON からピンを立てる
 *  2. 座標の取得（[data-geo-lookup]）… 住所 → 緯度・経度（国土地理院の住所検索 API）
 *  3. 料金シミュレーション（#studio-sim）… 入力が変わるたびに合計・1人あたり・内訳・パック・+1時間延長を計算して表示
 *  4. ✨AIで入力（#studio-ai）… AI の提案をプレビューして、反映する項目を選ぶ
 *  5. ＋／－ボタン（[data-step]）… 数字入力をタッチで増減（シミュレーションの人数・一覧の広さ）
 *  6. 広さの絞り込み（#studio-filter）… 一覧のカードと地図のピンを「N帖以上の部屋がある店」だけにする
 *  7. 部屋のチェック欄（.sinfo-chips）… 「全部屋」と個別の部屋を同時に選ばせない
 *  8. 料金の編集（[data-rate-add]）… 見出し（対象・曜日）の＋で空の行を足す
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

  /** 0時からの分 → "H:MM"（24時を超えてもそのまま 25:00 のように出す。PHP の studio_fmt_min と同じ） */
  const hm = (min) => Math.floor(min / 60) + ':' + String(min % 60).padStart(2, '0');

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

    if (pins.length === 1) {
      map.setView([pins[0].lat, pins[0].lng], 16); // 1本だけ → 駅からの道がわかるくらいの拡大率
    } else {
      // fitBounds = 全部のピンが入るように拡大率と中心を自動で決める。padding = 端に余白
      map.fitBounds(L.featureGroup(markers).getBounds(), { padding: [30, 30], maxZoom: 16 });
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
      else if (shown.length === 1) map.setView(shown[0].getLatLng(), 16);
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
  //  6. 広さの絞り込み（スタジオ一覧）
  //     各カードの data-max-area（そのスタジオでいちばん広い部屋の帖数。不明は空）が、入力した帖数以上のものだけ残す
  //     0 = 絞り込みなし。広さが不明のスタジオは、絞り込み中は出さない（条件を満たすか分からないため）
  //     入力値は URL（?min=12）にも入れる → 絞り込んだ状態のまま URL を共有できる
  // ===================================================================
  const setupFilter = (mapApi) => {
    const box = document.getElementById('studio-filter');
    if (!box) return;
    const input = box.querySelector('input[type=number]');
    const count = box.querySelector('[data-filter-count]');
    const empty = document.querySelector('[data-filter-empty]');
    const cards = [...document.querySelectorAll('.sinfo-list [data-studio-id]')]; // [...NodeList] = 配列に変換

    const apply = () => {
      const min = Math.max(0, Number(input.value) || 0);
      const visible = new Set();
      cards.forEach((card) => {
        const max = card.dataset.maxArea === '' ? null : Number(card.dataset.maxArea);
        const ok = min <= 0 || (max !== null && max >= min);
        card.closest('li').hidden = !ok; // hidden 属性 = 表示から外す（HTML 標準）
        if (ok) visible.add(card.dataset.studioId);
      });
      count.textContent = min > 0 ? visible.size + ' / ' + cards.length : '';
      if (empty) empty.hidden = visible.size > 0;
      if (mapApi) mapApi.setVisible(visible);
      // history.replaceState = ページを移動せず URL だけ書き換える（戻るボタンの履歴は増やさない）
      const url = new URL(location.href);
      if (min > 0) url.searchParams.set('min', String(min));
      else url.searchParams.delete('min');
      history.replaceState(null, '', url);
    };

    input.addEventListener('input', apply);
    const first = Number(new URL(location.href).searchParams.get('min'));
    if (first > 0) input.value = String(Math.min(Number(input.max) || 100, first));
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
      const q = addr.value.trim();
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
  const setupSim = () => {
    const form = document.getElementById('studio-sim');
    const data = readJson('studio-sim-data');
    const out = document.getElementById('studio-sim-result');
    if (!form || !data || !out) return;

    const DAY = 24 * 60;
    // 料金表・パックは 22:00〜29:00 のように 24時を超えて書けるので、予約の時刻を 0 / -24h / +24h ずらして比べる
    const SHIFTS = [0, -DAY, DAY];

    /**
     * 料金表の行 r が、時刻 t（0時からの分）を含むか
     *   例) 予約 1:00（t=60）  と 料金 24:00〜30:00 → t+24h = 1500 が範囲内 → 一致
     *   例) 予約 23:00〜翌2:00 の 0:30 部分（t=1470）と 料金 0:00〜6:00 → t-24h = 30 が範囲内 → 一致
     */
    const covers = (r, t) => SHIFTS.some((d) => r.s <= t + d && t + d < r.e);

    /** 対象（ppl: 0 = バンド / n = 個人n人）・部屋・曜日が合う行だけにする（料金表・パック共通） */
    const matches = (r, v) => r.ppl === v.ppl && (r.rooms.length === 0 || r.rooms.includes(v.room)) && (r.day === v.day || r.day === 0);

    /**
     * 時刻 t に使う料金の行を1つ選ぶ。優先順位:
     *   ① 部屋を指定した料金（rooms に選んだ部屋が入っている）＞ 全部屋共通（rooms が空）
     *   ② 曜日の指定あり（平日 / 土日祝）＞ 全日（day = 0）
     *   sort で「点数が高い順」に並べて先頭を取る
     */
    const findRate = (v, t) => {
      const hits = data.rates.filter((r) => matches(r, v) && covers(r, t));
      const score = (r) => (r.rooms.length ? 2 : 0) + (r.day !== 0 ? 1 : 0);
      hits.sort((a, b) => score(b) - score(a));
      return hits[0] || null;
    };

    /**
     * 割引区分 disc（区分の id。0 = 割引なし）の料金があればその料金、無ければ通常の料金
     *   r.dp = {区分id: 料金}。その時間帯・パックに区分の料金が無ければ undefined → 通常料金
     */
    const priceOf = (r, disc) => (disc && r.dp[disc] != null ? r.dp[disc] : r.p);

    /** 区分 id → 区分 {id, name, day}（無ければ null）。day = 使える曜日 0 全日 / 1 平日のみ / 2 土日祝のみ */
    const discOf = (id) => data.discounts.find((d) => d.id === id) || null;
    const DAY_ONLY = ['', '平日のみ', '土日祝のみ'];

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

    /**
     * 通常料金（30分ごとに料金の行を探して足す）。同じ料金が続く所は1つの「区間」にまとめる
     *   disc = 使う割引区分の id（0 = なし。曜日で使えないときは呼ぶ側が 0 にして渡す）
     *   戻り値: { total, segs: [{ from, to, perHour, disc }], missing: 料金未登録の30分の数 }
     */
    const normalCost = (v, disc, dur) => {
      const segs = [];
      let total = 0;
      let missing = 0;
      for (let t = v.start; t < v.start + dur; t += 30) {
        const r = findRate(v, t);
        if (!r) {
          missing++;
          continue;
        }
        const perHour = priceOf(r, disc);
        total += perHour / 2; // 30分 = 1時間の半分
        const last = segs[segs.length - 1];
        if (last && last.to === t && last.perHour === perHour) {
          last.to = t + 30; // 直前の区間と同じ料金なら伸ばす
        } else {
          segs.push({ from: t, to: t + 30, perHour, disc: disc && r.dp[disc] != null }); // disc = この区間に割引が効いたか
        }
      }
      return { total, segs, missing };
    };

    /**
     * 使えるパックのうち、いちばん安いもの（無ければ null）
     *   使える条件: 対象・部屋・曜日が合う ＆ 予約の開始〜終了がパックの時間帯に収まる ＆ 予約の長さ ≦ 使える時間
     *   使える時間（min）が null のパックは「時間帯まるごと」= 時間帯の長さまで
     */
    const bestPack = (v, disc, dur) => {
      let best = null;
      data.packs.forEach((pk) => {
        if (!matches(pk, v)) return;
        const limit = pk.min ?? pk.e - pk.s; // ?? = 左が null / undefined のときだけ右を使う
        if (dur > limit) return;
        if (!SHIFTS.some((d) => pk.s <= v.start + d && v.start + dur + d <= pk.e)) return;
        const price = priceOf(pk, disc);
        if (!best || price < best.price) best = { name: pk.name, price, disc: disc && pk.dp[disc] != null };
      });
      return best;
    };

    /**
     * 合計の計算（部屋代 + レンタル）。dur を変えて呼べるので、延長したときの合計もこれで出す
     *   部屋代は「通常料金」と「パック」の安い方。通常料金に未登録の時間があるときは、パックがあればパックを使う
     */
    const calc = (v, disc, dur) => {
      const normal = normalCost(v, disc, dur);
      const pack = bestPack(v, disc, dur);
      const usePack = pack && (normal.missing > 0 || pack.price < normal.total);
      const room = usePack ? pack.price : normal.total;
      const rentals = v.rentals.filter((r) => r.qty > 0).map((r) => ({
        name: r.name,
        qty: r.qty,
        cost: r.p * r.qty * (r.hour ? dur / 60 : 1), // 1時間あたりのものは時間をかける
      }));
      const rentalTotal = rentals.reduce((sum, r) => sum + r.cost, 0); // reduce = 配列を1つの値（合計）にまとめる
      return {
        total: Math.round(room + rentalTotal),
        normal,
        pack: usePack ? pack : null,
        packSave: usePack && normal.missing === 0 ? Math.round(normal.total - pack.price) : 0,
        missing: usePack ? 0 : normal.missing,
        rentals,
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
      // ---- 割引の判定: 選んだ区分が、選んだ曜日（平日 / 土日祝）で使えるか ----
      //   区分の day が 0（全日）か、曜日と同じなら使える。使えなければ割引なし（0）で計算し、下に理由を出す
      const d = discOf(v.disc);
      const discOk = !!d && (d.day === 0 || d.day === v.day);
      const disc = discOk ? v.disc : 0;
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
        ul.append(line(r.pack.name + (r.pack.disc ? ' ' + d.name : ''), yen(r.pack.price), 'sinfo-sim__pack'));
      } else {
        r.normal.segs.forEach((s) => {
          const hours = (s.to - s.from) / 60;
          ul.append(line(hm(s.from) + '〜' + hm(s.to) + (s.disc ? ' ' + d.name : ''), yen(s.perHour) + '/h × ' + hours + 'h = ' + yen(s.perHour * hours)));
        });
      }
      r.rentals.forEach((x) => ul.append(line(x.name + ' × ' + x.qty, yen(x.cost))));
      out.append(ul);

      // ---- お得情報（パック・割引区分） ----
      if (r.packSave > 0) out.append(el('p', 'sinfo-sim__save', r.pack.name + 'で ' + yen(r.packSave) + ' お得'));
      if (disc) {
        const diff = calc(v, 0, v.dur).total - r.total; // 割引なしで計算した合計との差
        if (diff > 0) out.append(el('p', 'sinfo-sim__save', d.name + 'で ' + yen(diff) + ' お得'));
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

    // 日付を選んだら曜日（平日 / 土日祝）を自動で切り替える。祝日は判定しないので、手で「土日祝」を選ぶ
    const date = form.elements.date;
    date.addEventListener('change', () => {
      if (!date.value) return;
      const wd = new Date(date.value + 'T00:00:00').getDay(); // 0 = 日曜 … 6 = 土曜
      form.querySelector('input[name="day"][value="' + (wd === 0 || wd === 6 ? 2 : 1) + '"]').checked = true;
      render();
    });

    // バンド ⇔ 個人を切り替えたら人数をそれっぽい値に（バンド 5人 / 個人 1人）。手で変えた人数は覚えておいて戻す
    const people = form.elements.people;
    const remembered = { band: people.value, solo: '1' };
    let lastMode = form.elements.mode.value;
    form.addEventListener('change', (e) => {
      if (e.target.name !== 'mode') return;
      remembered[lastMode] = people.value;
      lastMode = e.target.value;
      people.value = remembered[lastMode];
    });

    // input イベント = 値が変わるたび（数字を打つたび）に発生。change より反応が早い
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    if (form.elements.mode.value === 'solo') people.value = '1'; // 個人練習の料金しか無いスタジオ
    render(); // 最初に1回表示
  };

  // ===================================================================
  //  4. ✨AIで入力（幹部と管理者）
  //     api_studio_ai.php に頼む → 提案をプレビュー → 項目ごとにチェックして「反映」（studio_info.php の apply_ai）
  //     提案の中身はサーバーのセッションにあるので、ここから送るのは「どの項目を使うか」のチェックだけ
  // ===================================================================
  const setupAi = () => {
    const box = document.getElementById('studio-ai');
    if (!box) return;
    const btn = box.querySelector('[data-ai-run]');
    const status = box.querySelector('[data-ai-status]');
    const form = document.getElementById('studio-ai-result');
    const body = form.querySelector('[data-ai-body]');

    const DAYS = ['全日', '平日', '土日祝'];
    const SECTIONS = { info: '基本情報', rooms: '部屋', discounts: '割引区分', rates: '料金', packs: 'パック', rentals: 'レンタル', equipment: '機材' };
    const INFO = { reserve_url: '予約ページ', website_url: 'サイト', address: '住所', access: '最寄り駅', phone: '電話', student_note: '割引の条件', note: 'メモ' };
    const DAY_ONLY = ['全日', '平日のみ', '土日祝のみ'];
    const target = (p) => (p == null ? 'バンド' : '個人' + p + '人');
    const where = (r) => [target(r.people), r.rooms.length ? r.rooms.join('・') : '全部屋', DAYS[r.day_type]].join('・');
    // 割引区分ごとの料金 r.discounts = {区分名: 料金} → 「（学割 ¥1,650・U20 ¥1,100）」
    const price = (r) => {
      const parts = Object.entries(r.discounts || {}).map(([name, p]) => name + ' ' + yen(p)); // entries = [[キー, 値], …]
      return yen(r.price) + (parts.length ? '（' + parts.join('・') + '）' : '');
    };

    /** 項目ごとに、プレビューの1行の文字を作る関数 */
    const LINES = {
      info: (p) => Object.keys(INFO).filter((k) => p.info[k]).map((k) => INFO[k] + ': ' + p.info[k]),
      discounts: (p) => p.discounts.map((d) => d.name + ': ' + DAY_ONLY[d.day_type]),
      rooms: (p) => p.rooms.map((r) => r.name + ' ' + [r.area_jo != null ? r.area_jo + '帖' : '', r.size_note || ''].filter(Boolean).join('・')),
      rates: (p) => p.rates.map((r) => where(r) + ' ' + hm(r.start_min) + '〜' + hm(r.end_min) + ' ' + price(r) + '/h'),
      packs: (p) => p.packs.map((r) => r.name + ': ' + where(r) + ' ' + hm(r.start_min) + '〜' + hm(r.end_min)
        + (r.minutes ? ' のうち ' + hm(r.minutes) : '') + ' ' + price(r)),
      rentals: (p) => p.rentals.map((r) => r.name + ' ' + yen(r.price) + (r.per_hour ? '/h' : '/回')),
      equipment: (p) => p.equipment.map((r) => (r.rooms.length ? r.rooms.join('・') + ' ' : '') + r.category + ': ' + r.model_name + (r.quantity !== 1 ? ' × ' + r.quantity : '')),
    };

    const show = (p, yenUsed) => {
      body.replaceChildren();
      if (!p.found) {
        body.append(el('p', 'sinfo-sim__warn', '情報が見つかりませんでした。サイトの URL を入れてから試すと見つかりやすくなります'));
      }
      let any = false;
      Object.keys(SECTIONS).forEach((key) => {
        const lines = LINES[key](p);
        if (!lines.length) return; // 提案が無い項目は出さない（反映もできない）
        any = true;
        // <details> で畳む。見出しのチェックボックスが「この項目を反映するか」
        const d = el('details', 'sinfo-ai__sec');
        const sum = el('summary');
        const label = el('label', 'sinfo-check');
        const cb = el('input');
        cb.type = 'checkbox';
        cb.name = 'sections[]';
        cb.value = key;
        cb.checked = true;
        label.append(cb, document.createTextNode(' ' + SECTIONS[key]));
        sum.append(label, el('span', 'muted small', lines.length + '件'));
        const ul = el('ul', 'sinfo-plain sinfo-ai__lines');
        lines.forEach((t) => ul.append(el('li', '', t)));
        d.append(sum, ul);
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
      if (!files.length) return;
      e.preventDefault();
      addImages(files);
    });

    btn.addEventListener('click', async () => {
      btn.disabled = true;
      form.hidden = true;
      // 待ち時間（数十秒〜1分）を秒で見せる。setInterval = 決まった間隔でくり返し実行
      const t0 = Date.now();
      const verb = images.length ? '読んでいます' : '調べています'; // 画像があれば画像だけを読む（検索しない）
      const tick = () => { status.textContent = verb + '… ' + Math.round((Date.now() - t0) / 1000) + '秒'; };
      tick();
      const timer = setInterval(tick, 1000);
      try {
        // FormData = フォームと同じ形式（multipart）で送る。画像ファイルを送れる。
        // Content-Type は自分で書かない（ブラウザが境界の文字列つきで自動で付ける。手書きすると壊れる）
        const fd = new FormData();
        fd.append('studio_id', box.dataset.studioId);
        fd.append('model', box.querySelector('[data-ai-model]').value);
        images.forEach((it) => fd.append('images[]', it.file, it.file.name));
        const res = await fetch('api_studio_ai', {
          method: 'POST',
          headers: { 'X-CSRF-Token': box.dataset.csrf },
          body: fd,
        });
        const data = await res.json().catch(() => ({ error: 'サーバーの返事を読めませんでした' }));
        if (!res.ok || !data.ok) throw new Error(data.error || 'HTTP ' + res.status);
        show(data.proposal, data.yen);
      } catch (e) {
        status.textContent = e.message;
      } finally {
        clearInterval(timer);
        btn.disabled = false;
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
  //     <template data-rate-tpl> の中身（hidden の部屋・対象・曜日入り）を複製して、template の直前に入れる
  //     ・template.content = 画面に出ていない HTML の部品。cloneNode(true) = 中身ごと丸ごと複製
  //     ・入れた行の <select> は app.js の MutationObserver が見つけて、独自の選択 UI に変えてくれる
  //     ・足した行は、時間を「直前の行の終了」から始める（よくある「続きの時間帯」をすぐ入れられるように）
  // ===================================================================
  const setupRateEdit = () => {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-rate-add]');
      if (!btn) return;
      const group = btn.closest('[data-rate-group]');
      const tpl = group.querySelector('template[data-rate-tpl]');
      const row = tpl.content.firstElementChild.cloneNode(true);
      const rows = group.querySelectorAll('.sinfo-rate-row:not(.sinfo-rate-row--head)');
      const prev = rows[rows.length - 1];
      if (prev) {
        const end = Number(prev.querySelector('select[name="end[]"]').value);
        const start = row.querySelector('select[name="start[]"]');
        const endSel = row.querySelector('select[name="end[]"]');
        start.value = String(end);
        endSel.value = String(Math.min(30 * 60, end + 6 * 60)); // 6時間後（上限 30:00）
      }
      tpl.before(row);
      row.querySelector('input[name="price[]"]').focus();
    });
  };

  // DOMContentLoaded を待たなくても、このファイルはページの最後で読むので要素はもう全部ある
  const mapApi = setupMap();
  setupGeo();
  setupStepper();
  setupRoomChips();
  setupRateEdit();
  setupFilter(mapApi);
  setupSim();
  setupAi();
})();

/* =====================================================================
 *  assets/studio_info.js — スタジオ情報（studio_list.php / studio_info.php）の動き
 * =====================================================================
 *  1. 地図（#studio-map）… Leaflet で OpenStreetMap を出し、#studio-pins の JSON からピンを立てる
 *  2. 座標の取得（[data-geo-lookup]）… 住所 → 緯度・経度（国土地理院の住所検索 API）
 *  3. 料金シミュレーション（#studio-sim）… 入力が変わるたびに合計・1人あたり・内訳を計算して表示
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
    if (!box || !pins || !pins.length || !window.L) return;
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
  // ===================================================================
  const setupSim = () => {
    const form = document.getElementById('studio-sim');
    const data = readJson('studio-sim-data');
    const out = document.getElementById('studio-sim-result');
    if (!form || !data || !out) return;

    const DAY = 24 * 60;

    /**
     * 料金表の行 r が、時刻 t（0時からの分）を含むか。
     *   料金表は 22:00〜29:00 のように 24時を超えて書けるので、t をそのまま・-24h・+24h の3通りで比べる
     *   例) 予約 1:00（t=60）  と 料金 24:00〜30:00 → t+24h = 1500 が範囲内 → 一致
     *   例) 予約 23:00〜翌2:00 の 0:30 部分（t=1470）と 料金 0:00〜6:00 → t-24h = 30 が範囲内 → 一致
     */
    const covers = (r, t) => [t, t - DAY, t + DAY].some((x) => r.s <= x && x < r.e);

    /**
     * 時刻 t に使う料金の行を1つ選ぶ。優先順位:
     *   ① その部屋だけの料金 ＞ 全部屋共通（room = 0）
     *   ② 曜日の指定あり（平日 / 土日祝）＞ 全日（day = 0）
     *   sort で「点数が高い順」に並べて先頭を取る
     */
    const findRate = (room, day, t) => {
      const hits = data.rates.filter((r) => (r.room === room || r.room === 0) && (r.day === day || r.day === 0) && covers(r, t));
      const score = (r) => (r.room === room && room !== 0 ? 2 : 0) + (r.day !== 0 ? 1 : 0);
      hits.sort((a, b) => score(b) - score(a));
      return hits[0] || null;
    };

    /** 入力の値を読む（FormData = フォームの入力をまとめて取れるブラウザ標準の仕組み） */
    const read = () => {
      const fd = new FormData(form);
      return {
        room: Number(fd.get('room') || 0),
        day: Number(fd.get('day') || 1),
        start: Number(fd.get('start')),
        dur: Number(fd.get('dur')),
        people: Math.max(1, Number(fd.get('people')) || 1),
        student: fd.get('student') === 'on',
        rentals: data.rentals.map((r) => ({ ...r, qty: Math.max(0, Number(fd.get('rental_' + r.id)) || 0) })),
      };
    };

    /**
     * 部屋代の計算。30分ごとに料金の行を探し、同じ料金が続く所は1つの「区間」にまとめる。
     *   student = true でも、学割の無い時間帯（sp = null）は通常料金
     *   戻り値: { total, segs: [{ from, to, perHour }], missing: 料金未登録の30分がいくつあったか }
     */
    const roomCost = (v, student) => {
      const segs = [];
      let total = 0;
      let missing = 0;
      for (let t = v.start; t < v.start + v.dur; t += 30) {
        const r = findRate(v.room, v.day, t);
        if (!r) {
          missing++;
          continue;
        }
        const perHour = student && r.sp != null ? r.sp : r.p;
        total += perHour / 2; // 30分 = 1時間の半分
        const last = segs[segs.length - 1];
        if (last && last.to === t && last.perHour === perHour) {
          last.to = t + 30; // 直前の区間と同じ料金なら伸ばす
        } else {
          segs.push({ from: t, to: t + 30, perHour, student: student && r.sp != null });
        }
      }
      return { total, segs, missing };
    };

    const render = () => {
      const v = read();
      const room = roomCost(v, v.student);
      const rentalLines = v.rentals.filter((r) => r.qty > 0).map((r) => ({
        name: r.name,
        qty: r.qty,
        cost: r.p * r.qty * (r.hour ? v.dur / 60 : 1), // 1時間あたりのものは時間をかける
      }));
      const rentalTotal = rentalLines.reduce((sum, r) => sum + r.cost, 0); // reduce = 配列を1つの値（合計）にまとめる
      const total = Math.round(room.total + rentalTotal);

      out.replaceChildren(); // 中身を全部消してから作り直す

      // ---- 合計と1人あたり（割り勘は1円単位で切り上げ = 足りなくならないように） ----
      const head = el('div', 'sinfo-sim__total');
      const t1 = el('div');
      t1.append(el('span', 'muted small', '合計'), el('strong', 'sinfo-sim__yen', yen(total)));
      const t2 = el('div');
      t2.append(el('span', 'muted small', '1人あたり（' + v.people + '人）'), el('strong', 'sinfo-sim__yen', yen(Math.ceil(total / v.people))));
      head.append(t1, t2);
      out.append(head);

      // ---- 内訳 ----
      const ul = el('ul', 'sinfo-sim__lines');
      room.segs.forEach((s) => {
        const li = el('li');
        const hours = (s.to - s.from) / 60;
        li.append(
          el('span', '', hm(s.from) + '〜' + hm(s.to) + (s.student ? ' 学割' : '')),
          el('span', 'muted', yen(s.perHour) + '/h × ' + hours + 'h = ' + yen(s.perHour * hours)),
        );
        ul.append(li);
      });
      rentalLines.forEach((r) => {
        const li = el('li');
        li.append(el('span', '', r.name + ' × ' + r.qty), el('span', 'muted', yen(r.cost)));
        ul.append(li);
      });
      out.append(ul);

      // ---- 学割でいくら安くなったか（学割オンで、差があるときだけ） ----
      if (v.student) {
        const diff = Math.round(roomCost(v, false).total - room.total);
        if (diff > 0) out.append(el('p', 'sinfo-sim__save', '学割で ' + yen(diff) + ' お得'));
      }
      // ---- 料金表に無い時間が混ざっていたら注意 ----
      if (room.missing) {
        out.append(el('p', 'sinfo-sim__warn', '料金未登録の時間があります（' + room.missing * 30 + '分）'));
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

    // input イベント = 値が変わるたび（数字を打つたび）に発生。change より反応が早い
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    render(); // 最初に1回表示
  };

  // DOMContentLoaded を待たなくても、このファイルはページの最後で読むので要素はもう全部ある
  setupMap();
  setupGeo();
  setupSim();
})();

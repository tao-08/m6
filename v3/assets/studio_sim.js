/* =====================================================================
 *  assets/studio_sim.js — スタジオ料金の計算（料金シミュレーションの「エンジン」）
 * =====================================================================
 *  使う所: studio_info.php・studio_list.php（assets/studio_info.js）と、日程調整の候補（assets/sched.js）
 *  もとは studio_info.js の中にあった。2か所で同じ計算をコピーすると、片方だけ直して食い違う事故が起きるので、
 *  1つのファイルに切り出して両方から使う（「同じ知識は1か所に」= DRY 原則）
 *
 *  window.studioSimEngine(data) → { calc, dayOk }
 *    calc(v, disc, dur) … v = { ppl: 0=バンド/n=個人n人, room: 部屋id, day: 曜日区分, start: 開始(0時からの分), rentals: [] }
 *                         disc = 割引区分の id（0 = なし）、dur = 長さ（分）
 * ===================================================================== */
/** 料金の計算（スタジオの詳細ページの試算・一覧の比較・日程調整の候補で共通）。data = PHP の studio_sim_payload() の中身 */
window.studioSimEngine = (data) => {
  const DAY = 24 * 60;
  // 日をまたぐ時間帯は DB に「終了 = 翌日の時刻 + 24時間」で入っている（23:00〜10:00 → 1380〜2040。古いデータには 22:00〜29:00 のような値もある）ので、予約の時刻を 0 / -24h / +24h ずらして比べる
  const SHIFTS = [0, -DAY, DAY];

  /**
   * 料金表の行 r が、時刻 t（0時からの分）を含むか
   *   例) 予約 1:00（t=60）  と 料金 23:00〜10:00（1380〜2040） → t+24h = 1500 が範囲内 → 一致
   *   例) 予約 23:00〜翌2:00 の 0:30 部分（t=1470）と 料金 0:00〜6:00 → t-24h = 30 が範囲内 → 一致
   */
  const covers = (r, t, off) => SHIFTS.some((d) => r.s <= t + d - off && t + d - off < r.e);

  /** 選んだ部屋の「30分始まり」の分（料金・パックの時間帯を遅らせる分。30 / 0）。部屋が無いスタジオは 0 */
  const shiftOf = (v) => data.rooms.find((r) => r.id === v.room)?.sh || 0;

  /**
   * 曜日が合うか。rowDay = 料金・パック・割引の曜日、v = 選んだ曜日
   *   曜日の番号: 0 = 全日 / 1 = 平日 / 2 = 土日祝 / 3 = 土 / 4 = 日祝
   *   ・全日（0）は いつでも合う / 同じ番号なら合う
   *   ・「土日祝」（2）は、土（3）を選んでも日祝（4）を選んでも合う（土日で同じ料金のスタジオ用の行）
   */
  const dayOk = (rowDay, v) => rowDay === 0 || rowDay === v || (rowDay === 2 && (v === 3 || v === 4));

  /** 対象（ppl: 0 = バンド / n = 個人n人）・部屋・曜日が合う行だけにする（料金表・パック共通） */
  const matches = (r, v) => r.ppl === v.ppl && (r.rooms.length === 0 || r.rooms.includes(v.room)) && dayOk(r.day, v.day);

  /**
   * 時刻 t に使う料金の行を1つ選ぶ。優先順位:
   *   ① 部屋を指定した料金（rooms に選んだ部屋が入っている）＞ 全部屋共通（rooms が空）
   *   ② 選んだ曜日にぴったりの行（土 / 日祝 / 平日）＞ 「土日祝」の行（土も日祝も含む）＞ 全日（day = 0）
   *   部屋の指定（4点）のほうが曜日（最大2点）より強い。sort で「点数が高い順」に並べて先頭を取る
   */
  const findRate = (v, t) => {
    const off = shiftOf(v);
    const hits = data.rates.filter((r) => matches(r, v) && covers(r, t, off));
    const score = (r) => (r.rooms.length ? 4 : 0) + (r.day === 0 ? 0 : r.day === v.day ? 2 : 1);
    hits.sort((a, b) => score(b) - score(a));
    return hits[0] || null;
  };

  /**
   * 割引区分 disc（区分の id。0 = 割引なし）の料金があればその料金、無ければ通常の料金
   *   r.dp = {区分id: 料金}。その時間帯・パックに区分の料金が無ければ undefined → 通常料金
   */
  const priceOf = (r, disc) => (disc && r.dp[disc] != null ? r.dp[disc] : r.p);

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
      const off = shiftOf(v);
      if (!SHIFTS.some((d) => pk.s <= v.start + d - off && v.start + dur + d - off <= pk.e)) return;
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

  return { calc, dayOk };
};

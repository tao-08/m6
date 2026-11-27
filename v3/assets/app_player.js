/*
 * app_player.js — 音源・動画のプレーヤーとドライブの先読み
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/* ---------------------------------------------------------------------
 * live_youtube.php: 「どのバンドにも入っていない動画」の一覧を、プルダウンで選ぶたびに減らす（外せば戻る）
 *   一覧には全部の動画が <li data-yt-video="動画ID"> で入っていて、どれかのバンドで選ばれているものを hidden にする
 * ------------------------------------------------------------------- */
function setupYoutubeLeftover() {
  const form = document.querySelector('[data-yt-form]');
  const box = form?.querySelector('[data-yt-leftover]');
  if (!box) return;
  const update = () => {
    const selects = [...form.querySelectorAll('select')];
    const picked = new Set(selects.map((s) => s.value));
    // ほかの行で選んでいる動画は薄く（ポップアップにも app_form.js が写す）
    selects.forEach((s) => [...s.options].forEach((o) => {
      o.classList.toggle('is-muted', o.value !== '' && o.value !== 'keep' && o.value !== s.value && picked.has(o.value));
    }));
    let left = 0;
    box.querySelectorAll('[data-yt-video]').forEach((li) => {
      li.hidden = picked.has(li.dataset.ytVideo);
      if (!li.hidden) left++;
    });
    box.querySelector('[data-yt-left-count]').textContent = left;
    box.hidden = left === 0;
  };
  form.addEventListener('change', update); // setupSelectPick のポップアップで選んでも change が出る
  update();
}

/* ---------------------------------------------------------------------
 * live.php: ドライブのファイル（📂 ボタン）を押すと、そのバンドのカードの下にファイルの一覧を開く
 *   ・中身（プレーヤー・写真・リンク）はサーバーが <template data-drive-tpl> に入れてある。
 *     押したときに初めて枠 <div data-drive-player> に写す（template の中は読み込まれないので、全バンド分あっても軽い）
 *   ・閉じるときは中身ごと消す。hidden で隠すだけだと、見えないまま音が鳴り続けるため
 *   ・同時に開くのは1つだけ。別のバンドを開いたら前の一覧は閉じる（2曲同時に鳴らない）
 *   ・イベント委任: ボタン1つずつに addEventListener せず、document に1つだけ付けて
 *     「押された要素が 📂 ボタンか」を closest で調べる（ボタンが何十個あっても登録は1回）
 * ------------------------------------------------------------------- */
function setupDrivePlayer() {
  if (!document.querySelector('[data-drive-toggle]')) return; // ボタンが無いページでは何もしない
  // ボタン → そのバンドの枠と template（同じカード .slot の中にある）
  const partsOf = (btn) => {
    const slot = btn.closest('.slot');
    return { box: slot?.querySelector('[data-drive-player]'), tpl: slot?.querySelector('template[data-drive-tpl]') };
  };
  const close = (btn) => {
    const { box } = partsOf(btn);
    if (!box) return;
    box.replaceChildren(); // 中身（iframe など）を全部消す → 再生も止まる
    box.hidden = true;
    btn.setAttribute('aria-expanded', 'false'); // aria-expanded: 読み上げソフトに「開いている / 閉じている」を伝える
  };
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-drive-toggle]');
    if (!btn) return;
    const { box, tpl } = partsOf(btn);
    if (!box || !tpl) return;
    if (btn.getAttribute('aria-expanded') === 'true') {
      close(btn);
      return;
    }
    // ほかに開いている一覧があれば閉じる
    document.querySelectorAll('[data-drive-toggle][aria-expanded="true"]').forEach(close);
    box.append(tpl.content.cloneNode(true)); // template の中身を複製して入れる（true = 子要素も全部）
    setupAudioPlayers(box); // 中の音源のプレーヤーを動かす
    box.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
  });
}

/* ---------------------------------------------------------------------
 * live.php / band.php: 音源・動画が2つ以上あるとき、上のチップ（ラジオボタン）で再生するファイルを切り替える
 *   プレーヤー（iframe）は1つだけ。選んだファイルの再生用 URL（value）を iframe の src に入れ直す
 *   ・live.php の一覧はボタンを押してから作られるので、document で change を受ける（イベント委任）
 *   ・value は PHP がファイル ID から組み立てた drive.google.com の URL だけ。念のためここでも確かめる
 * ------------------------------------------------------------------- */
function setupDriveSwitch() {
  document.addEventListener('change', (e) => {
    const radio = e.target.closest('input[data-drive-src]');
    if (!radio?.checked) return;
    const frame = radio.closest('[data-drive-switch]')?.querySelector('iframe');
    if (!frame || !radio.value.startsWith('https://drive.google.com/')) return;
    frame.src = radio.value;                                  // src を変えると、前のファイルは止まって新しいファイルが読み込まれる
    frame.title = radio.closest('label')?.textContent.trim() || frame.title;
  });
}

/* ---------------------------------------------------------------------
 * 音源のプレーヤー（lib/audio_player.php の figure[data-audio-player]）。band.php・live.php 共通
 *   ・本番の前段のキャッシュが Range を落とすので、ブラウザのシークには頼らない。
 *     まだ読み込んでいない位置へは、src に ?from=バイト位置 を付けて、その位置から流してもらう（サーバー側は lib/wav.php など）
 *     base … いまの src の先頭の時刻（秒）。表示する時刻 = base ＋ audio.currentTime
 *     total … 全体の長さ（秒）。最初の読み込み（from なし）の duration だけを使う（from 付きは短く出るので無視）
 *     バイト位置 = 時刻 × (大きさ ÷ 全体の長さ)。WAV のずれ・サンプルの境界はサーバーが直す
 *   ・チャプター（band.php だけ。data-chapters）: 再生位置の棒の上の吹き出し（MC / 曲の番号）と、下のチップ（時間 ＋ MC / 曲名）。
 *     どちらも押すとその位置から再生する。鉛筆を押すと編集モード: 「MC」「曲」「終わり」で今の再生位置にチャプターを足し、チップの ✕ で消す（api_audio_chapter.php）
 *     編集モードでチップを押すと選ばれて、時間を直せる（入力・−1・+1・今の位置）。直した位置の2秒前から流す。連打は少し待って1回で保存する
 *   ・動き（開け閉め・出入り・並べ替え・つまみの滑り）の時間とイージングは CSS のトークン（--t-* / --ease-out）。減らす設定のときは付けない
 *   ・曲の番号は保存せず、時間順に 1, 2, 3… と数えて songs（セトリの曲名）に当てる
 *   ・同時に鳴らすのは1つだけ
 * ------------------------------------------------------------------- */
/**
 * 音源の長さ（秒）を、先頭 64KB とファイルの大きさから計算する。わからなければ null
 *   MP3: Xing/Info・VBRI のフレーム数があればそれ（VBR）、無ければビットレートから（CBR）/ WAV: data の大きさ ÷ byteRate
 */
async function audioProbeDuration(src, size) {
  let b;
  try {
    const res = await fetch(src);
    const reader = res.body.getReader();
    const parts = [];
    let n = 0;
    while (n < 65536) {
      const { done, value } = await reader.read();
      if (done) break;
      parts.push(value);
      n += value.length;
    }
    reader.cancel().catch(() => {});
    b = new Uint8Array(n);
    let o = 0;
    parts.forEach((p) => { b.set(p, o); o += p.length; });
  } catch (e) {
    return null;
  }
  const u32 = (i) => ((b[i] << 24) >>> 0) + (b[i + 1] << 16) + (b[i + 2] << 8) + b[i + 3];
  const str = (i, len) => String.fromCharCode(...b.subarray(i, i + len));
  if (str(0, 4) === 'RIFF' && str(8, 4) === 'WAVE') {
    let byteRate = 0;
    for (let i = 12; i + 8 <= b.length;) {
      const len = b[i + 4] + (b[i + 5] << 8) + (b[i + 6] << 16) + (b[i + 7] << 24);
      if (str(i, 4) === 'fmt ') byteRate = b[i + 16] + (b[i + 17] << 8) + (b[i + 18] << 16) + (b[i + 19] << 24);
      if (str(i, 4) === 'data') return byteRate ? (size - i - 8) / byteRate : null;
      i += 8 + len + (len & 1);
    }
    return null;
  }
  let i = 0;
  if (str(0, 3) === 'ID3') i = 10 + ((b[6] << 21) | (b[7] << 14) | (b[8] << 7) | b[9]) + (b[5] & 0x10 ? 10 : 0);
  const KBPS = { 1: [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320], 2: [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160] };
  for (; i + 4 < b.length; i++) {
    if (b[i] !== 0xFF || (b[i + 1] & 0xE0) !== 0xE0) continue;
    const ver = (b[i + 1] >> 3) & 3; // 3 = MPEG1 / 2 = MPEG2 / 0 = MPEG2.5
    const layer = (b[i + 1] >> 1) & 3; // 1 = Layer III
    const bi = b[i + 2] >> 4;
    const si = (b[i + 2] >> 2) & 3;
    if (ver === 1 || layer !== 1 || bi === 0 || bi === 15 || si === 3) continue; // Layer III 以外・ありえない値は飛ばす
    const sr = [44100, 48000, 32000][si] / (ver === 3 ? 1 : ver === 2 ? 2 : 4);
    const spf = ver === 3 ? 1152 : 576;
    const mono = (b[i + 3] >> 6) === 3;
    const xing = i + 4 + (ver === 3 ? (mono ? 17 : 32) : (mono ? 9 : 17));
    if (xing + 12 <= b.length && ['Xing', 'Info'].includes(str(xing, 4)) && (u32(xing + 4) & 1)) return u32(xing + 8) * spf / sr;
    if (i + 50 <= b.length && str(i + 36, 4) === 'VBRI') return u32(i + 50) * spf / sr;
    return (size - i) * 8 / (KBPS[ver === 3 ? 1 : 2][bi] * 1000);
  }
  return null;
}

function setupAudioPlayers(root = document) {
  const figs = [...root.querySelectorAll('[data-audio-player]:not([data-ap-ready])')]; // live.php は一覧を開くたびに作られるので、root を渡して作った分だけ初期化する
  if (!figs.length) return;
  const fmtTime = (ms) => {
    const s = Math.floor(ms / 1000);
    const sec = String(s % 60).padStart(2, '0');
    return s >= 3600 ? `${Math.floor(s / 3600)}:${String(Math.floor(s / 60) % 60).padStart(2, '0')}:${sec}` : `${Math.floor(s / 60)}:${sec}`;
  };

  figs.forEach((fig) => {
    fig.dataset.apReady = '1';
    const audio = fig.querySelector('audio');
    const playBtn = fig.querySelector('[data-ap-play]');
    const playIcon = mkEl('span', 'icon icon--fill', 'play_arrow');
    playIcon.setAttribute('aria-hidden', 'true');
    playBtn.replaceChildren(playIcon);
    const range = fig.querySelector('[data-ap-seek]');
    const timeEl = fig.querySelector('[data-ap-time]');
    const bubbles = fig.querySelector('[data-ap-bubbles]');
    const src = audio.getAttribute('src');
    const size = Number(fig.dataset.size) || 0;
    let chap = null; // { band, key, list: [{id, kind, ms}], songs }
    try { chap = fig.dataset.chapters ? JSON.parse(fig.dataset.chapters) : null; } catch (e) { /* チャプターなしで動かす */ }
    let base = 0;
    let total = 0;
    let pending = null; // 長さがわかる前に押された位置（秒）
    let dragging = false;
    let timer = 0;
    let draft = null;     // 自動で付けた下書き [{kind, ms}]。あるあいだは保存済みの代わりにこれを出す
    let ctrl = null;      // 自動で付けている最中の中止用
    let selId = null;     // 編集モードで選んだチャプターの id（時間を直す相手）
    let pend = null;      // 保存待ちの時間の直し { id, ms, from }。from = 直す前の時間（失敗したら戻す）
    let pendTimer = 0;
    let inflight = 0;     // 送信中の「直し」の数
    const els = new Map(); // id → { chip, bubble }。描画のたびに作り直さず使い回して、動きをつける
    const seen = { chip: false, bubble: false }; // 最初の描画は、出てくる動きを付けない
    const now = () => base + audio.currentTime;
    const shown = () => draft || chap.list;
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    // 動きの時間・イージングは CSS のトークンから読む（減らす設定のときは、呼ぶ側で動かさない）
    const tok = (name) => getComputedStyle(fig).getPropertyValue(name).trim();
    const dur = (name) => (parseFloat(tok(name)) || 0) * 1000;

    // 今の再生位置のチャプター（いちばん後ろの「開始が今以前」）に .is-current
    const mark = () => {
      if (!chap) return;
      const cur = shown().findLast((c) => c.ms <= now() * 1000);
      for (const el of [...fig.querySelectorAll('.audio-chapter'), ...bubbles.children]) {
        el.classList.toggle('is-current', !!cur && Number(el.dataset.id) === cur.id);
      }
    };
    const seekBox = range.parentElement;
    const update = () => {
      const t = dragging ? Number(range.value) : now();
      timeEl.textContent = `${fmtTime(t * 1000)} / ${fmtTime(total * 1000)}`;
      if (!dragging) range.value = t;
      seekBox.style.setProperty('--q', total ? Math.min(1, t / total) : 0);
      mark();
    };
    // 位置が飛ぶとき（押して動かしたとき）だけ、つまみと塗りを滑らせる。再生中・ドラッグ中は付けない
    let jumpTimer = 0;
    const jump = () => {
      if (reduced.matches) return;
      seekBox.classList.add('is-jumping');
      clearTimeout(jumpTimer);
      jumpTimer = setTimeout(() => seekBox.classList.remove('is-jumping'), dur('--t-base') * 1.5);
    };

    // 読み込めないとき（error / 60 秒たっても始まらない。初回は大きい音源をサーバーに落とすので長めに待つ）は、止めて知らせる
    let loadTimer = 0;
    const loadWatch = () => {
      clearTimeout(loadTimer);
      loadTimer = setTimeout(loadFail, 60000);
    };
    let failed = false; // 一度知らせたら、次に押されるまで黙る（下の load() がまた error を出して繰り返すため）
    const loadFail = () => {
      clearTimeout(loadTimer);
      if (failed) return;
      failed = true;
      audio.pause();
      audio.preload = 'none'; // 読み直しの load() で、また自分から読みに行かない
      audio.load(); // error の状態を解いて、次に押したときに読み直せるようにする
      paused();
      ctrl?.abort(); // 自動で付けている最中なら止める（中止の表示も戻る）
      flashToast('音源を読み込めませんでした', 'error');
    };
    audio.addEventListener('error', loadFail);
    audio.addEventListener('canplay', () => clearTimeout(loadTimer));

    // t 秒へ。読み込み済みの範囲ならそのまま動かし、そうでなければ ?from= で読み直す。play: true で再生する（省略は今のまま）
    const seekTo = (t, play) => {
      failed = false;
      if (!total) { // まだ長さがわからない: 読み込みを始めて、わかったら動かす
        if (audio.readyState > 0) { audio.currentTime = Math.max(0, t - base); } else { pending = t; loadWatch(); }
        if (play !== false) audio.play().catch(() => {});
        return;
      }
      t = Math.min(Math.max(t, 0), total);
      const resume = play ?? !audio.paused;
      const rel = t - base;
      let buffered = false;
      for (let i = 0; i < audio.buffered.length; i++) {
        if (rel >= audio.buffered.start(i) && rel < audio.buffered.end(i) - 0.5) buffered = true;
      }
      if (buffered || !size) {
        audio.currentTime = Math.max(0, rel);
      } else {
        const off = Math.round(t * size / total);
        audio.src = off > 0 ? `${src}${src.includes('?') ? '&' : '?'}from=${off}` : src;
        base = off > 0 ? t : 0;
        loadWatch();
      }
      if (resume) audio.play().catch(() => {});
      jump();
      update();
    };

    // 選んだチップに印を付け、時間の入力欄（選んだときだけ開く）を今の値にそろえる
    const atInput = fig.querySelector('[data-ap-at]');
    const syncSel = () => {
      if (!chap) return;
      const c = shown().find((x) => x.id === selId);
      if (!c) selId = null;
      fig.classList.toggle('has-sel', !!c && fig.classList.contains('is-editing'));
      for (const [id, e] of els) e.chip.classList.toggle('is-selected', id === selId);
      if (c && document.activeElement !== atInput) atInput.value = fmtTime(c.ms); // 打っている最中は触らない
    };

    // 吹き出し・チップの描画。同じ id のものは使い回して、位置の移り変わり・色の変化を動きにする
    //   新しいもの: ぽんと出る / 順番が変わったチップ: 元の位置から滑る（FLIP）/ 吹き出し: CSS の left の transition で滑る
    const render = () => {
      if (!chap) return;
      const list = shown();
      const motion = !reduced.matches;
      fig.classList.toggle('is-draft', !!draft);
      let n = 0;
      const labels = list.map((c) => {
        if (c.kind === 'end') return { short: '終', full: '終わり' }; // 曲の終わり（番号には数えない）
        if (c.kind !== 'song') return { short: 'MC', full: 'MC' };
        n += 1;
        return { short: String(n), full: chap.songs[n - 1] ? `${n}. ${chap.songs[n - 1]}` : String(n) };
      });
      const before = new Map([...els].map(([id, e]) => [id, e.chip.getBoundingClientRect()]));
      const keep = new Set(list.map((c) => c.id));
      for (const [id, e] of els) {
        if (keep.has(id)) continue;
        e.chip.remove();
        e.bubble?.remove();
        els.delete(id);
      }
      // 出てくる動き。同時に何個も出るとき（下書き）は、少しずつ遅らせる（--i）
      let fresh = 0;
      const pop = (el) => {
        if (!motion) return;
        el.style.setProperty('--i', fresh);
        el.classList.add('is-new');
        el.addEventListener('animationend', () => el.classList.remove('is-new'), { once: true });
      };
      const chips = list.map((c, i) => {
        let e = els.get(c.id);
        const isNew = !e;
        if (!e) {
          const chip = mkEl('span', 'chip audio-chapter');
          chip.dataset.id = c.id;
          const go = mkEl('button', 'audio-chapter__go');
          go.type = 'button';
          go.append(mkEl('span', 'audio-chapter__time'), document.createTextNode(''));
          const del = mkEl('button', 'audio-chapter__del');
          del.type = 'button';
          del.setAttribute('aria-label', '削除');
          del.title = '削除';
          del.append(mkEl('span', 'icon', 'close'));
          del.firstChild.setAttribute('aria-hidden', 'true');
          chip.append(go, del);
          e = { chip, bubble: null };
          els.set(c.id, e);
        }
        e.chip.dataset.ms = c.ms;
        const go = e.chip.firstChild;
        go.firstChild.textContent = fmtTime(c.ms);
        go.lastChild.nodeValue = labels[i].full;
        if (isNew && seen.chip) pop(e.chip);
        if (total) {
          if (!e.bubble) {
            e.bubble = mkEl('button', 'audio-bubble');
            e.bubble.type = 'button';
            e.bubble.dataset.id = c.id;
            bubbles.append(e.bubble);
            if (seen.bubble) pop(e.bubble);
          }
          e.bubble.textContent = labels[i].short;
          e.bubble.dataset.ms = c.ms;
          e.bubble.style.setProperty('--x', Math.min(1, c.ms / 1000 / total));
          e.bubble.setAttribute('aria-label', `${labels[i].full} から再生`);
          e.bubble.title = `${labels[i].full} から再生`;
        } else if (e.bubble) {
          e.bubble.remove();
          e.bubble = null;
        }
        if (isNew) fresh += 1;
        return e.chip;
      });
      const listEl = fig.querySelector('[data-ap-list]');
      chips.forEach((chip, i) => { if (listEl.children[i] !== chip) listEl.insertBefore(chip, listEl.children[i] || null); });
      if (motion) {
        chips.forEach((chip, i) => {
          const old = before.get(list[i].id);
          if (!old) return;
          const to = chip.getBoundingClientRect();
          const dx = old.left - to.left;
          const dy = old.top - to.top;
          if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;
          chip.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }], { duration: dur('--t-slow'), easing: tok('--ease-out') });
        });
      }
      seen.chip = true;
      if (total) seen.bubble = true;
      syncSel();
      mark();
    };

    // 時間の表示の幅を「全体 / 全体」を表示したときの幅で固定する（再生位置が 9:59 → 10:00 になっても幅が変わらない）
    const fitTime = () => {
      const was = timeEl.textContent;
      timeEl.style.minWidth = '';
      timeEl.textContent = `${fmtTime(total * 1000)} / ${fmtTime(total * 1000)}`; // 数字は等幅なので、これがいちばん広い
      const w = timeEl.getBoundingClientRect().width;
      timeEl.textContent = was;
      if (w) timeEl.style.minWidth = `${Math.ceil(w)}px`;
    };
    const setTotal = (d) => {
      total = d;
      fitTime();
      range.max = Math.ceil(total);
      range.disabled = false;
      render();
    };
    audio.addEventListener('loadedmetadata', async () => {
      clearTimeout(loadTimer);
      if (base === 0 && Number.isFinite(audio.duration) && audio.duration > 0) {
        setTotal(audio.duration);
      } else if (base === 0 && !total && size) {
        // 本番は前段のキャッシュで Content-Length が消え、MP3 の長さが Infinity になる。先頭を読んで自分で計算する
        const d = await audioProbeDuration(src, size);
        if (d && !total) setTotal(d);
      }
      if (pending !== null && total) {
        const t = pending;
        pending = null;
        seekTo(t, true);
      }
      update();
    });
    audio.addEventListener('durationchange', () => { // MP3 は読み進めると長さの見積もりが変わる
      if (base === 0 && Number.isFinite(audio.duration) && audio.duration > 0 && audio.duration !== total) {
        total = audio.duration;
        fitTime();
        range.max = Math.ceil(total);
        render();
      }
    });
    audio.addEventListener('timeupdate', update);
    audio.addEventListener('play', () => {
      document.querySelectorAll('[data-audio-player] audio').forEach((a) => { if (a !== audio) a.pause(); });
      playIcon.textContent = 'pause';
      playBtn.setAttribute('aria-label', '一時停止');
      playBtn.title = '一時停止';
    });
    const paused = () => {
      playIcon.textContent = 'play_arrow';
      playBtn.setAttribute('aria-label', '再生');
      playBtn.title = '再生';
    };
    audio.addEventListener('pause', paused);
    audio.addEventListener('ended', paused);

    playBtn.addEventListener('click', () => {
      failed = false;
      if (audio.ended) seekTo(0, true); // 最後まで流れたあとは、from 付きの src でも頭から
      else if (audio.paused) {
        if (audio.networkState === HTMLMediaElement.NETWORK_NO_SOURCE) audio.src = audio.src; // 前に読めなかった: 入れ直して読み直す
        if (audio.readyState === 0) loadWatch();
        audio.play().catch(() => {});
      } else fadePause(audio);
    });
    // つまみを動かしている間は表示だけ。離したら動かす（キーの連打は、少し待って最後の位置へ）
    range.addEventListener('input', () => { dragging = true; update(); });
    range.addEventListener('change', () => {
      dragging = true;
      const v = Number(range.value);
      clearTimeout(timer);
      timer = setTimeout(() => { dragging = false; seekTo(v); }, 150);
    });

    fig.addEventListener('click', (e) => {
      const go = e.target.closest('.audio-chapter__go');
      const bubble = e.target.closest('.audio-bubble');
      const del = e.target.closest('.audio-chapter__del');
      const add = e.target.closest('[data-ap-add]');
      const nudge = e.target.closest('[data-ap-nudge]');
      if (go || bubble) {
        const el = go ? go.closest('.audio-chapter') : bubble;
        if (go && fig.classList.contains('is-editing')) { // 編集モードのチップは、選んで時間を直せるようにする
          if (pend && pend.id !== Number(el.dataset.id)) flushMove(false);
          selId = Number(el.dataset.id);
          syncSel();
        }
        seekTo(Number(el.dataset.ms) / 1000, true);
      } else if (del) {
        const id = Number(del.closest('.audio-chapter').dataset.id);
        if (pend?.id === id) { clearTimeout(pendTimer); pend = null; }
        if (draft) { // 下書きは保存前なので、画面から外すだけ
          leave(els.get(id)).then(() => { draft = draft?.filter((c) => c.id !== id) ?? null; render(); });
          return;
        }
        send({ action: 'delete', chapter_id: id }, leave(els.get(id)));
      } else if (nudge) {
        const c = shown().find((x) => x.id === selId);
        if (c) moveSel(c.ms + Number(nudge.dataset.apNudge) * 1000, 400); // 連打は 400ms 待って1回にまとめる
      } else if (e.target.closest('[data-ap-here]')) {
        moveSel(Math.floor(now() * 1000), 0);
      } else if (add) {
        send({ action: 'add', key: chap.key, kind: add.dataset.apAdd, at_ms: Math.floor(now() * 1000) });
      } else if (e.target.closest('[data-ap-auto]')) {
        autoRun();
      } else if (e.target.closest('[data-ap-stop]')) {
        ctrl?.abort();
      } else if (e.target.closest('[data-ap-save]')) {
        autoSave();
      } else if (e.target.closest('[data-ap-discard]')) {
        autoDiscard();
      } else if (e.target.closest('[data-ap-edit]')) {
        const on = !fig.classList.contains('is-editing');
        fig.classList.toggle('is-editing', on);
        fig.querySelector('[data-ap-edit]').setAttribute('aria-pressed', String(on));
        if (!on) { // 編集を閉じたら、保存待ちの直しは保存し、途中の自動も下書きも捨てる
          flushMove(false);
          selId = null;
          ctrl?.abort();
          autoDiscard();
          syncSel();
        }
      }
    });
    // 追加・削除。返事は、その音源の今のチャプター全部。成功したら true。hold = 消える動きが終わるまで待つ Promise
    const send = async (body, hold) => {
      try {
        const res = await apiPost('api_audio_chapter.php', { band_id: chap.band, ...body }, '保存できませんでした');
        await hold;
        chap.list = res.chapters;
        render();
        return true;
      } catch (e) {
        flashToast(e.message, 'error');
        for (const x of els.values()) { x.chip.classList.remove('is-leaving'); x.bubble?.classList.remove('is-leaving'); }
        return false;
      }
    };
    // 消す動き: チップと吹き出しを薄く縮める。終わったら（減らす設定のときはすぐ）解決する
    const leave = (e) => {
      if (!e || reduced.matches) return Promise.resolve();
      e.chip.classList.add('is-leaving');
      e.bubble?.classList.add('is-leaving');
      return new Promise((resolve) => {
        e.chip.addEventListener('animationend', resolve, { once: true });
        setTimeout(resolve, dur('--t-base') * 1.5); // animationend が来ない場合の保険
      });
    };

    // 時間の直し。画面にはすぐ出し、保存と再生（直した位置の2秒前から）は少し待って1回にまとめる
    //   shortcut: 返事が前後して届くと古い並びが残りうる。数人が同時に直すなら、番号を付けて新しいものだけ使う
    const byTime = (a, b) => a.ms - b.ms || a.id - b.id;
    const flushMove = async (replay = true) => {
      clearTimeout(pendTimer);
      const p = pend;
      if (!p) return;
      pend = null;
      if (replay) seekTo(Math.max(0, p.ms / 1000 - 2), true);
      if (draft) return; // 下書きは「保存」でまとめて保存する
      inflight += 1;
      try {
        const res = await apiPost('api_audio_chapter.php', { band_id: chap.band, action: 'move', chapter_id: p.id, at_ms: p.ms }, '保存できませんでした');
        if (!pend && inflight === 1) chap.list = res.chapters; // 続きの直しがあるうちは、古い並びに戻さない
      } catch (e) {
        flashToast(e.message, 'error');
        const c = chap.list.find((x) => x.id === p.id);
        if (c) { c.ms = p.from; chap.list.sort(byTime); }
      }
      inflight -= 1;
      render();
    };
    const moveSel = (ms, wait) => {
      const c = shown().find((x) => x.id === selId);
      if (!c) return;
      if (pend && pend.id !== c.id) flushMove(false);
      ms = Math.max(0, Math.min(Math.round(ms), total ? Math.floor(total * 1000) : 86400000));
      pend = { id: c.id, ms, from: pend?.id === c.id ? pend.from : c.ms };
      c.ms = ms;
      shown().sort(byTime);
      render();
      clearTimeout(pendTimer);
      pendTimer = setTimeout(flushMove, wait);
    };
    // 入力: m:ss・h:mm:ss・秒だけ（全角も可）。Enter か外れたら確定、Esc でやめる。読めないときは元に戻す
    const parseTime = (s) => {
      const p = s.normalize('NFKC').trim().split(':');
      if (p.length > 3 || p.some((x) => !/^\d{1,5}$/.test(x)) || p.slice(1).some((x) => Number(x) > 59)) return null;
      return p.reduce((sum, x) => sum * 60 + Number(x), 0) * 1000;
    };
    atInput?.addEventListener('blur', () => { // チャプターのないプレーヤー（live.php）には時間の入力欄が無い

      const c = shown().find((x) => x.id === selId);
      if (!c) return;
      const ms = parseTime(atInput.value);
      if (ms !== null && ms !== Math.floor(c.ms / 1000) * 1000) moveSel(ms, 0);
      else atInput.value = fmtTime(c.ms);
    });
    atInput?.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') atInput.value = '';
      if ((e.key === 'Enter' && !e.isComposing) || e.key === 'Escape') atInput.blur();
    });

    // 自動で付ける: Gemini に聴かせて下書きを作る → 聞いて確かめる → 保存（置き換え）かやめる
    const setMode = (m) => fig.querySelectorAll('[data-ap-in]').forEach((b) => { b.hidden = b.dataset.apIn !== m; });
    const autoRun = async () => {
      ctrl = new AbortController();
      const { signal } = ctrl;
      setMode('busy');
      try {
        const { draft: found } = await apiPost('api_audio_chapter.php', { action: 'auto', band_id: chap.band, key: chap.key }, '自動で付けられませんでした', { signal });
        if (!found.length) throw new Error('none');
        draft = found.map((c, i) => ({ ...c, id: i - found.length })); // 保存前の印。負の番号（時間順に並ぶ）で、保存済みの id とぶつからない
        setMode('draft');
        render();
      } catch (e) {
        setMode('idle');
        if (e.message === 'none') flashToast('区切りを見つけられませんでした', 'warn');
        else if (e.name !== 'AbortError') flashToast(e.message, 'error');
      }
      ctrl = null;
    };
    const autoDiscard = () => {
      if (!draft) return;
      draft = null;
      if (pend?.id < 0) { clearTimeout(pendTimer); pend = null; } // 下書きの直しは捨てる
      setMode('idle');
      render();
    };
    const autoSave = async () => {
      if (chap.list.length && !confirm('今のチャプターを置き換えますか？')) return;
      if (await send({ action: 'replace', key: chap.key, chapters: draft.map(({ kind, ms }) => ({ kind, ms })) })) {
        draft = null;
        if (pend?.id < 0) { clearTimeout(pendTimer); pend = null; }
        setMode('idle');
        render();
      }
    };
    render();
  });
}

/* ---------------------------------------------------------------------
 * live.php / band.php: ドライブのプレーヤーを速く出すための「先読み」
 *   ドライブのプレーヤー（iframe）は、開くたびにドライブの画面の部品（JS・CSS で 1〜2MB）を読み込むので遅い。
 *   そこでページを開いたら、押される前に次の2つを済ませておく:
 *     1. preconnect … drive.google.com との接続（DNS の名前解決・暗号化のあいさつ）を先にしておく
 *     2. 見えないプレーヤーを1つだけ読み込んで、すぐ消す … 部品がブラウザのキャッシュに残るので、
 *        本物のプレーヤーを開いたときはキャッシュから出せて速い（音源の中身は再生しないと落ちないので、ギガはほぼ食わない）
 *   ・データセーバー（navigator.connection.saveData）が ON の端末では何もしない（通信を節約したい人の邪魔をしない）
 *   ・requestIdleCallback … ブラウザの手が空いたときに実行する。ページの表示を遅くしない
 *     （Safari には無いので、無ければ setTimeout で少し待ってから）
 * ------------------------------------------------------------------- */
function setupDriveWarmup() {
  // 再生できるものがあるページだけ: live.php は template の中、band.php は最初から置いてある iframe
  const tpl = [...document.querySelectorAll('template[data-drive-tpl]')].find((t) => t.content.querySelector('iframe'));
  // getAttribute: template の中の要素は「ページの外」扱いなので、.src より書いたままの値を取るほうが確実
  const src = tpl?.content.querySelector('iframe').getAttribute('src') || document.querySelector('.drive-player__frame')?.getAttribute('src');
  if (!src || navigator.connection?.saveData) return;

  // 1. 先に接続しておく（<link rel="preconnect"> を <head> に足すだけ。同じものがあれば足さない）
  for (const origin of ['https://drive.google.com', 'https://www.gstatic.com']) {
    if (document.querySelector(`link[rel="preconnect"][href="${origin}"]`)) continue;
    const link = document.createElement('link');
    link.rel = 'preconnect';
    link.href = origin;
    link.crossOrigin = ''; // フォントなど「別のサイトから取るファイル」の接続も使い回せるように
    document.head.append(link);
  }
  if (!tpl) return; // band.php はプレーヤーがもう読み込まれているので、2. は要らない

  // 2. 見えないプレーヤーで部品をキャッシュに入れる（読み込み終わったら消す。30秒で終わらなくても消す）
  const warm = () => {
    const frame = document.createElement('iframe');
    frame.src = src;
    frame.title = '';
    frame.tabIndex = -1;                        // Tab キーでここに止まらないように
    frame.setAttribute('aria-hidden', 'true');  // 読み上げソフトにも出さない
    // display: none だと読み込まないブラウザがあるので、大きさ 0・画面の外に置いて「見えないだけ」にする
    frame.style.cssText = 'position:absolute;width:0;height:0;border:0;left:-9999px;visibility:hidden';
    const done = () => frame.remove();
    frame.addEventListener('load', () => setTimeout(done, 3000), { once: true }); // 中の部品の読み込みを少し待ってから消す
    setTimeout(done, 30000);
    document.body.append(frame);
  };
  if ('requestIdleCallback' in window) requestIdleCallback(warm, { timeout: 4000 });
  else setTimeout(warm, 1500);
}

/* ---------------------------------------------------------------------
 * ヘッダーの「シャッフル再生」（管理者だけ。partials/header.php の a[data-shuffle]）
 *   外枠 player.php の中で押したときは、外枠を読み直さずに範囲だけ渡す（鳴っている音を切らない）
 *   外枠の外では普通のリンク（target="_top"）として外枠へ移る
 * ------------------------------------------------------------------- */
function setupShuffleLinks() {
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-shuffle]');
    let shell = null;
    try { shell = window.top !== window ? window.top.abbeyShuffle : null; } catch (err) { /* 別のサイトの中 */ }
    if (!a || !shell) return;
    e.preventDefault();
    a.closest('details')?.removeAttribute('open');
    shell(a.dataset.shuffle, Number(a.dataset.shuffleId) || 0);
  });
}

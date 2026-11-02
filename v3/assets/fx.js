/**
 * =====================================================================
 *  fx.js — サイト全体の「豪華な演出」専用 JavaScript（fx.css とセット）
 * =====================================================================
 *  やること（CSS だけでは作れないものを担当する）
 *    1. スクロールで要素が順番にせり上がる（IntersectionObserver）
 *    2. 数字のカウントアップ（集計の数字）
 *    3. 棒グラフが画面に入ったら左から伸びる
 *    4. 見出しに光の帯を1回走らせる
 *    5. スクロール量バー / ヘッダーの影
 *    6. ライブカードの 3D の傾きと、マウスを追いかける光
 *    7. クリックの輪っか（ping）、紙吹雪（いいね・メダル・成功のお知らせ）
 *
 *  ■ スイッチ
 *    <html> に fx クラスが付いていなければ、何もしないで終わる。
 *    fx クラスを付けるかどうかは partials/header.php の <head> のスクリプトが決める（FX_MODE）。
 *    fx-lite が付いているときは、重い演出（傾き・光・紙吹雪の数）を減らす。
 *
 *  ■ 他の JS（app.js）とのつきあい方
 *    app.js の中身は一切触らない。DOM に印（クラス）を付け外しするだけ。
 *    ここで例外が起きても本体が止まらないよう、各機能は try/catch で独立させている。
 * =====================================================================
 */
(() => {
  'use strict'; // うっかりミス（宣言していない変数への代入など）をエラーにする書き方

  const root = document.documentElement;
  if (!root.classList.contains('fx')) return; // 演出 OFF → 何もしない

  const lite = root.classList.contains('fx-lite');
  const noReveal = root.classList.contains('fx-noreveal'); // 編集画面など、位置の計算が大事なページでは出現演出を使わない
  // マウスのある環境か（スマホ・タブレットでは傾きと光を使わない）
  const finePointer = matchMedia('(hover: hover) and (pointer: fine)').matches;

  /** 1つの機能が失敗しても、ほかの機能を止めないための包み紙 */
  const safe = (fn) => { try { fn(); } catch (e) { console.warn('[fx]', e); } };

  /* ===================================================================
   * 1〜3. スクロールで順番に出す / 棒グラフ / カウントアップ
   * =================================================================== */
  safe(() => {
    if (noReveal || !('IntersectionObserver' in window)) { root.classList.remove('fx-boot'); return; }

    /**
     * 「出現させたい要素」の集まりを作る。
     * 同じ親の中での順番（0,1,2…）を --fx-i に入れ、CSS の animation-delay で少しずつ遅らせる。
     * cap … 遅れの上限（20番目の要素が1秒以上待たされないように）
     */
    const targets = [];
    const tag = (list, cap) => {
      const counter = new Map(); // 親ごとに数える
      list.forEach((el) => {
        if (el.closest('dialog, [hidden], .toasts, .sticky-actions')) return; // ダイアログ内などは触らない
        const n = counter.get(el.parentElement) || 0;
        counter.set(el.parentElement, n + 1);
        el.style.setProperty('--fx-i', Math.min(n, cap));
        targets.push(el);
      });
    };

    // 画面の一番外側のブロック（fx.css の fx-boot と同じ条件）
    tag([...document.querySelectorAll('.container > :not(.stats-filter, .toasts, .grid, .stats-grid, dialog, [hidden], .sticky-actions, .day-bar)')], 6);
    // カードの並び（ライブ一覧・集計のカードなど）
    tag([...document.querySelectorAll('.container > .grid > *, .container > .stats-grid > *')], 10);
    // ヒーローの数字の箱（.stats > div）
    tag([...document.querySelectorAll('.hero .stats > div')], 4);
    // ランキングの行（上位だけ。多すぎると重いので最初の 60 行まで）
    tag([...document.querySelectorAll('.ranking > li')].slice(0, 60), 8);
    // 表の行（タイムテーブルの編集表 .table--tt は並べ替えをするので除外。最初の 40 行まで）
    tag([...document.querySelectorAll('.table:not(.table--tt) tbody > tr')].slice(0, 40), 10);

    /** 画面に入った要素を表に出す */
    const reveal = (el) => {
      el.classList.remove('fx-wait');
      el.classList.add('fx-in');
      // アニメが終わったら印を外す（fx-in を残すと、あとで同じアニメが再生されることがある）
      const done = (ev) => {
        if (ev.target !== el) return; // 中の子要素のアニメ終了には反応しない
        el.classList.remove('fx-in');
        el.style.removeProperty('--fx-i');
        el.removeEventListener('animationend', done);
      };
      el.addEventListener('animationend', done);
    };

    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        io.unobserve(entry.target);
        reveal(entry.target);
      });
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.04 }); // 下の 6% に入ったら出す（端ぎりぎりで出ないように）

    targets.forEach((el) => { el.classList.add('fx-wait'); io.observe(el); });
    root.classList.remove('fx-boot'); // 仮の非表示を外す（ここまで同じ処理の中なので、ちらつかない）

    /* ---- 棒グラフ: 画面に入ったら伸びる ---- */
    const bars = [...document.querySelectorAll('.bar')].slice(0, 80);
    const barIo = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        barIo.unobserve(entry.target);
        // 次の描画で外す → CSS の transition（scaleX 0 → 1）が動く
        requestAnimationFrame(() => entry.target.classList.remove('fx-bar-wait'));
      });
    }, { threshold: 0.2 });
    bars.forEach((b) => { b.classList.add('fx-bar-wait'); barIo.observe(b); });

    /* ---- 数字のカウントアップ ----
       対象: .stats dd と [data-countup]。「123」「1,234」のような数字だけの文字（子要素なし）に限る。
       「12:30」「3回」のような他の文字が混ざるものは触らない（壊さないため） */
    const counters = [...document.querySelectorAll('.stats dd, [data-countup]')]
      .filter((el) => el.children.length === 0 && /^\d{1,3}(,\d{3})*$|^\d+$/.test(el.textContent.trim()));
    const countIo = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        countIo.unobserve(entry.target);
        countUp(entry.target);
      });
    }, { threshold: 0.6 });
    counters.forEach((el) => countIo.observe(el));
  });

  /** 0 → 目標の数字へ、だんだん遅くなりながら数える（easeOutExpo）。 */
  function countUp(el) {
    const text = el.textContent.trim();
    const target = parseInt(text.replace(/,/g, ''), 10);
    if (!Number.isFinite(target) || target < 3) return; // 0〜2 は数えても退屈なのでそのまま
    const withComma = text.includes(',');
    const duration = Math.min(1400, 500 + target * 3); // 大きい数ほど少し長く（最大 1.4 秒）
    const fmt = (n) => (withComma ? n.toLocaleString('en-US') : String(n));
    const start = performance.now();
    el.textContent = fmt(0);
    const tick = (now) => {
      const t = Math.min(1, (now - start) / duration);
      const eased = t === 1 ? 1 : 1 - Math.pow(2, -10 * t); // easeOutExpo: 最初は速く、最後はゆっくり
      el.textContent = fmt(Math.round(target * eased));
      if (t < 1) requestAnimationFrame(tick);
      else el.textContent = text; // 最後は元の文字にぴったり戻す（桁区切りなどがずれないように）
    };
    requestAnimationFrame(tick);
  }

  /* ===================================================================
   * 4. 見出し（最初の .display）に光の帯を1回走らせる
   * =================================================================== */
  safe(() => {
    const h = document.querySelector('.display');
    if (!h) return;
    h.classList.add('fx-shine');
    // 終わったら外す（背景の切り抜きが残ると、あとで文字の色を変えたときに透明のままになる）
    h.addEventListener('animationend', () => h.classList.remove('fx-shine'), { once: true });
    setTimeout(() => h.classList.remove('fx-shine'), 3000); // animationend が来なかったときの保険
  });

  /* ===================================================================
   * 5. スクロール量バー / ヘッダーの影
   * =================================================================== */
  safe(() => {
    const bar = document.createElement('div');
    bar.className = 'fx-progress';
    bar.setAttribute('aria-hidden', 'true'); // スクリーンリーダーには読ませない飾り
    document.body.appendChild(bar);
    const topbar = document.querySelector('.topbar');
    let waiting = false; // スクロールは1秒に何十回も起きるので、描画1回につき1回だけ計算する（requestAnimationFrame）
    const update = () => {
      waiting = false;
      const max = root.scrollHeight - innerHeight;
      bar.style.transform = `scaleX(${max > 0 ? Math.min(1, scrollY / max) : 0})`;
      if (topbar) topbar.classList.toggle('fx-stuck', scrollY > 8);
    };
    addEventListener('scroll', () => { if (!waiting) { waiting = true; requestAnimationFrame(update); } }, { passive: true });
    update();
  });

  /* ===================================================================
   * 6. ライブカードの傾きと、マウスを追いかける光（マウスのある環境・軽量モードでないときだけ）
   * =================================================================== */
  safe(() => {
    if (!finePointer || lite) return;
    document.addEventListener('pointermove', (e) => {
      if (e.pointerType !== 'mouse') return;
      const card = e.target.closest && e.target.closest('.live-card, .container > .grid > .card');
      if (!card) return;
      const r = card.getBoundingClientRect();
      const x = e.clientX - r.left;
      const y = e.clientY - r.top;
      card.style.setProperty('--mx', `${x}px`); // 光の中心
      card.style.setProperty('--my', `${y}px`);
      if (card.classList.contains('live-card')) {
        // マウスが右にあるほど右側が奥に、下にあるほど下側が奥に傾く（最大 ±4 度）
        card.style.setProperty('--tx', `${((y / r.height - 0.5) * -8).toFixed(2)}deg`);
        card.style.setProperty('--ty', `${((x / r.width - 0.5) * 8).toFixed(2)}deg`);
      }
    }, { passive: true });
  });

  /* ===================================================================
   * 7. クリックの輪っか / 紙吹雪
   * =================================================================== */

  /** 紙吹雪。(x, y) は画面上の座標（px）。Web Animations API（要素.animate）で動かし、終わったら消す */
  function confetti(x, y, count, colors) {
    if (!Element.prototype.animate) return;
    const palette = colors || ['#d90f0f', '#f39a3d', '#f7d774', '#2563eb', '#13915c', '#d6338a'];
    const n = lite ? Math.ceil(count / 2) : count;
    for (let i = 0; i < n; i++) {
      const p = document.createElement('i');
      p.className = 'fx-confetti';
      const size = 6 + Math.random() * 6;
      p.style.left = `${x}px`;
      p.style.top = `${y}px`;
      p.style.width = `${size}px`;
      p.style.height = `${size * (Math.random() < 0.5 ? 1 : 0.45)}px`; // 正方形と細長い紙を混ぜる
      p.style.background = palette[i % palette.length];
      document.body.appendChild(p);
      const angle = Math.random() * Math.PI * 2;
      const dist = 60 + Math.random() * 100;
      const dx = Math.cos(angle) * dist;
      const dy = Math.sin(angle) * dist - 70; // 少し上向きに打ち上げる
      const rot = Math.random() * 720 - 360;
      p.animate([
        { transform: 'translate(-50%, -50%) rotate(0deg) scale(1)', opacity: 1 },
        { transform: `translate(calc(-50% + ${dx}px), calc(-50% + ${dy}px)) rotate(${rot / 2}deg) scale(1)`, opacity: 1, offset: 0.45 },
        { transform: `translate(calc(-50% + ${dx * 1.2}px), calc(-50% + ${dy + 130}px)) rotate(${rot}deg) scale(.6)`, opacity: 0 }, // 最後は落ちながら消える
      ], { duration: 900 + Math.random() * 600, easing: 'cubic-bezier(.2,.7,.3,1)' }).onfinish = () => p.remove();
    }
  }

  safe(() => {
    // クリックの輪っか
    document.addEventListener('click', (e) => {
      const el = e.target.closest && e.target.closest('.btn, .icon-btn, .nav__link');
      if (!el || el.disabled) return;
      el.classList.remove('fx-ping');
      void el.offsetWidth; // 一度描き直させて、アニメを最初からやり直す（app.js の is-pop と同じ手）
      el.classList.add('fx-ping');
      setTimeout(() => el.classList.remove('fx-ping'), 650);
    });

    // いいね(♥): 押して「いいね済み」になったら、ハートから紙吹雪。
    //   app.js が先に状態を変える（clickの後）ので、少し待ってから見る
    document.addEventListener('click', (e) => {
      const like = e.target.closest && e.target.closest('.like');
      if (!like) return;
      setTimeout(() => {
        if (!like.classList.contains('is-liked')) return; // 外した（取り消した）ときは出さない
        const r = like.getBoundingClientRect();
        confetti(r.left + r.width / 2, r.top + r.height / 2, 12, ['#d90f0f', '#ff6b81', '#f39a3d', '#ffd1d9']);
      }, 160);
    });

    // メダルを押したとき: 金色の紙吹雪（ポップアップは app.js が出す）
    document.addEventListener('click', (e) => {
      const medal = e.target.closest && e.target.closest('.medal-btn');
      if (!medal) return;
      const r = medal.getBoundingClientRect();
      confetti(r.left + r.width / 2, r.top + r.height / 2, 10, ['#f7d774', '#c2920a', '#fff3b0', '#e3e7ec']);
    });

    // 成功のお知らせ（「登録しました」など）が出たら、お祝いの紙吹雪
    const ok = document.querySelector('[data-toasts] .toast.flash--success');
    if (ok) {
      setTimeout(() => {
        const r = ok.getBoundingClientRect();
        if (r.width === 0) return; // まだ表示されていない（チュートリアルの後に出す場合）
        confetti(r.left + r.width / 2, r.top + r.height / 2, 22);
      }, 450);
    }
  });
})();

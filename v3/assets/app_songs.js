/*
 * app_songs.js — 曲の編集・曲の紐付け・歌詞検索・30秒試聴
 *   app.js の続き。全ページで app.js の後に読み込み、setup◯◯ は app.js の DOMContentLoaded から呼ぶ。
 */

/* ---------------------------------------------------------------------
 * 曲の編集（songs_edit.php）
 *   data-add-song       … <template data-song-template> をコピーして空の曲カードを足す（data-add-song-se は SE 用）
 *   data-performer-on   … チェックを外した人を薄く表示
 *   data-omnibus        … オムニバスのチェック。外すとアーティスト欄をバンドのアーティストに戻して編集不可にする。
 *                         付けると編集できるようにして、前に書いていた名前 → 連携済みの曲のアーティスト → 空欄 の順で入れる
 * ------------------------------------------------------------------- */
function setupSongs() {
  // 保存するとき、曲名の入った曲（SE・削除した曲を除く）の数と曲数を比べる
  //   曲数が空 → その数で埋めるか聞く（いいえなら保存しない）
  //   曲数と違う → セットリストの数に変えるか聞く（いいえなら曲数はそのまま保存）
  //   はい / いいえ（askYesNo）は答えを待つので、いったん送信を止め、答えたら同じボタンで送り直す（asked: 2回目は聞かない）
  let asked = false;
  document.querySelector('.songs-form')?.addEventListener('submit', async (e) => {
    const count = e.target.querySelector('input[name="song_count"]');
    if (!count || asked) { asked = false; return; }
    const n = [...e.target.querySelectorAll('[data-song-card]:not(.song-card--se)')]
      .filter((c) => c.querySelector('.song-card__title')?.value.trim() && c.querySelector('[data-song-delete]')?.value !== '1').length;
    const now = count.value.trim();
    if (!n || (now !== '' && Number(now) === n)) return;
    e.preventDefault();
    const form = e.target;
    const submitter = e.submitter;
    if (now === '') {
      if (!(await askYesNo(`曲数が空です。${n}曲で保存しますか？`))) return;
      count.value = n;
    } else if (await askYesNo(`曲数が${now}曲で登録されています。セットリストの${n}曲に変更しますか？`)) {
      count.value = n;
    }
    asked = true;
    form.requestSubmit(submitter);
  });

  // バンド編集の2つ目の楽器の「＋」: 1つ目の楽器の下に開閉。閉じたら値を空（なし）に戻す
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-inst2-toggle]');
    if (!btn) return;
    const row = btn.closest('.member-row-edit');
    const slot = row.querySelector('[data-inst2-slot]');
    const select = slot.querySelector('select');
    const open = !slot.classList.contains('is-open');
    slot.classList.toggle('is-open', open);
    btn.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open);
    if (open) { (slot.querySelector('.live-pick__btn') || select).focus({ preventScroll: true }); }
    else { select.value = ''; select.dispatchEvent(new Event('change', { bubbles: true })); }
  });

  // 演奏者の楽器の「＋」:下に楽器2の段を出して、＋ は隠す（3つ目以降は無い）
  document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-add-inst]');
    if (!add) return;
    const second = add.closest('.performer').querySelector('[data-inst2]');
    second.hidden = false;
    add.hidden = true;
    (second.querySelector('.live-pick__btn') || second.querySelector('select')).focus();
  });
  const list = document.querySelector('[data-song-list]');
  if (!list) return;

  const omnibus = document.querySelector('[data-omnibus]');
  const defaultArtist = list.dataset.defaultArtist;
  omnibus.addEventListener('change', () => {
    list.querySelectorAll('[data-song-artist]').forEach((input) => {
      if (omnibus.checked) {
        input.readOnly = false;
        // 前に書いていた名前を戻す。無ければ、連携済み（紐付け済み）の曲は紐付けた曲のアーティストを残す。
        //   それも無ければ空欄（オムニバスはバンドのアーティストを持たないので、バンドの名前は入れない）
        //   ||（OR）: 左が空文字のときも右を使う（?? だと空文字 '' は「ある」扱いで右に進まない）
        const linked = input.closest('[data-song-card]')?.querySelector('[data-track-key]')?.dataset.artist;
        input.value = input.dataset.typed || linked || '';
      } else {
        input.dataset.typed = input.value; // チェックを付け直したときのために覚えておく
        input.value = defaultArtist;
        input.readOnly = true;
      }
    });
  });

  // 「何曲目」を画面の上から 1, 2, 3... と振り直す（カードを足した・消したとき）。消えている途中のカードは数えない
  //   並び順の隠し項目（data-song-pos）も同じ順に振り直す。保存はこの順（songs_edit.php）
  const cards = () => [...list.querySelectorAll('[data-song-card]:not(.is-leaving)')];
  const renumber = () => {
    let n = 1; // SE は数えない（曲の番号は 1, 2, 3...）
    cards().forEach((c, i) => {
      const no = c.querySelector('[data-song-no]'); // SE のカードには番号が無い
      if (no && !c.classList.contains('is-deleted')) no.textContent = String(n++); // 削除した曲（見えない）は数えない
      const pos = c.querySelector('[data-song-pos]');
      if (pos) pos.value = String(i);
    });
    // ↻（1曲だけ検出し直す）の区間: 自分の秒〜次の曲の秒。自分の秒が無ければ、前の曲の秒〜次の曲の秒の最後の曲（data-tail）。
    //   どちらも決まらなければ出さない。秒は data-video-start（空 = わからない）
    //   動画が2本以上のときは、同じ動画（data-video-no）の曲だけで前後を見る
    const live = cards().filter((c) => !c.classList.contains('is-deleted'));
    const secs = live.map((c) => { const v = c.querySelector('[data-video-start]')?.value ?? ''; return v === '' ? null : Number(v); });
    const nos = live.map((c) => c.querySelector('[data-video-no]')?.value || '0');
    live.forEach((c, i) => {
      const retry = c.querySelector('[data-detect-retry]');
      if (!retry) return;
      const same = (s, j) => s !== null && nos[j] === nos[i];
      const prev = secs.slice(0, i).filter(same).pop() ?? 0; // 前に秒のわかる曲が無い（最初の SE など）: 動画の頭から
      const next = secs.slice(i + 1).find((s, j) => same(s, i + 1 + j)) ?? null;
      const from = secs[i] ?? (next === null ? null : prev);
      retry.hidden = from === null;
      retry.dataset.from = from ?? '';
      retry.dataset.to = next ?? '';
      retry.dataset.tail = secs[i] === null ? '1' : '';
      retry.dataset.video = nos[i];
    });
  };
  renumber();

  // ---- 曲の並び替え（≡ をドラッグ / ≡ にフォーカスして ↑↓ キー） ----
  //   指の位置が上か下のカードの真ん中を越えたら、そのカードと入れ替える
  let drag = null; // ドラッグ中だけ { card, pointerId, grab（カードの上端からつかんだ所までの距離）, y（今の指の高さ）, raf（自動スクロールの予約） }
  // 滑っている途中・指に付いている途中のずれ（transform の translateY）を引いた「本当の位置」の上端
  const layoutTop = (el) => el.getBoundingClientRect().top - new DOMMatrixReadOnly(getComputedStyle(el).transform).m42;
  // 入れ替えのアニメーション（FLIP）: 動かす前の位置を測る → DOM を入れ替える → 前の位置から今の位置へ滑らせる
  //   skip = ドラッグ中のカード（指に付いているので滑らせない）
  const move = (change, skip = null) => {
    const before = new Map(cards().map((c) => [c, c.getBoundingClientRect().top]));
    change();
    renumber();
    if (reduceMotion) return;
    cards().forEach((c) => {
      if (c === skip) return;
      c.getAnimations().forEach((a) => a.cancel()); // 前のアニメーションの途中なら止めてから測る
      const dy = before.get(c) - c.getBoundingClientRect().top;
      if (Math.abs(dy) < 1) return;
      c.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 200, easing: 'ease-out' });
    });
  };
  list.addEventListener('pointerdown', (e) => {
    const handle = e.target.closest('[data-song-handle]');
    if (!handle || drag || (e.pointerType === 'mouse' && e.button !== 0)) return;
    e.preventDefault(); // 文字の選択が始まらないように
    const card = handle.closest('[data-song-card]');
    // 縮んだあとのカードより下をつかんでいても、カードが指から外れないよう ≡ の行の高さに収める
    const headH = card.querySelector('.song-card__head').offsetHeight + 16;
    drag = { card, pointerId: e.pointerId, grab: Math.min(e.clientY - card.getBoundingClientRect().top, headH) };
    drag.card.classList.add('is-dragging');
    list.classList.add('is-sorting'); // ドラッグ中だけ、全カードのアーティスト欄から下を縮める
    handle.setPointerCapture(e.pointerId); // 指やマウスが ≡ の外に出ても追いかける
    drag.y = e.clientY;
    drag.raf = requestAnimationFrame(autoScroll);
  });
  // 指の高さ y に合わせて、入れ替え（上下のカードの真ん中を越えたら）とカードの位置を更新する
  const follow = (y) => {
    const all = cards();
    const i = all.indexOf(drag.card);
    const prev = all[i - 1];
    const next = all[i + 1];
    // 真ん中の位置は、滑っている途中のずれを引いた「本当の位置」で測る（途中の位置で測ると行ったり来たりする）
    const mid = (el) => layoutTop(el) + el.offsetHeight / 2;
    if (prev && y < mid(prev)) {
      move(() => list.insertBefore(drag.card, prev), drag.card);
    } else if (next && y > mid(next)) {
      move(() => list.insertBefore(drag.card, next.nextElementSibling), drag.card);
    }
    // つかんでいるカードを指に付いてこさせる（本当の位置からのずれを transform で付ける）
    drag.card.style.transform = `translateY(${y - drag.grab - layoutTop(drag.card)}px)`;
  };
  // 自動スクロール: 指が画面の上端・下端の近く（EDGE px 以内）にあるあいだ、ページを上下に動かす。端に近いほど速い
  //   スクロールすると指の下のカードが変わるので、毎フレーム follow() で入れ替えとカードの位置をやり直す
  //   EDGE は、スマホの下のナビ・上のヘッダーに指がかかっても届くように大きめ
  const EDGE = 100;
  const MAX_SPEED = 18; // 1フレーム（約 1/60 秒）に動かす最大の px
  const autoScroll = () => {
    if (!drag) return;
    const y = drag.y;
    const speed = y < EDGE ? -(EDGE - y) / EDGE : y > innerHeight - EDGE ? (y - (innerHeight - EDGE)) / EDGE : 0;
    if (speed) {
      const before = scrollY;
      scrollBy(0, Math.round(Math.max(-1, Math.min(1, speed)) * MAX_SPEED));
      if (scrollY !== before) follow(y); // 端まで行って動かなかったときはやり直さない
    }
    drag.raf = requestAnimationFrame(autoScroll);
  };
  list.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.pointerId) return;
    drag.y = e.clientY;
    follow(e.clientY);
  });
  const endDrag = (e) => {
    if (!drag || e.pointerId !== drag.pointerId) return;
    const { card } = drag;
    cancelAnimationFrame(drag.raf); // 自動スクロールを止める
    drag = null;
    list.classList.remove('is-sorting');
    // 離したら、指の位置から並びの位置へスッと戻す
    const dy = new DOMMatrixReadOnly(getComputedStyle(card).transform).m42;
    card.style.transform = '';
    const done = () => card.classList.remove('is-dragging');
    if (reduceMotion || Math.abs(dy) < 1) {
      done();
      return;
    }
    card.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 150, easing: 'ease-out' }).onfinish = done;
  };
  list.addEventListener('pointerup', endDrag);
  list.addEventListener('pointercancel', endDrag);
  list.addEventListener('keydown', (e) => {
    const handle = e.target.closest('[data-song-handle]');
    if (!handle || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault(); // ページがスクロールしないように
    const card = handle.closest('[data-song-card]');
    const all = cards();
    const i = all.indexOf(card);
    // キーボードのときは動かしたカードも滑らせる（skip なし）
    if (e.key === 'ArrowUp' && all[i - 1]) move(() => list.insertBefore(card, all[i - 1]));
    if (e.key === 'ArrowDown' && all[i + 1]) move(() => list.insertBefore(card, all[i + 1].nextElementSibling));
    handle.focus(); // 動かしたあともフォーカスを ≡ に残す（続けて押せるように）
  });

  // 空のカードの元（songs_edit.php の <template>）。template の中身は画面に出ず、送信もされない
  const template = list.querySelector('[data-song-template]');
  const templateSe = list.querySelector('[data-song-template-se]'); // SE 用（演奏者・アーティスト欄なし）
  // name="songs[3][title]" の 3 の部分。保存済みの曲（0, 1, 2...）より大きく、押すたびに 1 ずつ増やして被らないようにする
  //   （Date.now() をそのまま使うと、同じミリ秒に2回押したとき同じ番号になり、2曲が1曲に混ざる）
  let nextIndex = Date.now();
  const addCard = (tpl, atTop = false) => {
    const card = tpl.content.firstElementChild.cloneNode(true);
    const newIndex = nextIndex++;
    card.querySelectorAll('[name]').forEach((el) => {
      el.name = el.name.replace(/^songs\[[^\]]+\]/, `songs[${newIndex}]`);
    });
    // テンプレートはページを開いたときのオムニバスの状態で作られているので、今のチェックに合わせる
    const artist = card.querySelector('[data-song-artist]'); // SE には無い
    if (artist) {
      artist.value = omnibus.checked ? '' : defaultArtist;
      artist.readOnly = !omnibus.checked;
    }
    // ふわっと出す（CSS の .song-card.is-entering）。終わったらクラスを外しておく
    card.classList.add('is-entering');
    card.addEventListener('animationend', () => card.classList.remove('is-entering'), { once: true });
    list.insertBefore(card, atTop ? cards()[0] ?? null : null); // SE は一番上（オープニング SE）、曲は一番下
    renumber();
    card.querySelector('[name$="[title]"]').focus();
  };
  document.querySelector('[data-add-song]').addEventListener('click', () => addCard(template));
  document.querySelector('[data-add-song-se]').addEventListener('click', () => addCard(templateSe, true));

  // ---- 元に戻す / やり直す（保存前の操作を1つずつ） ----
  //   操作のたびに「全カードの並び・入力値・状態」を撮って（capture）積んでおき、戻すときはそれに合わせ直す（restore）。
  //   消したカード（要素）も持っておくので、戻せば入力ごと復活する。入力欄は change（欄を離れたとき）で1操作になる
  const undoBtn = document.querySelector('[data-song-undo]');
  const redoBtn = document.querySelector('[data-song-redo]');
  const cardIds = new WeakMap();
  let cardSeq = 0;
  const capture = () => {
    const snap = { omni: omnibus.checked, cards: cards().map((card) => {
      if (!cardIds.has(card)) cardIds.set(card, ++cardSeq);
      return {
        card,
        fields: [...card.querySelectorAll('[name]')].map((el) => ({ el, value: el.value, checked: el.checked, disabled: el.disabled })),
        deleted: card.classList.contains('is-deleted'),
        inst: [...card.querySelectorAll('[data-inst2], [data-add-inst]')].map((el) => ({ el, hidden: el.hidden })),
        thumb: card.querySelector('[data-track-thumb]').innerHTML,
        thumbTitle: card.querySelector('.song-thumb').title,
        clearHidden: card.querySelector('[data-track-clear]').hidden,
      };
    }) };
    // 中身が同じ操作を積まないための印（欄の値・並び順・削除の印・紐付け）
    snap.sig = JSON.stringify([snap.omni, snap.cards.map((s) => [cardIds.get(s.card), s.fields.map((f) => [f.value, f.checked]), s.deleted, s.inst.map((i) => i.hidden), s.clearHidden])]);
    return snap;
  };
  let restoring = false;
  const restore = (snap) => {
    restoring = true;
    const keep = new Set(snap.cards.map((s) => s.card));
    cards().forEach((c) => { if (!keep.has(c)) c.remove(); });
    omnibus.checked = snap.omni;
    snap.cards.forEach((s) => {
      const c = s.card;
      c.getAnimations().forEach((a) => a.cancel());
      c.classList.remove('is-leaving', 'is-entering', 'is-dragging');
      c.style.cssText = '';
      list.appendChild(c);
      s.fields.forEach(({ el, value, checked, disabled }) => {
        const changed = el.value !== value || el.checked !== checked;
        el.value = value;
        el.checked = checked;
        el.disabled = disabled;
        if (changed || el.matches('select')) el.dispatchEvent(new Event('change', { bubbles: true })); // 見た目（楽器のボタン・薄い表示）を合わせさせる
      });
      c.classList.toggle('is-deleted', s.deleted);
      s.inst.forEach(({ el, hidden }) => { el.hidden = hidden; });
      c.querySelector('[data-track-thumb]').innerHTML = s.thumb;
      c.querySelector('.song-thumb').title = s.thumbTitle;
      c.querySelector('[data-track-clear]').hidden = s.clearHidden;
      const artist = c.querySelector('[data-song-artist]');
      if (artist) artist.readOnly = !snap.omni;
    });
    renumber();
    restoring = false;
  };
  const history = [capture()];
  let pos = 0;
  const refreshHistoryBtns = () => {
    undoBtn.disabled = pos <= 0;
    redoBtn.disabled = pos >= history.length - 1;
  };
  const commit = () => {
    if (restoring) return;
    const snap = capture();
    if (snap.sig === history[pos].sig) return;
    history.length = pos + 1; // 戻したあとに新しい操作をしたら、やり直しは捨てる
    history.push(snap);
    if (history.length > 50) history.shift();
    pos = history.length - 1;
    refreshHistoryBtns();
  };
  const step = (d) => {
    commit(); // 先に今の状態を積んでおく（積み残しがあれば、それも戻せるように）
    const next = pos + d;
    if (next < 0 || next >= history.length) return;
    pos = next;
    restore(history[pos]);
    refreshHistoryBtns();
  };
  undoBtn.addEventListener('click', () => step(-1));
  redoBtn.addEventListener('click', () => step(1));
  refreshHistoryBtns();
  // 操作のあとに積む（他の処理が終わってから撮るので setTimeout 0）
  const later = () => setTimeout(commit, 0);
  list.addEventListener('change', later);
  list.addEventListener('click', later);
  list.addEventListener('keyup', (e) => { if (e.key === 'ArrowUp' || e.key === 'ArrowDown') later(); });
  list.addEventListener('pointerup', later);
  omnibus.addEventListener('change', later);
  document.querySelectorAll('[data-add-song], [data-add-song-se]').forEach((b) => b.addEventListener('click', later));

  // カードが後から増えるので、list でまとめてイベントを受ける（イベント委譲）
  list.addEventListener('click', (e) => {
    const del = e.target.closest('[data-song-delete-btn]');
    if (del) {
      const card = del.closest('[data-song-card]');
      // 追加したばかりの曲: まだ保存していないので、カードごと消すだけ
      if (card.classList.contains('song-card--new')) {
        if (card.classList.contains('is-leaving')) return; // 消えている途中にもう一度押された
        card.classList.add('is-leaving');
        // 消えるアニメーションの途中で保存されても送られないように、先に入力欄を無効にしておく（disabled の欄は送信されない）
        card.querySelectorAll('[name]').forEach((el) => { el.disabled = true; });
        renumber();
        if (reduceMotion) {
          card.remove();
          return;
        }
        // 薄くなりながら高さを 0 まで縮める。高さはカードごとに違うので、今の高さを測ってから animate() で動かす。
        //   margin-bottom: -12px … .song-list の gap（カードの間の 12px）のぶんも詰める
        card.style.overflow = 'hidden';
        card.animate([
          { opacity: 1, transform: 'none', height: `${card.offsetHeight}px` },
          { opacity: 0, transform: 'scale(.98)', height: '0px', paddingTop: '0px', paddingBottom: '0px', borderWidth: '0px', marginBottom: '-12px' },
        ], { duration: 220, easing: 'ease-in' }).onfinish = () => card.remove();
        return;
      }
      // 保存済みの曲: 画面からはすぐ消す。データベースから消えるのは保存したとき（戻す ↩ で復活できる）
      card.classList.add('is-deleted');
      card.querySelector('[data-song-delete]').value = '1';
      renumber();
    }
  });
  list.addEventListener('change', (e) => {
    if (e.target.matches('[data-performer-on]')) {
      e.target.closest('.performer').classList.toggle('is-off', !e.target.checked);
    }
  });
}

/* ---------------------------------------------------------------------
 * 曲を Spotify / iTunes の曲と紐付ける（songs_edit.php）
 *   data-track-search  … 🔍「曲名 アーティスト」で api_track_search.php に聞いて、候補をカードの中に出す
 *   data-track-results … 候補の一覧。押すとその曲を紐付ける（隠し項目 data-track-key にキーを入れる）
 *                        オムニバスなら、アーティスト欄も選んだ曲のアーティストにする
 *   data-track-clear   … 紐付けを外す（ジャケットに重なったリンクが切れるマーク）
 *   ここで入れるのはキー（"spotify:xxxx"）だけ。曲名やジャケットは保存するときにサーバーが取り直す
 * ------------------------------------------------------------------- */

/** Material Symbols のアイコン（PHP の icon() と同じ形）を作る。名前は textContent で入れる */
// AI まわりのエラーの理由（API の detail）を出す ⓘ。押しても何も実行しないので data-tip
function infoTip(detail) {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'info-tip';
  btn.dataset.tip = detail;
  btn.setAttribute('aria-label', '補足を表示');
  btn.append(iconEl('info'));
  return btn;
}

function iconEl(name) {
  const span = document.createElement('span');
  span.className = 'icon';
  span.setAttribute('aria-hidden', 'true');
  span.textContent = name;
  return span;
}

/** カードの紐付けを変える。track = 候補1件（{key, title, artist_name, artwork_url}）/ null = 外す */
function setTrack(card, track) {
  const keyInput = card.querySelector('[data-track-key]');
  keyInput.value = track ? track.key : '';
  keyInput.dataset.artist = track ? track.artist_name.split(', ')[0] : ''; // オムニバスに切り替えたとき残すアーティスト（songs_edit.php の data-artist と同じ）
  const thumb = card.querySelector('[data-track-thumb]'); // ジャケットの中身（画像か ♪）
  thumb.replaceChildren();
  if (track) {
    const img = document.createElement('img');
    img.src = track.artwork_url;
    img.alt = '';
    thumb.appendChild(img);
    thumb.parentElement.title = `${track.title} / ${track.artist_name}`;
  } else {
    thumb.appendChild(iconEl('music_note'));
    thumb.parentElement.title = '';
  }
  card.querySelector('[data-track-clear]').hidden = !track;
  closeResults(card.querySelector('[data-track-results]'));
}

/**
 * 検索結果の窓を、アニメーションしてから閉じる。
 *   hidden を付けると一瞬で消えてしまうので、先に is-closing（CSS で縮みながら消える）を付けて、
 *   アニメーションが終わってから hidden を付ける。
 *   閉じている途中でまた開いたら（openResults）、閉じるのをやめる。
 */
function closeResults(results) {
  if (results.hidden || results.classList.contains('is-closing')) return;
  results.classList.add('is-closing');
  results.dispatchEvent(new Event('preview-stop', { bubbles: true })); // 中で鳴っている試聴を止める（中身は消えるので、止めないと鳴り続ける）
  const done = () => {
    if (!results.classList.contains('is-closing')) return; // 途中で開き直された
    results.classList.remove('is-closing');
    results.hidden = true;
    results.replaceChildren();
  };
  // 中の候補のアニメーションの終わり（泡のように上がってくる）では閉じないよう、窓そのもののときだけ
  const onEnd = (e) => {
    if (e.target !== results) return;
    results.removeEventListener('animationend', onEnd);
    done();
  };
  results.addEventListener('animationend', onEnd);
  setTimeout(done, 300); // 動きを減らす設定などで animationend が来ないときの保険
}

function openResults(results) {
  results.classList.remove('is-closing');
  results.hidden = false;
}

function setupTrackSearch() {
  const list = document.querySelector('[data-song-list]');
  if (!list) return;

  // 候補1件ぶんのボタンを作る。外から来た文字は textContent で入れる（innerHTML だと XSS になりうる）
  const optionButton = (track) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'track-option';
    const img = document.createElement('img');
    img.src = track.artwork_url;
    img.alt = '';
    img.loading = 'lazy';
    const text = document.createElement('span');
    const title = document.createElement('div');
    title.className = 'track-option__title';
    title.textContent = track.title;
    const sub = document.createElement('div');
    sub.className = 'track-option__sub';
    sub.textContent = `${track.artist_name} ・ ${track.album_title}`;
    text.append(title, sub);
    btn.append(img, text);
    return btn;
  };
  const message = (results, text, detail) => {
    const p = document.createElement('p');
    p.className = 'track-results__msg';
    p.textContent = text;
    if (detail) p.append(' ', infoTip(detail));
    results.replaceChildren(p);
    openResults(results);
  };

  // 候補1件ぶんの行（押すと紐付ける・▶ で試聴）
  const trackRow = (card, track) => {
    const row = document.createElement('div');
    row.className = 'track-option-row';
    const btn = optionButton(track);
    btn.addEventListener('click', () => {
      // 選んだ曲の正式な曲名で置き換える（打ち間違い・表記ゆれをそろえる）
      card.querySelector('[name$="[title]"]').value = track.title;
      // オムニバスのときだけ、選んだ曲のアーティストを入れる。
      const artist = card.querySelector('[data-song-artist]');
      if (artist && !artist.readOnly) artist.value = track.artist_name.split(', ')[0];
      setTrack(card, track);
    });
    row.append(btn);
    if (track.preview_url) {
      const play = document.createElement('button');
      play.type = 'button';
      play.className = 'track-option__play';
      play.dataset.preview = track.key;
      play.dataset.previewUrl = track.preview_url; // 候補はまだ保存前なので、URL を直接渡す
      play.setAttribute('aria-label', track.title + 'を試聴');
      play.title = '30秒試聴';
      play.append(Object.assign(iconEl('play_arrow'), { className: 'icon icon--fill' }));
      row.append(play);
    }
    return row;
  };

  // ↻ で除外する曲名（data-exclude）: 候補に出た曲を足していき、↻ を押すたびに送る。「出た候補を戻す」で空にする
  const excluded = (card) => { try { return JSON.parse(card.dataset.exclude || '[]'); } catch { return []; } };
  const exclude = (card, titles) => { card.dataset.exclude = JSON.stringify([...new Set([...excluded(card), ...titles.filter(Boolean)])]); };
  const resetButton = (card) => {
    if (!card.querySelector('[data-detect-retry]') || !excluded(card).length) return [];
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn--ghost btn--sm';
    btn.title = '↻ で、もう出た候補も探す';
    btn.append(iconEl('restart_alt'), '出た候補を戻す');
    btn.addEventListener('click', () => { delete card.dataset.exclude; delete card.dataset.compared; btn.remove(); });
    return [btn];
  };

  // 動画から曲を検出して自信が低かった曲（data-candidates）: Gemini の候補を1曲ずつ探し、最初に当たった曲を候補欄に並べる
  //   探しても見つからない候補は、曲名だけのボタンにする（押すと曲名を入れる）
  //   afterRetry: ↻ で出した候補のときだけ「出た候補を戻す」を付ける（最初の検出の候補では押す意味がない）
  const showCandidates = async (card, afterRetry = false) => {
    const results = card.querySelector('[data-track-results]');
    let cands = [];
    try { cands = JSON.parse(card.dataset.candidates); } catch { return; }
    exclude(card, cands.map((c) => c.title));
    message(results, '検索中…');
    const rows = [];
    const seen = new Set();
    for (const c of cands) {
      try {
        const res = await fetch(`api_track_search?q=${encodeURIComponent(`${c.title} ${c.artist}`.trim())}`, { credentials: 'same-origin' });
        // 検索は似た曲も返すので、曲名が候補と同じもの（全角半角・大文字小文字・空白は無視）だけ採る
        const norm = (t) => t.normalize('NFKC').toLowerCase().replace(/\s/g, '');
        const track = res.ok ? (await res.json()).tracks?.find((t) => norm(t.title).startsWith(norm(c.title))) : null;
        if (track) {
          if (!seen.has(track.key)) rows.push(trackRow(card, track));
          seen.add(track.key);
          continue;
        }
      } catch { /* 見つからないときと同じ扱い */ }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'track-option';
      const title = document.createElement('div');
      title.className = 'track-option__title';
      title.textContent = c.title;
      const sub = document.createElement('div');
      sub.className = 'track-option__sub';
      sub.textContent = c.artist;
      const text = document.createElement('span');
      text.append(title, sub);
      btn.append(iconEl('music_note'), text); // ジャケットの代わり（紐付いていない曲と同じ ♪）
      btn.addEventListener('click', () => {
        card.querySelector('[name$="[title]"]').value = c.title;
        closeResults(results);
      });
      const row = document.createElement('div');
      row.className = 'track-option-row';
      row.append(btn);
      rows.push(row);
    }
    results.replaceChildren(...rows, ...(afterRetry ? resetButton(card) : []));
    openResults(results);
  };

  // 曲名の欄で Enter → その曲の「曲を探す」を押す（フォームは送信しない）
  //   isComposing: 日本語入力の変換を確定する Enter では探さない
  list.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.isComposing || e.keyCode === 229) return;
    if (!e.target.matches('[name$="[title]"]')) return;
    e.preventDefault();
    const card = e.target.closest('[data-song-card]');
    // 候補が開いていたら閉じ始めておく。ボタンは「開いているときに押すと閉じる」ので、これで探し直しになる
    closeResults(card.querySelector('[data-track-results]'));
    card.querySelector('[data-track-search]').click();
  });

  list.addEventListener('click', async (e) => {
    const card = e.target.closest('[data-song-card]');
    if (!card) return;

    if (e.target.closest('[data-track-clear]')) {
      setTrack(card, null);
      return;
    }

    // ↻（動画から曲を検出したあと）: この曲の区間だけ Gemini に聴かせ直し、候補を出し直す
    const retry = e.target.closest('[data-detect-retry]');
    if (retry) {
      const results = card.querySelector('[data-track-results]');
      const body = new FormData();
      body.append('band_id', document.querySelector('[data-detect-player]')?.dataset.band || '');
      body.append('from', retry.dataset.from);
      body.append('to', retry.dataset.to);
      body.append('tail', retry.dataset.tail);
      body.append('video', retry.dataset.video || '0');
      exclude(card, [card.querySelector('[name$="[title]"]').value.trim()]); // ↻ を押すのは今の曲名が違うとき
      excluded(card).forEach((t) => body.append('exclude[]', t));
      if (!card.dataset.compared) body.append('compare', '1'); // 試聴との聴き比べは1回目（と「出た候補を戻す」の後）だけ
      card.dataset.compared = '1';
      if (card.classList.contains('song-card--se')) body.append('se', '1');
      retry.disabled = true;
      message(results, '解析しています');
      try {
        const res = await fetch('api_setlist_detect', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw Object.assign(new Error(data.error || '解析に失敗しました'), { detail: data.detail });
        card.dataset.candidates = JSON.stringify(data.candidates);
        await showCandidates(card, true);
      } catch (err) {
        message(results, err.message, err.detail);
        results.append(...resetButton(card)); // 「ほかの候補が見つかりませんでした」のとき
      } finally {
        retry.disabled = false;
      }
      return;
    }

    const searchBtn = e.target.closest('[data-track-search]');
    if (!searchBtn) return;
    const results = card.querySelector('[data-track-results]');
    if (!results.hidden && !results.classList.contains('is-closing')) { closeResults(results); return; } // もう一度押したら閉じる
    const title = card.querySelector('[name$="[title]"]').value.trim();
    if (title === '') {
      message(results, '先に曲名を入れてから探してください');
      return;
    }
    const artistInput = card.querySelector('[data-song-artist]'); // SE には無い
    const q = `${title} ${artistInput ? artistInput.value.trim() : ''}`.trim();
    message(results, '検索中…');
    searchBtn.disabled = true;
    try {
      // encodeURIComponent: 日本語や & などを URL で使える形にする
      const res = await fetch(`api_track_search?q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
      const data = await res.json().catch(() => ({})); // 空の応答でも JSON の読み取りエラーをそのまま出さない
      if (!res.ok || !Array.isArray(data.tracks)) throw new Error(data.error);
      if (data.tracks.length === 0) {
        message(results, '見つかりませんでした。曲名やアーティスト名を変えて探してください');
        return;
      }
      results.replaceChildren(...data.tracks.map((track) => trackRow(card, track)));
    } catch (err) {
      message(results, err.message || '検索できませんでした');
    } finally {
      searchBtn.disabled = false;
    }
  });

  // 概要欄・動画から来たとき（data-auto-search）: 紐付いていない曲の 🔍 を順に押して、候補を並べておく（自信が低い曲は Gemini の候補）
  //   一度に叩かないよう 400ms ずつずらす
  if (list.hasAttribute('data-auto-search')) {
    list.querySelectorAll('[data-song-card]:not([data-template])').forEach((card, i) => {
      const btn = card.querySelector('[data-track-search]');
      if (!btn || card.closest('template')) return;
      // 自信が低い曲は、第1候補が自動で紐付いていても、ほかの候補を見せる
      if (card.dataset.candidates) setTimeout(() => showCandidates(card), i * 400);
      // 曲名が空の曲（動画から曲を検出して、わからなかった曲）は探さない
      else if (!card.querySelector('[name$="[track]"]')?.value && card.querySelector('[name$="[title]"]').value.trim()) setTimeout(() => btn.click(), i * 400);
    });
  }
}

/* ---------------------------------------------------------------------
 * 動画から曲を検出したあとの確認画面（songs_edit.php?from_detect=1）
 *   data-detect-player … バンドの YouTube（enablejsapi=1 の埋め込み）
 *   data-seek="秒"      … 押すと動画をその曲の場面から流す。鳴っている試聴は止める
 *   data-video="ID"     … その曲の動画（2本目以降なら動画を切り替えてから流す）
 *   試聴（data-preview）を押したら動画を止める。2つの音が重ならないように
 *   YouTube へは postMessage で命令だけ送る（IFrame API のスクリプトは読まない）
 * ------------------------------------------------------------------- */
function setupDetectPlayer() {
  const frame = document.querySelector('[data-detect-player] iframe');
  if (!frame) return;
  const send = (func, args = []) => frame.contentWindow?.postMessage(JSON.stringify({ event: 'command', func, args }), 'https://www.youtube.com');
  let current = frame.parentElement.dataset.video; // 今出している動画
  // 動画を替える（①② のタブも合わせる）
  const show = (video, start) => {
    current = video;
    frame.parentElement.querySelectorAll('[data-detect-video]').forEach((t) => t.classList.toggle('is-active', t.dataset.detectVideo === video));
    send('loadVideoById', [video, start]); // 読み込んで、その秒から流す
  };
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-preview]')) { send('pauseVideo'); return; }
    const tab = e.target.closest('[data-detect-video]');
    if (tab) { if (tab.dataset.detectVideo !== current) show(tab.dataset.detectVideo, 0); return; }
    const seek = e.target.closest('[data-seek]');
    if (!seek) return;
    document.querySelector('[data-preview].is-playing, [data-preview].is-loading')?.click(); // 鳴っている試聴を止める（もう一度押すと止まる）
    if (seek.dataset.video && seek.dataset.video !== current) {
      show(seek.dataset.video, Number(seek.dataset.seek));
      return;
    }
    send('seekTo', [Number(seek.dataset.seek), true]);
    send('playVideo');
  });
  // 動画が流れ始めたら試聴を止める（動画の中の ▶ で流したときも）。listening を送ると、YouTube が状態を知らせてくる
  const listen = () => frame.contentWindow?.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), 'https://www.youtube.com');
  frame.addEventListener('load', listen);
  listen();
  let last = null;
  window.addEventListener('message', (e) => {
    if (e.origin !== 'https://www.youtube.com' || e.source !== frame.contentWindow) return;
    let d;
    try { d = JSON.parse(e.data); } catch { return; }
    const state = d.event === 'onStateChange' ? d.info : d.info?.playerState;
    if (state === undefined || state === last) return; // 状態が変わったときだけ（再生中の知らせは何度も来る）
    last = state;
    if (state === 1) document.dispatchEvent(new Event('preview-stop')); // 1 = 再生中
  });
}

/* ---------------------------------------------------------------------
 * 歌詞の一部から曲を探す（管理者限定）
 *   検索語はサーバー経由で Musixmatch に送り、曲名・アーティストだけ取得する。
 *   歌詞本文は取得・表示・保存しない。試聴 URL は iTunes から取得して既存プレイヤーで再生する。
 * ------------------------------------------------------------------- */
function setupLyricsSearch() {
  const list = document.querySelector('[data-song-list]');
  if (!list) return;

  const showMessage = (results, text, detail) => {
    const p = document.createElement('p');
    p.className = 'track-results__msg';
    p.textContent = text;
    if (detail) p.append(' ', infoTip(detail));
    results.replaceChildren(p);
    openResults(results);
  };

  list.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.isComposing || e.keyCode === 229) return;
    if (!e.target.matches('[data-lyrics-query]')) return;
    e.preventDefault();
    e.target.closest('[data-song-card]')?.querySelector('[data-lyrics-search]')?.click();
  });

  list.addEventListener('click', async (e) => {
    const button = e.target.closest('[data-lyrics-search]');
    if (!button) return;
    const card = button.closest('[data-song-card]');
    const input = card?.querySelector('[data-lyrics-query]');
    const results = card?.querySelector('[data-lyrics-results]');
    if (!input || !results) return;
    const q = input.value.trim();
    if (q.length < 3) {
      showMessage(results, '歌詞を3文字以上入力してください');
      return;
    }
    if (q.length > 100) {
      showMessage(results, '検索語は100文字以内で入力してください');
      return;
    }

    button.disabled = true;
    showMessage(results, '歌詞から曲を検索中…');
    try {
      const omnibus = card.closest('form')?.querySelector('[data-omnibus]')?.checked ?? false;
      const artistInput = card.querySelector('[data-song-artist]');
      const artist = !omnibus ? (artistInput?.value.trim() || '') : '';
      const started = Date.now();
      const res = await fetch('api_lyrics_search', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
        credentials: 'same-origin',
        body: JSON.stringify({ q, artist }),
      });
      // 空の応答（サーバーが途中で切った）でも JSON の読み取りエラーをそのまま出さない
      const raw = await res.text();
      let data;
      try { data = JSON.parse(raw); } catch { // ⓘ に応答の頭を出す（空なら時間切れなどでサーバーが途中で切れた）
        data = { error: '歌詞検索に失敗しました。もう一度検索してください', detail: `HTTP ${res.status} ${raw.trim() ? raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200) : '（空の応答）'} ${Math.round((Date.now() - started) / 1000)}秒` }; // 秒数で、サーバーに何秒で切られたかがわかる
      }
      if (!res.ok || data.error) throw Object.assign(new Error(data.error || '歌詞検索に失敗しました'), { detail: data.detail });
      if (!Array.isArray(data.tracks) || data.tracks.length === 0) {
        showMessage(results, '曲の候補が見つかりませんでした。別の歌詞で検索してください');
        return;
      }

      const rows = data.tracks.slice(0, 10).map((track) => {
        const row = document.createElement('div');
        row.className = 'track-option';
        let img = iconEl('music_note'); // 照合して見つかったらジャケットに替える
        const text = document.createElement('span');
        const title = document.createElement('div');
        title.className = 'track-option__title';
        title.textContent = track.title;
        const sub = document.createElement('div');
        sub.className = 'track-option__sub';
        sub.textContent = track.artist_name;
        text.append(title, sub);
        const actions = document.createElement('span');
        actions.className = 'lyrics-result__actions';
        // 試聴が無い曲は、Google で聴き比べられるように（試聴ボタンと同じ見た目。編集中のページが移らないよう別タブ）
        const find = document.createElement('a');
        find.className = 'track-option__play';
        find.href = 'https://www.google.com/search?q=' + encodeURIComponent(`${track.artist_name} ${track.title}`);
        find.target = '_blank';
        find.rel = 'noopener';
        find.setAttribute('aria-label', track.title + 'を Google で検索');
        find.title = 'Google で検索';
        find.append(iconEl('search'));
        const play = document.createElement('button');
        play.type = 'button';
        play.className = 'track-option__play';
        play.dataset.preview = 'lyrics';
        play.setAttribute('aria-label', track.title + 'を試聴');
        play.title = '30秒試聴';
        play.append(Object.assign(iconEl('play_arrow'), { className: 'icon icon--fill' }));
        actions.append(play);
        // iTunes / Spotify との照合は ▶・選択を押したときに1回だけ（失敗したら次に押したときにやり直す）
        let matching = null;
        const match = () => (matching ??= apiPost('api_lyrics_search', { action: 'match', title: track.title, artist_name: track.artist_name, artist }, '曲を探せませんでした')
          .then(({ track: found }) => {
            if (found?.artwork_url) {
              const cover = Object.assign(document.createElement('img'), { src: found.artwork_url, alt: '' });
              img.replaceWith(cover);
              img = cover;
            }
            if (found) sub.textContent = [found.artist_name, found.album_title].filter(Boolean).join(' ・ ');
            if (!found?.preview_url) play.replaceWith(find);
            return found;
          })
          .catch((err) => { matching = null; throw err; }));
        play.lookupPreview = async () => (await match())?.preview_url ?? null; // setupPreview が ▶ を押したときに呼ぶ
        const pick = document.createElement('button');
        pick.type = 'button';
        pick.className = 'btn btn--ghost btn--sm';
        pick.textContent = '選択';
        pick.addEventListener('click', async () => {
          pick.disabled = true;
          let found = null;
          try {
            found = await match();
          } catch (err) {
            toastText(err.message, 'flash flash--warn'); // 曲名とアーティストだけ入れる
          }
          pick.disabled = false;
          card.querySelector('[name$="[title]"]').value = found?.title ?? track.title;
          const artistInput = card.querySelector('[data-song-artist]');
          if (artistInput && !artistInput.readOnly) artistInput.value = (found?.artist_name ?? track.artist_name).split(', ')[0];
          setTrack(card, found ? {
            key: found.source + ':' + found.track_id,
            title: found.title,
            artist_name: found.artist_name,
            artwork_url: found.artwork_url,
          } : null);
          closeResults(results);
        });
        actions.append(pick);
        row.append(img, text, actions);
        return row;
      });
      if (data.search_suggest) {
        // Google 検索の候補（Google 指定の HTML）。中の <style> がページに漏れないよう Shadow DOM に入れる
        const suggest = document.createElement('div');
        suggest.className = 'lyrics-suggest';
        const root = suggest.attachShadow({ mode: 'open' });
        // 部品は OS の明るさで色が変わるので、サイトの明るさ（data-theme）に合わせる
        const dark = document.documentElement.dataset.theme === 'dark';
        root.innerHTML = data.search_suggest
          .replaceAll('(prefers-color-scheme: dark)', dark ? 'all' : 'not all')
          .replaceAll('(prefers-color-scheme: light)', dark ? 'not all' : 'all');
        // そのままだと編集中のページが Google に移ってしまうので、別タブで開く
        root.querySelectorAll('a').forEach((a) => { a.target = '_blank'; a.rel = 'noopener'; });
        rows.push(suggest);
      }
      results.replaceChildren(...rows);
      openResults(results);
    } catch (err) {
      showMessage(results, err.message || '歌詞検索に失敗しました', err.detail);
    } finally {
      button.disabled = false;
    }
  });
}

/* ---------------------------------------------------------------------
 * 30秒試聴（バンドページのセットリストの ▶）
 *   data-preview="spotify:xxxx" … 押すと試聴を鳴らす。もう一度押すと止める。鳴るのは1曲ずつ
 *   data-preview-url             … 覚えてある音源の URL。無ければ api_track_preview.php に聞きに行く
 *   ・<audio> はページに1個だけ作って使い回す。押すまで何も読み込まない（通信量は1曲 約1MB）
 *   ・iPhone の Safari は「押したその瞬間」に play() しないと鳴らさない。URL を聞きに行っている間に
 *     その瞬間が過ぎてしまうので、押した瞬間に無音を鳴らして <audio> を使える状態にしておく
 *   ・覚えてある URL で鳴らなかったら（Apple 側で URL が変わったなど）、1回だけ探し直す
 *   ・どこまで聴いたかを --p（0〜1）に入れて、ボタンの丸い枠に出す（app.css の .setlist__play）
 *   ・音量は VOLUME（0〜1）。試聴の音源は音が大きいので下げておく
 *     iPhone の Safari は audio.volume を無視するので、本体の音量ボタンでしか変わらない
 * ------------------------------------------------------------------- */
function setupPreview() {
  // 0.01秒の無音（8kHz・8bit の WAV）。iPhone の Safari 用
  const SILENCE = 'data:audio/wav;base64,UklGRnQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YVAAAACAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgA==';
  const VOLUME = 0.4;
  const audio = new Audio();
  audio.volume = VOLUME; // 1回設定すれば src を替えても残る
  audio.preload = 'none';
  audio.crossOrigin = 'anonymous'; // iPhone のフェード（Web Audio）に必要。iTunes の試聴は CORS を許可している
  let current = null;   // 今鳴らしている（読み込み中の）ボタン
  let retried = false;  // 探し直しは1曲につき1回だけ
  const isSilence = () => audio.src.startsWith('data:');

  const setState = (btn, state) => { // 'loading' | 'play' | 'stop'
    btn.classList.toggle('is-loading', state === 'loading');
    btn.classList.toggle('is-playing', state === 'play');
    btn.querySelector('.icon').textContent = state === 'stop' ? 'play_arrow' : 'pause';
    if (state === 'stop') btn.style.removeProperty('--p');
  };
  const stop = (fade = false) => {
    if (fade) fadePause(audio);
    else { cancelFade(audio); audio.pause(); }
    if (current) setState(current, 'stop');
    current = null;
  };
  const noPreview = (btn) => {
    if (btn.closest('.track-results')) { btn.remove(); return; } // 曲の検索結果では、聴けないと分かった ▶ を消す
    btn.disabled = true;
    btn.classList.add('is-none');
    btn.title = '試聴がありません';
    btn.setAttribute('aria-label', '試聴がありません');
    btn.querySelector('.icon').textContent = 'music_off';
  };
  const fail = (text) => {
    toastText(text, 'flash flash--warn');
  };

  const start = async (btn, refresh = false) => {
    current = btn;
    setState(btn, 'loading');
    let url = refresh ? null : btn.dataset.previewUrl;
    if (!url) {
      try {
        if (btn.lookupPreview) { // 歌詞検索の候補: 押したときに初めて照合する
          url = await btn.lookupPreview();
        } else {
          const res = await fetch(`api_track_preview?track=${encodeURIComponent(btn.dataset.preview)}${refresh ? '&refresh=1' : ''}`,
            { credentials: 'same-origin' });
          const data = await res.json().catch(() => ({})); // 空の応答でも JSON の読み取りエラーをそのまま出さない
          if (!res.ok || data.url === undefined) throw new Error(data.error);
          url = data.url;
        }
      } catch (err) {
        if (current === btn) stop();
        fail(err.message || '試聴を探せませんでした');
        return;
      }
    }
    if (current !== btn) return; // 待っている間に止められた・別の曲が押された
    if (!url) {
      stop();
      noPreview(btn);
      return;
    }
    btn.dataset.previewUrl = url;
    audio.src = url;
    audio.play().catch(() => {}); // 鳴らなかったときは下の 'error' で扱う
  };

  // ボタンは後から差し替わる（楽曲データベースの一覧更新）ので、document で受ける
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-preview]');
    if (!btn || btn.disabled) return;
    if (current === btn) { stop(true); return; }
    stop();
    retried = false;
    if (!btn.dataset.previewUrl) { // URL を聞きに行く間に「押した瞬間」が過ぎるので、先に無音を鳴らしておく
      audio.src = SILENCE;
      audio.play().catch(() => {});
    }
    start(btn);
  });
  // 候補を閉じた・動画が流れ始めたら止める（target の中で鳴っているときだけ。document なら何でも）
  document.addEventListener('preview-stop', (e) => {
    if (current && (e.target === document || e.target.contains(current))) stop();
  });
  // モーダルを閉じたら止める（close は伝わらないのでキャプチャで受ける）
  document.addEventListener('close', (e) => {
    if (current && e.target.contains?.(current)) stop();
  }, true);

  audio.addEventListener('playing', () => { if (current && !isSilence()) setState(current, 'play'); });
  audio.addEventListener('timeupdate', () => {
    if (current && !isSilence() && audio.duration) current.style.setProperty('--p', audio.currentTime / audio.duration);
  });
  audio.addEventListener('ended', () => { if (!isSilence()) stop(); });
  audio.addEventListener('error', () => {
    if (!current || isSilence()) return;
    if (!retried) { retried = true; start(current, true); return; }
    const btn = current;
    stop();
    noPreview(btn);
  });

  // ページを開いたあと、試聴をまだ調べていない曲（band.php が data-lookup を付けたボタン）を、裏で 1 曲ずつ探す。
  //   ページの表示は待たせない（表示が終わってから動く）。見つかった URL はボタンに持たせるので、押したらすぐ鳴る。
  //   無かった曲は、押されたときと同じ「試聴がありません」の見た目にする。
  const LOOKUP_GAP_MS = 10000; // 1 曲ごとの間隔。1曲で iTunes を最大4回ほど叩くので、サーバー全体の上限（lib/itunes.php の1分15回）に収める
  const LOOKUP_MAX = 10;      // 1 回のページ表示で探す曲の上限
  const lookupAll = async () => {
    const targets = [...document.querySelectorAll('[data-preview][data-lookup]')].slice(0, LOOKUP_MAX);
    for (const btn of targets) {
      if (!btn.isConnected || btn.dataset.previewUrl || btn === current) continue; // 押された曲は、押した側が探す
      try {
        const res = await fetch(`api_track_preview?track=${encodeURIComponent(btn.dataset.preview)}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok) break; // iTunes に繋がらないなど。続けて叩かずに、ここで止める
        if (btn !== current && !btn.dataset.previewUrl) {
          if (data.url) btn.dataset.previewUrl = data.url;
          else noPreview(btn);
        }
      } catch {
        break;
      }
      await new Promise((resolve) => { setTimeout(resolve, LOOKUP_GAP_MS); });
    }
  };
  lookupAll();
}

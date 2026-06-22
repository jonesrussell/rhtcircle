/*
 * rht-anokii-chat.js: the public Co-Intelligence launcher for rhtcircle.ca.
 *
 * The chat is the navigation backbone: a persistent ask box on the home hero and
 * a site-wide launcher. It streams a grounded, cited answer from the hub's own
 * /api/chat endpoint (server-side key; the browser never sees a model key), with
 * a "find your nation" vantage switcher and section-aware suggested prompts.
 *
 * Opener "Aanii" (hello) is the greeting already used across the hub's copy;
 * chrome is otherwise English for v1, with richer keeper-reviewed Anishinaabemowin
 * openers a later refinement (see site/anishinaabemowin-sources.md). Never invent
 * Anishinaabemowin words.
 */
(function () {
  'use strict';

  // The 21 vantages plus treaty-wide. Slugs match config/anokii.yaml.
  var NATIONS = [
    ['', 'Treaty-wide'],
    ['batchewana', 'Batchewana'], ['garden-river', 'Garden River'], ['thessalon', 'Thessalon'],
    ['mississauga', 'Mississauga #8'], ['serpent-river', 'Serpent River'], ['sagamok', 'Sagamok Anishnawbek'],
    ['atikameksheng', 'Atikameksheng'], ['wahnapitae', 'Wahnapitae'], ['nipissing', 'Nipissing'],
    ['dokis', 'Dokis'], ['henvey-inlet', 'Henvey Inlet'], ['magnetawan', 'Magnetawan'],
    ['shawanaga', 'Shawanaga'], ['wasauksing', 'Wasauksing'], ['aundeck-omni-kaning', 'Aundeck Omni Kaning'],
    ['whitefish-river', 'Whitefish River'], ['mchigeeng', "M'Chigeeng"], ['sheguiandah', 'Sheguiandah'],
    ['sheshegwaning', 'Sheshegwaning'], ['wiikwemkoong', 'Wiikwemkoong'], ['zhiibaahaasing', 'Zhiibaahaasing']
  ];

  function suggestionsFor(path) {
    if (path.indexOf('/treaty') === 0) return ['What is the 2023 settlement?', 'Why was the annuity stuck at $4?', 'What did the Supreme Court decide?'];
    if (path.indexOf('/land/territory-and-safety') === 0) return ['What did Second Sons do here?', 'Who do I contact about community safety?'];
    if (path.indexOf('/land') === 0) return ['What is the Massey Solar project?', 'What energy projects are on the territory?'];
    if (path.indexOf('/standard') === 0 || path.indexOf('/treaty-wide') === 0) return ['What can I ask my council?', 'What is the records request?'];
    if (path.indexOf('/communities') === 0) return ['Find help near my nation', 'A crisis line right now'];
    if (path.indexOf('/circle') === 0 || path.indexOf('/about') === 0) return ['What is the Circle?', 'How do I take part?'];
    return ['What is the Robinson Huron Treaty?', 'Mental health help near me', 'Find my nation'];
  }

  var panel, log, input, vantageSel, sending = false;

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text != null) e.textContent = text;
    return e;
  }

  function build() {
    if (document.getElementById('rht-chat')) return;
    injectStyles();

    var launch = el('button', { id: 'rht-chat-launch', type: 'button', 'aria-expanded': 'false', 'aria-controls': 'rht-chat' }, 'Aanii. Ask about the treaty');
    launch.addEventListener('click', toggle);
    document.body.appendChild(launch);

    panel = el('section', { id: 'rht-chat', class: 'rht-chat', role: 'dialog', 'aria-label': 'Ask the Robinson Huron Treaty assistant', hidden: 'hidden' });

    var head = el('div', { class: 'rht-chat__head' });
    head.appendChild(el('p', { class: 'rht-chat__title' }, 'Aanii. Ask about the treaty and its 21 nations.'));
    var close = el('button', { type: 'button', class: 'rht-chat__close', 'aria-label': 'Close' }, '×');
    close.addEventListener('click', toggle);
    head.appendChild(close);
    panel.appendChild(head);

    var vrow = el('div', { class: 'rht-chat__vantage' });
    var vlabel = el('label', { for: 'rht-chat-vantage' }, 'Find your nation');
    vantageSel = el('select', { id: 'rht-chat-vantage' });
    NATIONS.forEach(function (n) { var o = el('option', { value: n[0] }, n[1]); vantageSel.appendChild(o); });
    vrow.appendChild(vlabel);
    vrow.appendChild(vantageSel);
    panel.appendChild(vrow);

    log = el('div', { class: 'rht-chat__log', 'aria-live': 'polite', 'aria-atomic': 'false' });
    panel.appendChild(log);

    renderSuggestions();

    var form = el('form', { class: 'rht-chat__form' });
    input = el('input', { id: 'rht-chat-input', type: 'text', autocomplete: 'off', maxlength: '500', placeholder: 'Ask a question...', 'aria-label': 'Your question' });
    var send = el('button', { type: 'submit', class: 'rht-chat__send' }, 'Ask');
    form.appendChild(input);
    form.appendChild(send);
    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    panel.appendChild(form);

    var note = el('p', { class: 'rht-chat__note' }, 'A community resource, not the Nation or the Fund. Confirm details with the office. For emergencies, call 911.');
    panel.appendChild(note);

    document.body.appendChild(panel);
  }

  function renderSuggestions() {
    var wrap = el('div', { class: 'rht-chat__suggest' });
    suggestionsFor(location.pathname).forEach(function (q) {
      var b = el('button', { type: 'button', class: 'rht-chat__chip' }, q);
      b.addEventListener('click', function () { ask(q); });
      wrap.appendChild(b);
    });
    log.appendChild(wrap);
  }

  function toggle() {
    var open = panel.hasAttribute('hidden');
    if (open) { panel.removeAttribute('hidden'); input && input.focus(); }
    else { panel.setAttribute('hidden', 'hidden'); }
    var l = document.getElementById('rht-chat-launch');
    if (l) l.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function open() { if (panel.hasAttribute('hidden')) toggle(); }

  function bubble(cls, text) {
    var b = el('div', { class: 'rht-chat__msg rht-chat__msg--' + cls }, text || '');
    log.appendChild(b);
    log.scrollTop = log.scrollHeight;
    return b;
  }

  function ask(question) {
    question = (question || '').trim();
    if (!question || sending) return;
    open();
    if (input) input.value = '';
    var community = vantageSel ? vantageSel.value : '';
    bubble('you', question);
    var answer = bubble('bot', '');
    answer.classList.add('is-streaming');
    sending = true;

    var sources = [];
    stream({ question: question, community: community }, function (delta) {
      answer.textContent += delta;
      log.scrollTop = log.scrollHeight;
    }, function (srcs) {
      sources = srcs || [];
      answer.classList.remove('is-streaming');
      if (sources.length) {
        var s = el('div', { class: 'rht-chat__sources' });
        s.appendChild(el('span', { class: 'rht-chat__sources-h' }, 'Sources'));
        sources.forEach(function (src) {
          var a = el('a', { href: src.source_url, target: src.source_url.indexOf('http') === 0 ? '_blank' : '_self', rel: 'noopener' }, src.title || src.source_url);
          s.appendChild(a);
        });
        answer.appendChild(s);
      }
      sending = false;
      log.scrollTop = log.scrollHeight;
    }, function () {
      answer.classList.remove('is-streaming');
      if (!answer.textContent) answer.textContent = 'Something went wrong reaching the assistant. Please try again, or use the page contacts.';
      sending = false;
    });
  }

  function stream(payload, onDelta, onDone, onError) {
    fetch('/api/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'omit'
    }).then(function (resp) {
      if (!resp.ok || !resp.body) { onError(); return; }
      var reader = resp.body.getReader();
      var dec = new TextDecoder();
      var buf = '';
      function pump() {
        return reader.read().then(function (r) {
          if (r.done) { return; }
          buf += dec.decode(r.value, { stream: true });
          var idx;
          while ((idx = buf.indexOf('\n\n')) >= 0) {
            handle(buf.slice(0, idx));
            buf = buf.slice(idx + 2);
          }
          return pump();
        });
      }
      function handle(raw) {
        var ev = '', data = '';
        raw.split('\n').forEach(function (line) {
          if (line.indexOf('event:') === 0) ev = line.slice(6).trim();
          else if (line.indexOf('data:') === 0) data += line.slice(5).trim();
        });
        if (!data) return;
        var parsed; try { parsed = JSON.parse(data); } catch (e) { return; }
        if (ev === 'delta' && parsed.text) onDelta(parsed.text);
        else if (ev === 'done') onDone(parsed.sources);
      }
      return pump();
    }).catch(onError);
  }

  function injectStyles() {
    if (document.getElementById('rht-chat-style')) return;
    var css =
      '#rht-chat-launch{position:fixed;right:18px;bottom:18px;z-index:50;background:var(--indigo,#4f2fb0);color:#fff;border:none;border-radius:999px;padding:12px 20px;font:600 15px/1 var(--sans,system-ui,sans-serif);box-shadow:0 6px 24px rgba(34,29,51,.25);cursor:pointer}' +
      '#rht-chat-launch:hover{background:var(--indigo-deep,#38217f)}' +
      '.rht-chat{position:fixed;right:18px;bottom:18px;z-index:51;width:min(420px,calc(100vw - 24px));max-height:min(80vh,640px);display:flex;flex-direction:column;background:#fff;border:1px solid var(--line,#e4def2);border-radius:16px;box-shadow:0 12px 40px rgba(34,29,51,.3);overflow:hidden;font-family:var(--sans,system-ui,sans-serif)}' +
      '.rht-chat__head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line,#e4def2);background:var(--paper-2,#f4f1fb)}' +
      '.rht-chat__title{margin:0;font-family:var(--serif,Georgia,serif);font-weight:600;font-size:15px;color:var(--indigo-deep,#38217f)}' +
      '.rht-chat__close{border:none;background:transparent;font-size:22px;line-height:1;color:var(--ink-3,#6f6688);cursor:pointer}' +
      '.rht-chat__vantage{display:flex;align-items:center;gap:8px;padding:10px 16px;border-bottom:1px solid var(--line,#e4def2);font-size:13px;color:var(--ink-2,#4a4361)}' +
      '.rht-chat__vantage select{flex:1;padding:6px 8px;border:1px solid var(--rule-strong,#d6cdea);border-radius:8px;font:inherit;background:#fff;color:var(--ink,#221d33)}' +
      '.rht-chat__log{flex:1;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:10px}' +
      '.rht-chat__suggest{display:flex;flex-wrap:wrap;gap:8px}' +
      '.rht-chat__chip{border:1px solid var(--accent-soft,#e8c4de);background:var(--accent-wash,#f9ecf4);color:var(--magenta-deep,#98146d);border-radius:999px;padding:7px 13px;font:500 13px/1.2 inherit;cursor:pointer;text-align:left}' +
      '.rht-chat__chip:hover{border-color:var(--magenta,#c41d8f)}' +
      '.rht-chat__msg{max-width:90%;padding:10px 13px;border-radius:12px;font-size:14.5px;line-height:1.5;white-space:pre-wrap}' +
      '.rht-chat__msg--you{align-self:flex-end;background:var(--indigo,#4f2fb0);color:#fff;border-bottom-right-radius:3px}' +
      '.rht-chat__msg--bot{align-self:flex-start;background:var(--paper-2,#f4f1fb);color:var(--ink,#221d33);border-bottom-left-radius:3px}' +
      '.rht-chat__msg--bot.is-streaming::after{content:"\\2026";opacity:.5}' +
      '.rht-chat__sources{margin-top:9px;padding-top:8px;border-top:1px solid var(--line,#e4def2);display:flex;flex-direction:column;gap:3px}' +
      '.rht-chat__sources-h{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-3,#6f6688)}' +
      '.rht-chat__sources a{font-size:13px;color:var(--indigo,#4f2fb0)}' +
      '.rht-chat__form{display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--line,#e4def2)}' +
      '.rht-chat__form input{flex:1;padding:10px 12px;border:1px solid var(--rule-strong,#d6cdea);border-radius:999px;font:inherit;color:var(--ink,#221d33)}' +
      '.rht-chat__send{border:none;background:var(--magenta,#c41d8f);color:#fff;border-radius:999px;padding:10px 18px;font:600 14px/1 inherit;cursor:pointer}' +
      '.rht-chat__note{margin:0;padding:0 16px 14px;font-size:11.5px;line-height:1.45;color:var(--ink-3,#6f6688)}' +
      '@media (max-width:520px){.rht-chat{right:0;bottom:0;width:100vw;max-height:85vh;border-radius:16px 16px 0 0}}';
    var style = el('style', { id: 'rht-chat-style' });
    style.textContent = css;
    document.head.appendChild(style);
  }

  function wireHero() {
    var form = document.getElementById('rht-ask-hero');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var f = form.querySelector('input');
      ask(f ? f.value : '');
      if (f) f.value = '';
    });
  }

  function init() { build(); wireHero(); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

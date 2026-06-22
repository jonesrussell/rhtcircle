/*
 * rht-analytics.js: first-party analytics and light page furniture for
 * rhtcircle.ca. Privacy-respecting: no cookies, no fingerprinting, no third
 * parties, no ad-tech.
 *
 * Tracking beacons (pageview + engagement) respect Do Not Track / Global
 * Privacy Control and are skipped entirely when set. The share control and the
 * aggregate "read count" are NOT tracking (they do not identify the reader), so
 * they still work under DNT.
 */
(function () {
  'use strict';

  var COUNT_FLOOR = 10; // don't show a read count below this (avoids "read 2 times")
  // Long-form content sections. The home page and short utility pages are left
  // without the furniture bar.
  var ARTICLE_RE = /^\/(treaty|communities|land|standard|safety|circle|about|treaty-wide)(\/|$)/;

  var dnt =
    navigator.doNotTrack === '1' ||
    window.doNotTrack === '1' ||
    navigator.globalPrivacyControl === true;

  // ---------------------------------------------------------------------------
  // Tracking beacons (skipped under Do Not Track)
  // ---------------------------------------------------------------------------
  if (!dnt) {
    var viewId =
      (crypto.randomUUID && crypto.randomUUID()) ||
      (Date.now().toString(36) + Math.random().toString(36).slice(2));

    var startTime = Date.now();
    var maxScroll = 0;
    var sent = false;
    var ticking = false;

    var send = function (payload) {
      try {
        fetch('/api/collect', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          keepalive: true,
          credentials: 'omit',
        });
      } catch (e) {
        // ignore
      }
    };

    send({ t: 'pageview', p: location.pathname, r: document.referrer || '', v: viewId });

    var computeScroll = function () {
      ticking = false;
      var doc = document.documentElement;
      var body = document.body;
      var scrollTop = window.pageYOffset || doc.scrollTop || (body && body.scrollTop) || 0;
      var viewportHeight = window.innerHeight || doc.clientHeight || 0;
      var documentHeight = Math.max(
        doc.scrollHeight,
        body ? body.scrollHeight : 0,
        doc.offsetHeight,
        body ? body.offsetHeight : 0,
        doc.clientHeight
      );
      if (documentHeight <= 0) {
        return;
      }
      var pct = Math.round(((scrollTop + viewportHeight) / documentHeight) * 100);
      if (pct < 0) pct = 0;
      if (pct > 100) pct = 100;
      if (pct > maxScroll) maxScroll = pct;
    };

    var onScroll = function () {
      if (!ticking) {
        ticking = true;
        requestAnimationFrame(computeScroll);
      }
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    computeScroll();

    var sendEngagement = function () {
      if (sent) return;
      sent = true;
      var json = JSON.stringify({
        t: 'engagement',
        v: viewId,
        s: maxScroll,
        d: Date.now() - startTime,
      });
      if (navigator.sendBeacon) {
        try {
          navigator.sendBeacon('/api/collect', new Blob([json], { type: 'application/json' }));
          return;
        } catch (e) {
          // fall through to fetch
        }
      }
      try {
        fetch('/api/collect', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: json,
          keepalive: true,
          credentials: 'omit',
        });
      } catch (e) {
        // ignore
      }
    };

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') {
        sendEngagement();
      }
    });
    window.addEventListener('pagehide', sendEngagement);
  }

  // ---------------------------------------------------------------------------
  // Page furniture: share control + aggregate read count (always, incl. DNT)
  // ---------------------------------------------------------------------------
  function shareUrl() {
    var canonical = document.querySelector('link[rel="canonical"]');
    return (canonical && canonical.href) || location.href;
  }

  function copyLink(button) {
    var url = shareUrl();
    var done = function () {
      var original = button.textContent;
      button.textContent = 'Link copied';
      setTimeout(function () {
        button.textContent = original;
      }, 2000);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done, function () {});
      return;
    }
    try {
      var input = document.createElement('input');
      input.value = url;
      document.body.appendChild(input);
      input.select();
      document.execCommand('copy');
      document.body.removeChild(input);
      done();
    } catch (e) {
      // ignore
    }
  }

  function onShare(button) {
    if (navigator.share) {
      navigator
        .share({ title: document.title, url: shareUrl() })
        .catch(function () {});
      return;
    }
    copyLink(button);
  }

  function injectStyles() {
    if (document.getElementById('rht-furniture-style')) return;
    var css =
      '.rht-share{margin-top:40px;padding-top:24px;border-top:1px solid var(--line,#e4def2);' +
      'display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;' +
      'font-family:var(--sans,system-ui,sans-serif)}' +
      '.rht-share__count{font-size:13px;color:var(--ink-3,#6f6688);font-variant-numeric:tabular-nums}' +
      '.rht-share__btn{font-family:inherit;font-size:12px;font-weight:600;letter-spacing:.06em;' +
      'text-transform:uppercase;color:var(--indigo,#4f2fb0);background:transparent;' +
      'border:1px solid var(--accent-soft,#e8c4de);border-radius:999px;padding:8px 16px;cursor:pointer;' +
      'transition:background .15s,color .15s}' +
      '.rht-share__btn:hover{background:var(--indigo,#4f2fb0);color:#fff}';
    var style = document.createElement('style');
    style.id = 'rht-furniture-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  function initFurniture() {
    if (!ARTICLE_RE.test(location.pathname)) return;
    if (document.getElementById('rht-furniture')) return;
    // Pages that ship their own share control (e.g. the records request sign-on)
    // opt out so we do not show two.
    if (document.querySelector('[data-rr]')) return;
    var main = document.querySelector('main') || document.body;
    if (!main) return;

    injectStyles();

    var bar = document.createElement('div');
    bar.className = 'rht-share';
    bar.id = 'rht-furniture';

    var count = document.createElement('span');
    count.className = 'rht-share__count';
    count.hidden = true;

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'rht-share__btn';
    button.textContent = navigator.share ? 'Share this page' : 'Copy link';
    button.addEventListener('click', function () {
      onShare(button);
    });

    bar.appendChild(count);
    bar.appendChild(button);
    main.appendChild(bar);

    fetch('/api/page-stats?path=' + encodeURIComponent(location.pathname), {
      credentials: 'omit',
    })
      .then(function (r) {
        return r.ok ? r.json() : null;
      })
      .then(function (data) {
        if (!data || typeof data.views !== 'number' || data.views < COUNT_FLOOR) return;
        count.textContent = 'Read ' + data.views.toLocaleString() + ' times';
        count.hidden = false;
      })
      .catch(function () {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFurniture);
  } else {
    initFurniture();
  }
})();

(function () {
  'use strict';
  if (typeof OPCVC === 'undefined' || !OPCVC.endpoint) return;

  var KEY = 'opcvc_visitor_id_v1';
  var refresh = Math.max(30000, Number(OPCVC.refresh) || 60000);

  function validId(value) { return typeof value === 'string' && /^[a-f0-9]{32}$/.test(value); }
  function newId() {
    var bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    return Array.prototype.map.call(bytes, function (n) { return n.toString(16).padStart(2, '0'); }).join('');
  }
  function getVisitorId() {
    var id = '';
    try { id = window.localStorage.getItem(KEY); } catch (e) { /* Storage may be blocked. */ }
    if (!validId(id)) {
      var match = document.cookie.match(/(?:^|;\s*)opcvc_id=([a-f0-9]{32})(?:;|$)/);
      if (match) id = match[1];
    }
    if (!validId(id)) id = newId();
    try { window.localStorage.setItem(KEY, id); } catch (e) { /* Fall back to cookie. */ }
    if (document.cookie.indexOf('opcvc_id=') < 0) {
      document.cookie = 'opcvc_id=' + id + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    }
    return id;
  }

  var visitorId = OPCVC.track ? getVisitorId() : null;
  var loading = false;

  function paint(data) {
    if (!data || typeof data !== 'object') return;
    document.querySelectorAll('[data-opcvc-value]').forEach(function (node) {
      var key = node.getAttribute('data-opcvc-value');
      if (Object.prototype.hasOwnProperty.call(data, key)) {
        var number = Number(data[key]);
        if (isFinite(number) && number >= 0) node.textContent = Math.floor(number).toLocaleString('en-IN');
      }
    });
  }
  function send() {
    if (loading) return;
    if (document.visibilityState === 'hidden') return;
    loading = true;
    var body = new URLSearchParams();
    body.append('action', OPCVC.track ? 'opcvc_ping' : 'opcvc_stats');
    if (visitorId) body.append('visitor_id', visitorId);
    fetch(OPCVC.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString()
    }).then(function (response) {
      if (!response.ok) throw new Error('Visitor counter request failed');
      return response.json();
    }).then(function (json) {
      if (json && json.success) paint(json.data);
    }).catch(function () {
      // Keep the section readable even if the connection is unavailable.
    }).finally(function () { loading = false; });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', send, { once: true });
  } else {
    send();
  }
  window.setInterval(send, refresh);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') send();
  });
})();

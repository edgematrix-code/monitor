/*
 * Visitor Monitor tracker. Add to every page you want to count:
 *   <script defer src="https://YOUR-DOMAIN/monitor/tracker.js"></script>
 * Sends the page address and referrer only. No cookies, no personal data.
 */
(function () {
  var script = document.currentScript;
  if (!script || !script.src) return;
  var endpoint = script.src.replace(/tracker\.js(\?.*)?$/, 'track.php');
  var referrer = document.referrer || '';
  var lastPath = null;

  function send() {
    var path = location.pathname + location.search;
    if (path === lastPath) return; // avoid double counting the same page
    lastPath = path;
    var payload = JSON.stringify({ u: location.href, r: referrer });
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(endpoint, payload)) {
        referrer = ''; // later in-site navigations have no outside referrer
        return;
      }
    } catch (e) {}
    var img = new Image();
    img.src = endpoint + '?u=' + encodeURIComponent(location.href) +
              '&r=' + encodeURIComponent(referrer) + '&t=' + Date.now();
    referrer = '';
  }

  send();

  // Count page changes on single-page apps too.
  var push = history.pushState;
  history.pushState = function () {
    push.apply(this, arguments);
    setTimeout(send, 0);
  };
  window.addEventListener('popstate', function () { setTimeout(send, 0); });
})();

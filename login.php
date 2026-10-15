<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/auth.php';

start_session();
if (current_user()) { header('Location: index.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'local';
    if (login_blocked($ip)) {
        $err = 'Terlalu banyak gagal. Coba lagi dalam 5 menit.';
    } else {
        $u = trim($_POST['username'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        $st = db()->prepare("SELECT pass_hash FROM users WHERE username=?");
        $st->execute([$u]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $p !== '' && password_verify($p, $row['pass_hash'])) {
            if (password_needs_rehash($row['pass_hash'], PASSWORD_ARGON2ID)) {
                db()->prepare("UPDATE users SET pass_hash=? WHERE username=?")
                    ->execute([password_hash($p, PASSWORD_ARGON2ID), $u]);
            }
            session_regenerate_id(true);
            $_SESSION['user'] = $u;
            $_SESSION['just_logged_in'] = 1;
            login_reset($ip);
            log_activity($u, 'login', $ip);
            if (($_SERVER['HTTP_X_LOGIN_MODE'] ?? '') === 'ajax') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            header('Location: index.php');
            exit;
        }
        login_fail($ip);
        $err = 'Username atau password salah.';
    }
}
if (($_SERVER['HTTP_X_LOGIN_MODE'] ?? '') === 'ajax' && $err !== '') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $err]);
    exit;
}
// Gagal login -> buka langsung di langkah password (tanpa bocorkan langkah mana yang salah).
$step = ($err !== '' || isset($_GET['setup'])) ? 2 : 1;
$posted_user = trim($_POST['username'] ?? '');
?>
<!doctype html><html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — dhexx panel</title>
<link rel="stylesheet" href="assets/style.css?v=14">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg"></head>
<body data-step="<?= $step ?>">
<div class="login-wrap">
  <div class="login-card" id="loginCard">
    <h1>dhexx / panel</h1>
    <p class="mono">dashboard / sign-in · <span id="stepNum"><?= $step ?>/2</span></p>
    <?php if (isset($_GET['setup'])): ?><div class="alert alert-ok">Admin dibuat. Silakan sign in.</div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-err"><?= e($err) ?></div><?php endif; ?>
    <form method="post" id="loginForm" novalidate>
      <div class="step" id="step1">
        <div style="margin-bottom:16px"><label class="lbl" for="inUser">Username</label>
          <input class="input mono" id="inUser" name="username" autocomplete="username" required autofocus value="<?= e($posted_user) ?>"></div>
        <button class="btn btn-primary" type="button" id="btnNext" style="width:100%;justify-content:center;padding:9px">Continue</button>
      </div>
      <div class="step" id="step2">
        <div class="whoami mono">login sebagai <b id="nameEcho"><?= e($posted_user !== '' ? $posted_user : '…') ?></b> · <button type="button" id="btnBack" class="linkbtn">ganti</button></div>
        <div class="alert alert-err" id="ajaxErr" hidden></div>
        <div style="margin-bottom:16px"><label class="lbl" for="inPass">Password</label>
          <input class="input" id="inPass" type="password" name="password" autocomplete="current-password" required></div>
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:9px">Sign in</button>
      </div>
      <noscript><div style="margin-top:12px"><button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:9px">Sign in</button></div></noscript>
    </form>
  </div>
</div>
<div class="wipe" id="wipe" hidden><div class="mono">masuk…</div><div class="barline"><i></i></div></div>
<script>
(function () {
  var s1 = document.getElementById('step1');
  var s2 = document.getElementById('step2');
  var card = document.getElementById('loginCard');
  var userIn = document.getElementById('inUser');
  var passIn = document.getElementById('inPass');
  var echo = document.getElementById('nameEcho');
  var num = document.getElementById('stepNum');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var startAt = document.body.getAttribute('data-step') || '1';

  function show(n, rise) {
    s1.hidden = (n !== 1);
    s2.hidden = (n !== 2);
    num.textContent = n + '/2';
    if (n === 2) {
      echo.textContent = userIn.value.trim() || '…';
      if (rise && !reduce) { s2.classList.remove('step-enter'); void s2.offsetWidth; s2.classList.add('step-enter'); }
      passIn.focus();
    } else {
      userIn.focus();
    }
  }

  // Awal: tanpa JS kedua langkah tampil (fallback). Dengan JS, tampilkan sesuai langkah.
  show(startAt === '2' ? 2 : 1, false);

  function rnd(a, b) { return a + Math.random() * (b - a); }

  function shatter(el, done) {
    var r = el.getBoundingClientRect();
    // Bekukan nilai input agar kepingan terlihat sama seperti kartu asli.
    [userIn].forEach(function (i) { i.setAttribute('value', i.value); });
    // Fallback browser tua tanpa Web Animations API.
    if (!Element.prototype.animate || reduce) { done(); return; }
    var finished = false;
    function fin() { if (!finished) { finished = true; done(); } }
    setTimeout(function () {
      var l = document.querySelector('.shatter-layer');
      if (l) l.remove();
      el.style.visibility = '';
      fin();
    }, 1500);
    var layer = document.createElement('div');
    layer.className = 'shatter-layer';
    document.body.appendChild(layer);
    var cols = 4, rows = 3, total = cols * rows, gone = 0;
    el.style.visibility = 'hidden';
    for (var y = 0; y < rows; y++) {
      for (var x = 0; x < cols; x++) {
        (function (x, y) {
          var w = r.width / cols, h = r.height / rows;
          var tile = document.createElement('div');
          tile.className = 'shard';
          tile.style.left = (r.left + x * w + window.scrollX) + 'px';
          tile.style.top = (r.top + y * h + window.scrollY) + 'px';
          tile.style.width = w + 'px';
          tile.style.height = h + 'px';
          var c = el.cloneNode(true);
          c.removeAttribute('id');
          c.style.visibility = 'visible';
          c.style.position = 'absolute';
          c.style.left = (-x * w) + 'px';
          c.style.top = (-y * h) + 'px';
          c.style.width = r.width + 'px';
          c.style.margin = '0';
          tile.appendChild(c);
          layer.appendChild(tile);
          var dx = rnd(-130, 130), dy = rnd(-110, 90), rot = rnd(-45, 45);
          var a = tile.animate(
            [{ transform: 'translate(0,0) rotate(0deg)', opacity: 1 },
             { transform: 'translate(' + dx + 'px,' + dy + 'px) rotate(' + rot + 'deg)', opacity: 0 }],
            { duration: 380 + Math.random() * 220, easing: 'cubic-bezier(.2,.7,.3,1)' }
          );
          a.onfinish = function () {
            tile.remove();
            if (++gone === total) { layer.remove(); el.style.visibility = ''; fin(); }
          };
        })(x, y);
      }
    }
  }

  document.getElementById('btnNext').addEventListener('click', function () {
    if (!userIn.value.trim()) { userIn.focus(); return; }
    if (reduce) { show(2, true); return; }
    shatter(card, function () { show(2, true); });
  });

  document.getElementById('btnBack').addEventListener('click', function () { show(1, false); });

  userIn.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('btnNext').click(); }
  });

  // Submit password via fetch: sukses -> wipe naik menutup layar -> pindah dashboard.
  // Gagal/no-JS/reduced-motion -> fallback POST biasa.
  var form = document.getElementById('loginForm');
  var wipe = document.getElementById('wipe');
  var ajaxErr = document.getElementById('ajaxErr');
  form.addEventListener('submit', function (e) {
    if (s2.hidden) return;
    if (reduce || !window.fetch || !window.FormData) return;
    e.preventDefault();
    ajaxErr.hidden = true;
    fetch('login.php', {
      method: 'POST',
      headers: { 'X-Login-Mode': 'ajax' },
      body: new FormData(form),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) {
        wipe.hidden = false;
        void wipe.offsetWidth;
        wipe.classList.add('go');
        setTimeout(function () { window.location = 'index.php'; }, reduce ? 30 : 420);
      } else {
        ajaxErr.textContent = (d && d.error) || 'Login gagal.';
        ajaxErr.hidden = false;
        passIn.focus();
        try { passIn.select(); } catch (x) {}
      }
    }).catch(function () { form.submit(); });
  });
})();
</script>
</body></html>

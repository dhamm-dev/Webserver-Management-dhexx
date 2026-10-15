<?php
// Modul Projects: CRUD metadata + deploy git via worker + webhook.
declare(strict_types=1);

/** Jalankan job worker dan tunggu hasil; kembalikan [hasil?, pesan]. */
function run_worker(string $op, array $args, string $user, string $logAction, string $logDetail): array {
    $id = queue_job($op, $args);
    if (!$id) return [null, 'Gagal membuat antrean.'];
    $res = job_result($id, 25);
    if ($res === null) return [null, 'Worker belum selesai (>25 dtk). Hasil tercatat di Logs bila selesai.'];
    if (!empty($res['ok'])) log_activity($user, $logAction, $logDetail);
    return [$res, ''];
}

function projects_handle(string $user): array {
    $flash = ['ok' => '', 'err' => ''];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return $flash;
    csrf_check();
    $act = $_POST['act'] ?? '';
    $pdo = db();

    if ($act === 'create' || $act === 'update') {
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? 'static';
        $repo = trim($_POST['repo'] ?? '');
        $branch = trim($_POST['branch'] ?? 'main') ?: 'main';
        $status = $_POST['status'] ?? 'active';
        $notes = trim($_POST['notes'] ?? '');
        if (!in_array($type, ['static','php','node','python','proxy'], true)) $type = 'static';
        if (!in_array($status, ['active','paused','archived'], true)) $status = 'active';
        if ($repo !== '' && !valid_repo($repo)) { $flash['err'] = 'URL repo harus https://… (publik).'; return $flash; }
        if (!valid_branch($branch)) { $flash['err'] = 'Branch tidak valid.'; return $flash; }
        if ($name === '' || strlen($name) > 80) { $flash['err'] = 'Nama wajib 1-80 karakter.'; return $flash; }
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($name));
        $slug = trim($slug, '-');
        if ($slug === '') { $flash['err'] = 'Nama tidak valid.'; return $flash; }
        $path = 'storage/projects/' . $slug;

        if ($act === 'create') {
            $full = APP_ROOT . '/' . $path;
            if (!is_dir($full)) mkdir($full, 0755, true);
            file_put_contents($full . '/README.txt', "Project: $name\nType: $type\nCreated: " . date('Y-m-d H:i') . "\n");
            $pdo->prepare("INSERT INTO projects(name,type,path,repo,branch,status,notes,created_at) VALUES(?,?,?,?,?,?,?,datetime('now','localtime'))")
                ->execute([$name, $type, $path, $repo, $branch, $status, $notes]);
            log_activity($user, 'project_create', $name);
            $flash['ok'] = 'Project dibuat: ' . $path;
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE projects SET name=?,type=?,repo=?,branch=?,status=?,notes=? WHERE id=?")
                ->execute([$name, $type, $repo, $branch, $status, $notes, $id]);
            log_activity($user, 'project_update', $name);
            $flash['ok'] = 'Project diupdate.';
        }
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT name FROM projects WHERE id=?");
        $st->execute([$id]);
        $n = $st->fetchColumn();
        $pdo->prepare("DELETE FROM projects WHERE id=?")->execute([$id]);
        log_activity($user, 'project_delete', (string)$n);
        $flash['ok'] = 'Project dihapus dari katalog (folder fisik dibiarkan).';
    }

    if ($act === 'webhook_regen') {
        setting_set('webhook_secret', bin2hex(random_bytes(24)));
        $flash['ok'] = 'Webhook secret baru dibuat.';
    }

    // Aksi git via worker (butuh path valid + repo)
    if (in_array($act, ['git_status', 'git_log', 'git_pull', 'git_clone'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM projects WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { $flash['err'] = 'Project tidak ada.'; return $flash; }
        $dir = project_dir($r['path']);
        if ($dir === null) { $flash['err'] = 'Path project di luar area deploy.'; return $flash; }
        $branch = trim($r['branch'] ?? 'main') ?: 'main';
        if (!valid_branch($branch)) { $flash['err'] = 'Branch tidak valid.'; return $flash; }
        $op = ['git_status' => 'git.status', 'git_log' => 'git.log', 'git_pull' => 'git.pull', 'git_clone' => 'git.clone'][$act];
        $args = ['dir' => $dir, 'branch' => $branch];
        if ($act === 'git_clone') {
            if (trim($r['repo'] ?? '') === '' || !valid_repo($r['repo'])) { $flash['err'] = 'Isi repo https dulu.'; return $flash; }
            $args['repo'] = trim($r['repo']);
        }
        [$res, $msg] = run_worker($op, $args, $user, 'deploy_' . substr($op, 4), $r['name']);
        if ($msg !== '') {
            $flash['err'] = $msg;
        } elseif ($res && !empty($res['ok'])) {
            $flash['ok'] = 'OK: ' . $r['name'];
            $_SESSION['git_out'] = $res['out'] ?? '';
        } else {
            $flash['err'] = 'Gagal: ' . (($res['out'] ?? '') !== '' ? $res['out'] : 'worker error');
        }
    }
    return $flash;
}

function projects_render(array $flash): void {
    start_session();
    $rows = db()->query("SELECT * FROM projects ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $t = csrf_token();
    if ($flash['ok']) echo '<div class="alert alert-ok">' . e($flash['ok']) . '</div>';
    if ($flash['err']) echo '<div class="alert alert-err">' . e($flash['err']) . '</div>';
    $out = $_SESSION['git_out'] ?? '';
    unset($_SESSION['git_out']);
    if ($out !== '') echo '<pre class="mono" style="background:var(--wash);border:1px solid var(--border);border-radius:8px;padding:12px;overflow-x:auto;font-size:12px;white-space:pre-wrap">' . e($out) . '</pre>';
    $edit = null;
    if (isset($_GET['edit'])) {
        $st = db()->prepare("SELECT * FROM projects WHERE id=?");
        $st->execute([(int)$_GET['edit']]);
        $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $secret = setting_get('webhook_secret');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    ?>
    <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2>Webhook deploy</h2></div>
      <div style="padding:16px" class="row">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="webhook_regen">
          <button class="btn btn-sm" type="submit"><?= $secret ? 'Regenerate secret' : 'Buat secret' ?></button>
        </form>
        <?php if ($secret): ?>
          <span class="muted mono" style="font-size:11px">POST ke URL per-project di bawah (kolom Deploy). Token = secret ini.</span>
        <?php else: ?>
          <span class="muted mono" style="font-size:11px">Belum ada secret — webhook nonaktif sampai dibuat.</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><?= $edit ? 'Edit project' : 'Project baru' ?></h2></div>
      <form method="post" style="padding:16px">
        <input type="hidden" name="csrf" value="<?= e($t) ?>">
        <input type="hidden" name="act" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="form-grid">
          <div><label class="lbl">Nama</label><input class="input" style="width:100%" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
          <div><label class="lbl">Tipe</label><select class="input" name="type">
            <?php foreach (['static','php','node','python','proxy'] as $o): ?>
              <option <?= ($edit['type'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option>
            <?php endforeach; ?></select></div>
          <div><label class="lbl">Repo https (opsional)</label><input class="input mono" style="width:100%" name="repo" placeholder="https://github.com/user/repo.git" value="<?= e($edit['repo'] ?? '') ?>"></div>
          <div><label class="lbl">Branch</label><input class="input mono" style="width:100%" name="branch" value="<?= e($edit['branch'] ?? 'main') ?>"></div>
          <div><label class="lbl">Status</label><select class="input" name="status">
            <?php foreach (['active','paused','archived'] as $o): ?>
              <option <?= ($edit['status'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option>
            <?php endforeach; ?></select></div>
        </div>
        <div style="margin-top:12px"><label class="lbl">Catatan</label><textarea class="input" style="width:100%" name="notes" rows="2"><?= e($edit['notes'] ?? '') ?></textarea></div>
        <div class="row" style="margin-top:12px">
          <button class="btn btn-primary" type="submit"><?= $edit ? 'Simpan' : 'Buat project' ?></button>
          <?php if ($edit): ?><a class="btn" href="?page=projects">Batal</a><?php endif; ?>
        </div>
      </form>
    </div>
    <div class="panel"><div class="panel-head"><h2><?= count($rows) ?> project</h2></div>
      <div class="tscroll"><table>
        <thead><tr><th>Name</th><th>Type</th><th>Path</th><th>Status</th><th>Deploy</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="muted">Belum ada project. Buat yang pertama di atas.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $pid = (int)$r['id']; ?>
          <tr>
            <td><b><?= e($r['name']) ?></b><br><span class="muted mono" style="font-size:11px"><?= e(trim($r['repo'] ?? '') !== '' ? $r['repo'] . ' @' . ($r['branch'] ?? 'main') : 'tanpa repo') ?></span></td>
            <td class="mono"><?= e($r['type']) ?></td>
            <td class="mono"><?= e($r['path']) ?></td>
            <td><span class="badge"><span class="dot <?= $r['status']==='active'?'dot-ok':($r['status']==='paused'?'dot-warn':'dot-bad') ?>"></span><?= e($r['status']) ?></span></td>
            <td><div class="row">
              <?php if (trim($r['repo'] ?? '') !== ''): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="git_status"><input type="hidden" name="id" value="<?= $pid ?>"><button class="btn btn-sm" type="submit">Status</button></form>
              <form method="post"><input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="git_log"><input type="hidden" name="id" value="<?= $pid ?>"><button class="btn btn-sm" type="submit">Log</button></form>
              <form method="post" onsubmit="return confirm('Pull <?= e($r['branch'] ?? 'main') ?> ke folder ini?')"><input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="git_pull"><input type="hidden" name="id" value="<?= $pid ?>"><button class="btn btn-sm btn-primary" type="submit">Pull</button></form>
              <form method="post" onsubmit="return confirm('Clone repo ke folder ini (harus kosong)?')"><input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="git_clone"><input type="hidden" name="id" value="<?= $pid ?>"><button class="btn btn-sm" type="submit">Clone</button></form>
              <?php else: ?><span class="muted mono" style="font-size:11px">isi repo dulu</span><?php endif; ?>
              <?php if ($secret && trim($r['repo'] ?? '') !== ''): ?>
                <span class="muted mono" style="font-size:10px">webhook:<br><?= e('https://' . $host . $base . '/webhook.php?token=' . $secret . '&project=' . $pid) ?></span>
              <?php endif; ?>
            </div></td>
            <td><div class="row">
              <a class="btn btn-sm" href="?page=projects&edit=<?= $pid ?>">Edit</a>
              <form method="post" onsubmit="return confirm('Hapus dari katalog?')">
                <input type="hidden" name="csrf" value="<?= e($t) ?>">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php
}

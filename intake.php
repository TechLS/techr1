<?php
declare(strict_types=1);
session_start();

/* ============================================================
   CONFIG  — edit these to taste
   ============================================================ */
const DB_PATH       = __DIR__ . '/repairs.sqlite';   // SQLite file location
const TICKET_PREFIX = 'LS';                           // ticket number prefix
const APP_NAME      = 'Repair Tracker';
const COMPANY       = 'Lauderdale Speedometer';

$STATUSES   = ['Received', 'Diagnosing', 'Awaiting Parts', 'In Progress', 'Ready for Pickup', 'Completed', 'Cancelled'];
$PRIORITIES = ['Low', 'Normal', 'High', 'Rush'];

/* ============================================================
   DATABASE
   ============================================================ */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        init_schema($pdo);
    }
    return $pdo;
}

function init_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        username   TEXT UNIQUE NOT NULL,
        pass_hash  TEXT NOT NULL,
        role       TEXT NOT NULL DEFAULT 'tech',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS tickets (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_no      TEXT UNIQUE NOT NULL,
        customer_name  TEXT NOT NULL,
        customer_phone TEXT,
        customer_email TEXT,
        device         TEXT NOT NULL,
        model          TEXT,
        serial         TEXT,
        qty            INTEGER NOT NULL DEFAULT 1,
        problem        TEXT,
        status         TEXT NOT NULL DEFAULT 'Received',
        priority       TEXT NOT NULL DEFAULT 'Normal',
        technician     TEXT,
        estimate       REAL,
        created_at     TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS history (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id  INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
        note       TEXT NOT NULL,
        author     TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_hist_ticket ON history(ticket_id)");
    $db->exec("CREATE TABLE IF NOT EXISTS ticket_items (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id  INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
        device     TEXT NOT NULL,
        model      TEXT,
        serial     TEXT,
        notes      TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_items_ticket ON ticket_items(ticket_id)");

    // Migration: add role column to older databases and promote the first user to admin
    $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
    if (!in_array('role', $cols, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'tech'");
        $db->exec("UPDATE users SET role='admin' WHERE id = (SELECT MIN(id) FROM users)");
    }
    // Migration: add qty (items checked in) column to older ticket tables
    $tcols = array_column($db->query("PRAGMA table_info(tickets)")->fetchAll(), 'name');
    if (!in_array('qty', $tcols, true)) {
        $db->exec("ALTER TABLE tickets ADD COLUMN qty INTEGER NOT NULL DEFAULT 1");
    }
    // Backfill: any ticket with no line items gets rows built from its legacy device/model/serial
    $legacy = $db->query("SELECT t.* FROM tickets t
        WHERE NOT EXISTS (SELECT 1 FROM ticket_items i WHERE i.ticket_id = t.id)")->fetchAll();
    if ($legacy) {
        $ins = $db->prepare("INSERT INTO ticket_items (ticket_id, device, model, serial) VALUES (?,?,?,?)");
        foreach ($legacy as $t) {
            $n = max(1, (int)($t['qty'] ?? 1));
            for ($k = 0; $k < $n; $k++) $ins->execute([$t['id'], $t['device'], $t['model'], $t['serial']]);
        }
    }
}

/* ============================================================
   HELPERS
   ============================================================ */
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid CSRF token — reload the page and try again.');
    }
}

function current_user(): ?string { return $_SESSION['user'] ?? null; }
function current_role(): string  { return $_SESSION['role'] ?? 'tech'; }
function is_admin(): bool         { return current_role() === 'admin'; }
function require_login(): void {
    if (!current_user()) { header('Location: ?page=login'); exit; }
}
function require_admin(): void {
    require_login();
    if (!is_admin()) { http_response_code(403); flash('Admins only.'); header('Location: ?page=dashboard'); exit; }
}
function admin_count(): int {
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
}
function user_count(): int {
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function generate_ticket_no(): string {
    // Unambiguous alphabet (no 0/O, 1/I) -> ~1 billion combos before collision matters
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $db = db();
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $no = TICKET_PREFIX . '-' . $code;
        $stmt = $db->prepare('SELECT 1 FROM tickets WHERE ticket_no = ?');
        $stmt->execute([$no]);
    } while ($stmt->fetchColumn());
    return $no;
}

function status_class(string $s): string {
    return 'st-' . strtolower(preg_replace('/[^a-z0-9]+/i', '', $s));
}
function add_history(int $ticketId, string $note): void {
    $stmt = db()->prepare("INSERT INTO history (ticket_id, note, author) VALUES (?,?,?)");
    $stmt->execute([$ticketId, $note, current_user()]);
}
function flash(string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}
function all_usernames(): array {
    return db()->query("SELECT username FROM users ORDER BY username COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
}
/* Line items for a ticket */
function ticket_items(int $ticketId): array {
    $stmt = db()->prepare("SELECT * FROM ticket_items WHERE ticket_id = ? ORDER BY id");
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll();
}
/* Parse the repeatable item_* arrays from a submitted form into clean rows.
   Each row: [device, model, serial, notes]. Rows with a blank device are dropped. */
function parse_items(array $post): array {
    $dev = $post['item_device'] ?? [];
    $mod = $post['item_model']  ?? [];
    $ser = $post['item_serial'] ?? [];
    $not = $post['item_notes']  ?? [];
    $rows = [];
    foreach ((array)$dev as $i => $d) {
        $d = trim((string)$d);
        if ($d === '') continue;
        $rows[] = [
            $d,
            trim((string)($mod[$i] ?? '')),
            trim((string)($ser[$i] ?? '')),
            trim((string)($not[$i] ?? '')),
        ];
    }
    return $rows;
}
/* Replace all line items for a ticket and sync the ticket summary fields. */
function save_items(int $ticketId, array $rows): void {
    $db = db();
    $db->prepare("DELETE FROM ticket_items WHERE ticket_id = ?")->execute([$ticketId]);
    $ins = $db->prepare("INSERT INTO ticket_items (ticket_id, device, model, serial, notes) VALUES (?,?,?,?,?)");
    foreach ($rows as $r) $ins->execute([$ticketId, $r[0], $r[1], $r[2], $r[3]]);
    $first = $rows[0] ?? ['', '', '', ''];
    $db->prepare("UPDATE tickets SET device=?, model=?, serial=?, qty=? WHERE id=?")
       ->execute([$first[0], $first[1], $first[2], count($rows), $ticketId]);
}
/* Build <option> list for the technician assignment dropdown.
   Preserves any legacy free-text value that isn't a current user. */
function tech_options(string $current): string {
    $out = '<option value="">— Unassigned —</option>';
    $found = false;
    foreach (all_usernames() as $u) {
        $sel = ($u === $current) ? ' selected' : '';
        if ($u === $current) $found = true;
        $out .= '<option'.$sel.'>'.e($u).'</option>';
    }
    if ($current !== '' && !$found) {
        $out .= '<option selected>'.e($current).'</option>';
    }
    return $out;
}

/* ============================================================
   CONTROLLER
   ============================================================ */
db(); // ensure schema
$page  = $_GET['page'] ?? 'dashboard';
$error = null;

// Force first-run setup before anything else
if (user_count() === 0 && $page !== 'setup') { header('Location: ?page=setup'); exit; }
if (user_count() > 0 && $page === 'setup')    { header('Location: ?page=login'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'setup') {
        $u  = trim($_POST['username'] ?? '');
        $p  = $_POST['password'] ?? '';
        $p2 = $_POST['confirm'] ?? '';
        if ($u === '' || strlen($p) < 8)       $error = 'Username required and password must be at least 8 characters.';
        elseif ($p !== $p2)                     $error = 'Passwords do not match.';
        else {
            $stmt = db()->prepare("INSERT INTO users (username, pass_hash, role) VALUES (?,?,'admin')");
            $stmt->execute([$u, password_hash($p, PASSWORD_BCRYPT)]);
            $_SESSION['user'] = $u; $_SESSION['role'] = 'admin';
            header('Location: ?page=dashboard'); exit;
        }
    }

    elseif ($do === 'login') {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        $stmt = db()->prepare("SELECT pass_hash, role FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $row = $stmt->fetch();
        if ($row && password_verify($p, $row['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = $u; $_SESSION['role'] = $row['role'];
            header('Location: ?page=dashboard'); exit;
        }
        $error = 'Invalid username or password.';
    }

    elseif ($do === 'create') {
        require_login();
        $items = parse_items($_POST);
        if (!$items) {
            flash('Add at least one item (a device name is required).');
            header('Location: ?page=new'); exit;
        }
        $first = $items[0];
        $no = generate_ticket_no();
        $stmt = db()->prepare("INSERT INTO tickets
            (ticket_no, customer_name, customer_phone, customer_email, device, model, serial, qty, problem, status, priority, technician, estimate)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $no,
            trim($_POST['customer_name'] ?? ''),
            trim($_POST['customer_phone'] ?? ''),
            trim($_POST['customer_email'] ?? ''),
            $first[0], $first[1], $first[2],
            count($items),
            trim($_POST['problem'] ?? ''),
            in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : 'Received',
            in_array($_POST['priority'] ?? '', $PRIORITIES, true) ? $_POST['priority'] : 'Normal',
            trim($_POST['technician'] ?? ''),
            ($_POST['estimate'] ?? '') === '' ? null : (float)$_POST['estimate'],
        ]);
        $id = (int) db()->lastInsertId();
        save_items($id, $items);
        add_history($id, "Ticket created ($no) with " . count($items) . " item(s)");
        flash("Created ticket $no");
        header('Location: ?page=view&id=' . $id); exit;
    }

    elseif ($do === 'update') {
        require_login();
        $id = (int)($_POST['id'] ?? 0);
        $cur = db()->prepare("SELECT * FROM tickets WHERE id = ?");
        $cur->execute([$id]);
        $old = $cur->fetch();
        if (!$old) { http_response_code(404); exit('Ticket not found'); }

        $newStatus = in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : $old['status'];
        $items = parse_items($_POST);
        if (!$items) {
            flash('A ticket needs at least one item (a device name is required).');
            header('Location: ?page=edit&id=' . $id); exit;
        }
        // Update non-item ticket fields; save_items() syncs device/model/serial/qty
        $stmt = db()->prepare("UPDATE tickets SET
            customer_name=?, customer_phone=?, customer_email=?,
            problem=?, status=?, priority=?, technician=?, estimate=?, updated_at=datetime('now')
            WHERE id=?");
        $stmt->execute([
            trim($_POST['customer_name'] ?? ''),
            trim($_POST['customer_phone'] ?? ''),
            trim($_POST['customer_email'] ?? ''),
            trim($_POST['problem'] ?? ''),
            $newStatus,
            in_array($_POST['priority'] ?? '', $PRIORITIES, true) ? $_POST['priority'] : $old['priority'],
            trim($_POST['technician'] ?? ''),
            ($_POST['estimate'] ?? '') === '' ? null : (float)$_POST['estimate'],
            $id,
        ]);
        save_items($id, $items);
        if ($newStatus !== $old['status']) {
            add_history($id, "Status: {$old['status']} \u{2192} {$newStatus}");
        }
        if (count($items) !== (int)$old['qty']) {
            add_history($id, "Items checked in changed: {$old['qty']} \u{2192} " . count($items));
        }
        flash('Ticket updated');
        header('Location: ?page=view&id=' . $id); exit;
    }

    elseif ($do === 'note') {
        require_login();
        $id = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') add_history($id, $note);
        header('Location: ?page=view&id=' . $id); exit;
    }

    elseif ($do === 'user_add') {
        require_admin();
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? 'tech') === 'admin' ? 'admin' : 'tech';
        if ($u === '' || strlen($p) < 8) {
            flash('Username required and password must be at least 8 characters.');
        } else {
            try {
                $stmt = db()->prepare("INSERT INTO users (username, pass_hash, role) VALUES (?,?,?)");
                $stmt->execute([$u, password_hash($p, PASSWORD_BCRYPT), $role]);
                flash("Added user '$u'.");
            } catch (PDOException $ex) {
                flash("Could not add user — '$u' already exists.");
            }
        }
        header('Location: ?page=users'); exit;
    }

    elseif ($do === 'user_role') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $role = ($_POST['role'] ?? 'tech') === 'admin' ? 'admin' : 'tech';
        $target = db()->query("SELECT username, role FROM users WHERE id=".$id)->fetch();
        if ($target && $target['role'] === 'admin' && $role === 'tech' && admin_count() <= 1) {
            flash('Cannot demote the last admin.');
        } else {
            $stmt = db()->prepare("UPDATE users SET role=? WHERE id=?");
            $stmt->execute([$role, $id]);
            if ($target && $target['username'] === current_user()) $_SESSION['role'] = $role;
            flash('Role updated.');
        }
        header('Location: ?page=users'); exit;
    }

    elseif ($do === 'user_reset') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $p  = $_POST['password'] ?? '';
        if (strlen($p) < 8) { flash('Password must be at least 8 characters.'); }
        else {
            $stmt = db()->prepare("UPDATE users SET pass_hash=? WHERE id=?");
            $stmt->execute([password_hash($p, PASSWORD_BCRYPT), $id]);
            flash('Password reset.');
        }
        header('Location: ?page=users'); exit;
    }

    elseif ($do === 'user_delete') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $target = db()->query("SELECT username, role FROM users WHERE id=".$id)->fetch();
        if (!$target)                                   flash('User not found.');
        elseif ($target['username'] === current_user()) flash('You cannot delete your own account.');
        elseif ($target['role'] === 'admin' && admin_count() <= 1) flash('Cannot delete the last admin.');
        else {
            db()->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            flash("Deleted user '{$target['username']}'. Their assigned tickets keep the name for history.");
        }
        header('Location: ?page=users'); exit;
    }

    elseif ($do === 'pw_change') {
        require_login();
        $old = $_POST['old'] ?? ''; $new = $_POST['new'] ?? ''; $conf = $_POST['confirm'] ?? '';
        $hash = db()->prepare("SELECT pass_hash FROM users WHERE username=?");
        $hash->execute([current_user()]);
        $h = $hash->fetchColumn();
        if (!$h || !password_verify($old, $h))  flash('Current password is incorrect.');
        elseif (strlen($new) < 8)               flash('New password must be at least 8 characters.');
        elseif ($new !== $conf)                 flash('New passwords do not match.');
        else {
            $stmt = db()->prepare("UPDATE users SET pass_hash=? WHERE username=?");
            $stmt->execute([password_hash($new, PASSWORD_BCRYPT), current_user()]);
            flash('Your password was changed.');
        }
        header('Location: ?page=account'); exit;
    }
}

if ($page === 'logout') { session_destroy(); header('Location: ?page=login'); exit; }

/* ============================================================
   VIEWS
   ============================================================ */
function layout_top(string $title): void {
    $u = current_user();
    $theme = (($_COOKIE['theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
    ?><!DOCTYPE html>
<html lang="en" data-theme="<?=$theme?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=e($title)?> · <?=e(APP_NAME)?></title>
<style>
:root{--bg:#0f1420;--panel:#181f2e;--panel2:#1f2838;--line:#2a3448;--txt:#e6ebf4;--mut:#8b98b0;--acc:#3b82f6;--acc2:#2563eb}
html[data-theme="light"]{--bg:#f3f5fa;--panel:#ffffff;--panel2:#eef1f7;--line:#d7ddea;--txt:#1a2233;--mut:#5c6a82;--acc:#2563eb;--acc2:#1d4ed8}
html{color-scheme:dark}html[data-theme="light"]{color-scheme:light}
/* Status badges: light-mode variants (softer, on-white) */
html[data-theme="light"] .st-received{background:#e0edff;color:#1d4ed8}
html[data-theme="light"] .st-diagnosing{background:#fef3c7;color:#92600a}
html[data-theme="light"] .st-awaitingparts{background:#ffedd5;color:#9a3412}
html[data-theme="light"] .st-inprogress{background:#dcfce7;color:#166534}
html[data-theme="light"] .st-readyforpickup{background:#ede9fe;color:#6d28d9}
html[data-theme="light"] .st-completed{background:#dcfce7;color:#15803d}
html[data-theme="light"] .st-cancelled{background:#fee2e2;color:#b91c1c}
html[data-theme="light"] .flash{background:#dcfce7;border-color:#86efac;color:#166534}
html[data-theme="light"] .err{background:#fee2e2;border-color:#fca5a5;color:#b91c1c}
html[data-theme="light"] .pri-Low{color:#94a3b8}
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--txt)}
a{color:var(--acc);text-decoration:none}a:hover{text-decoration:underline}
header{background:var(--panel);border-bottom:1px solid var(--line);padding:12px 20px;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:5}
header .brand{font-weight:700;font-size:16px}header .brand small{color:var(--mut);font-weight:400}
header nav{margin-left:auto;display:flex;gap:16px;align-items:center;color:var(--mut)}
.wrap{max-width:1080px;margin:0 auto;padding:22px 20px}
.btn{display:inline-block;background:var(--acc);color:#fff;border:0;padding:9px 16px;border-radius:7px;font:inherit;font-weight:600;cursor:pointer}
.btn:hover{background:var(--acc2);text-decoration:none}
.btn.sec{background:var(--panel2);color:var(--txt);border:1px solid var(--line)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:20px;margin-bottom:18px}
input,select,textarea{width:100%;background:var(--panel2);border:1px solid var(--line);color:var(--txt);border-radius:7px;padding:9px 11px;font:inherit}
textarea{min-height:90px;resize:vertical}
label{display:block;font-size:12px;color:var(--mut);margin:0 0 5px;text-transform:uppercase;letter-spacing:.03em}
.grid{display:grid;gap:14px}.g2{grid-template-columns:1fr 1fr}.g3{grid-template-columns:1fr 1fr 1fr}.g4{grid-template-columns:2fr 2fr 2fr 1fr}
@media(max-width:640px){.g2,.g3,.g4{grid-template-columns:1fr}}
.item-row{display:grid;grid-template-columns:2fr 1.3fr 1.3fr 2fr 42px;gap:8px;margin-bottom:8px}
.item-row .btn{padding:9px 0}
@media(max-width:640px){.item-row{grid-template-columns:1fr 1fr}}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut);padding:8px 10px;border-bottom:1px solid var(--line)}
td{padding:11px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
tr:hover td{background:var(--panel2)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap}
.st-received{background:#1e3a5f;color:#93c5fd}.st-diagnosing{background:#3f3a1e;color:#fcd34d}
.st-awaitingparts{background:#4a2e1e;color:#fdba74}.st-inprogress{background:#1e3a2e;color:#86efac}
.st-readyforpickup{background:#2e1e4a;color:#c4b5fd}.st-completed{background:#1e3a2e;color:#4ade80}
.st-cancelled{background:#3a1e1e;color:#fca5a5}
.pri{font-size:12px;font-weight:600}.pri-Rush{color:#f87171}.pri-High{color:#fb923c}.pri-Normal{color:var(--mut)}.pri-Low{color:#64748b}
.filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;align-items:center}
.chip{padding:6px 12px;border-radius:20px;border:1px solid var(--line);background:var(--panel);color:var(--mut);font-size:13px;cursor:pointer}
.chip.on{background:var(--acc);color:#fff;border-color:var(--acc)}
.flash{background:#14351f;border:1px solid #1e5631;color:#86efac;padding:11px 15px;border-radius:8px;margin-bottom:16px}
.err{background:#3a1414;border:1px solid #7f1d1d;color:#fca5a5;padding:11px 15px;border-radius:8px;margin-bottom:16px}
.muted{color:var(--mut)}.right{text-align:right}
.hist{border-left:2px solid var(--line);padding-left:16px;margin-left:4px}
.hist .item{margin-bottom:14px}.hist .meta{font-size:12px;color:var(--mut)}
.center{max-width:400px;margin:8vh auto}.center h1{text-align:center}
.stat{display:flex;gap:22px;flex-wrap:wrap;margin-bottom:18px}
.stat div{background:var(--panel);border:1px solid var(--line);border-radius:9px;padding:12px 18px;min-width:110px}
.stat .n{font-size:24px;font-weight:700}.stat .l{font-size:12px;color:var(--mut)}
.theme-btn{cursor:pointer;background:var(--panel2);color:var(--txt);border:1px solid var(--line);border-radius:7px;padding:6px 12px;font:inherit;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px}
.theme-btn:hover{border-color:var(--acc)}
.theme-fixed{position:fixed;top:14px;right:16px;z-index:10}
</style></head><body>
<?php if ($u): ?>
<header>
  <div class="brand"><?=e(COMPANY)?> <small>/ <?=e(APP_NAME)?></small></div>
  <nav>
    <a href="?page=dashboard">Tickets</a>
    <a href="?page=workload">Workload</a>
    <a href="?page=new">+ New</a>
    <?php if (is_admin()): ?><a href="?page=users">Users</a><?php endif; ?>
    <a href="?page=account" class="muted"><?=e($u)?></a>
    <button type="button" class="theme-btn" onclick="toggleTheme()" title="Toggle color theme"><span id="themeLabel"><?= $theme==='dark'?'Light':'Dark' ?></span> mode</button>
    <a href="?page=logout">Log out</a>
  </nav>
</header>
<?php else: ?>
<button type="button" class="theme-btn theme-fixed" onclick="toggleTheme()" title="Toggle color theme"><span id="themeLabel"><?= $theme==='dark'?'Light':'Dark' ?></span> mode</button>
<?php endif; ?>
<script>
function applyTheme(t){
  document.documentElement.setAttribute('data-theme', t);
  var l=document.getElementById('themeLabel'); if(l) l.textContent = (t==='dark'?'Light':'Dark');
  document.cookie = 'theme='+t+';path=/;max-age=31536000;samesite=lax';
}
function toggleTheme(){
  var cur = document.documentElement.getAttribute('data-theme') || 'dark';
  applyTheme(cur==='dark' ? 'light' : 'dark');
}
</script>
<div class="wrap"><?php
}
function layout_bottom(): void { echo '</div></body></html>'; }

/* ---- SETUP ---- */
if ($page === 'setup') {
    layout_top('First-run setup');
    ?><div class="center card">
      <h1>Create admin account</h1>
      <p class="muted">No users exist yet. Create the first login to secure the tracker.</p>
      <?php if ($error): ?><div class="err"><?=e($error)?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="do" value="setup">
        <div class="grid" style="gap:12px">
          <div><label>Username</label><input name="username" autofocus required></div>
          <div><label>Password (min 8 chars)</label><input type="password" name="password" required></div>
          <div><label>Confirm password</label><input type="password" name="confirm" required></div>
          <button class="btn">Create account</button>
        </div>
      </form>
    </div><?php
    layout_bottom(); exit;
}

/* ---- LOGIN ---- */
if ($page === 'login') {
    layout_top('Log in');
    ?><div class="center card">
      <h1><?=e(COMPANY)?></h1>
      <p class="muted" style="text-align:center;margin-top:-6px"><?=e(APP_NAME)?></p>
      <?php if ($error): ?><div class="err"><?=e($error)?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="do" value="login">
        <div class="grid" style="gap:12px">
          <div><label>Username</label><input name="username" autofocus required></div>
          <div><label>Password</label><input type="password" name="password" required></div>
          <button class="btn">Log in</button>
        </div>
      </form>
    </div><?php
    layout_bottom(); exit;
}

require_login();

/* ---- NEW / EDIT FORM ---- */
if ($page === 'new' || $page === 'edit') {
    $t = ['status' => 'Received', 'priority' => 'Normal'];
    $editing = false;
    if ($page === 'edit') {
        $stmt = db()->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([(int)($_GET['id'] ?? 0)]);
        $t = $stmt->fetch();
        if (!$t) { http_response_code(404); exit('Ticket not found'); }
        $editing = true;
    }
    layout_top($editing ? 'Edit ' . $t['ticket_no'] : 'New ticket');
    ?><h2><?= $editing ? 'Edit ' . e($t['ticket_no']) : 'New repair ticket' ?></h2>
    <form method="post" class="card">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="do" value="<?= $editing ? 'update' : 'create' ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?=(int)$t['id']?>"><?php endif; ?>
      <div class="grid g2">
        <div><label>Customer name *</label><input name="customer_name" required value="<?=e($t['customer_name']??'')?>"></div>
        <div><label>Phone</label><input name="customer_phone" value="<?=e($t['customer_phone']??'')?>"></div>
        <div><label>Email</label><input type="email" name="customer_email" value="<?=e($t['customer_email']??'')?>"></div>
        <div><label>Technician (assigned)</label><select name="technician"><?=tech_options($t['technician']??'')?></select></div>
      </div>
      <?php
        $formItems = $editing ? ticket_items((int)$t['id']) : [];
        if (!$formItems) $formItems = [['device'=>'','model'=>'','serial'=>'','notes'=>'']];
      ?>
      <label style="margin-top:16px">Items checked in — one row per unit</label>
      <div id="items">
      <?php foreach ($formItems as $idx => $it): ?>
        <div class="item-row">
          <input name="item_device[]" placeholder="Device / item *"<?= $idx===0?' required':'' ?> value="<?=e($it['device']??'')?>">
          <input name="item_model[]"  placeholder="Model" value="<?=e($it['model']??'')?>">
          <input name="item_serial[]" placeholder="Serial #" value="<?=e($it['serial']??'')?>">
          <input name="item_notes[]"  placeholder="Notes / condition" value="<?=e($it['notes']??'')?>">
          <button type="button" class="btn sec" onclick="delItem(this)" title="Remove item">&times;</button>
        </div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="btn sec" onclick="addItem()">+ Add item</button>
      <div style="margin-top:16px"><label>Problem / reason for repair (whole ticket)</label><textarea name="problem"><?=e($t['problem']??'')?></textarea></div>
      <div class="grid g3" style="margin-top:14px">
        <div><label>Status</label><select name="status"><?php foreach($STATUSES as $s):?><option<?=($t['status']??'')===$s?' selected':''?>><?=e($s)?></option><?php endforeach;?></select></div>
        <div><label>Priority</label><select name="priority"><?php foreach($PRIORITIES as $p):?><option<?=($t['priority']??'')===$p?' selected':''?>><?=e($p)?></option><?php endforeach;?></select></div>
        <div><label>Estimate ($)</label><input type="number" step="0.01" name="estimate" value="<?=e($t['estimate']??'')?>"></div>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button class="btn"><?= $editing ? 'Save changes' : 'Create ticket' ?></button>
        <a class="btn sec" href="<?= $editing ? '?page=view&id='.(int)$t['id'] : '?page=dashboard' ?>">Cancel</a>
      </div>
    </form>
    <script>
    function addItem(){
      var box=document.getElementById('items');
      var row=document.createElement('div');
      row.className='item-row';
      row.innerHTML='<input name="item_device[]" placeholder="Device / item *">'
        +'<input name="item_model[]" placeholder="Model">'
        +'<input name="item_serial[]" placeholder="Serial #">'
        +'<input name="item_notes[]" placeholder="Notes / condition">'
        +'<button type="button" class="btn sec" onclick="delItem(this)" title="Remove item">&times;</button>';
      box.appendChild(row);
      row.querySelector('input').focus();
    }
    function delItem(btn){
      var box=document.getElementById('items');
      if(box.querySelectorAll('.item-row').length<=1) return;
      btn.closest('.item-row').remove();
    }
    </script><?php
    layout_bottom(); exit;
}

/* ---- VIEW ---- */
if ($page === 'view') {
    $stmt = db()->prepare("SELECT * FROM tickets WHERE id = ?");
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $t = $stmt->fetch();
    if (!$t) { http_response_code(404); exit('Ticket not found'); }
    $h = db()->prepare("SELECT * FROM history WHERE ticket_id = ? ORDER BY id DESC");
    $h->execute([(int)$t['id']]);
    $hist = $h->fetchAll();
    $items = ticket_items((int)$t['id']);

    layout_top($t['ticket_no']);
    if ($f = flash()) echo '<div class="flash">'.e($f).'</div>';
    ?>
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px">
      <span class="mono" style="font-size:22px"><?=e($t['ticket_no'])?></span>
      <span class="badge <?=status_class($t['status'])?>"><?=e($t['status'])?></span>
      <span class="pri pri-<?=e($t['priority'])?>"><?=e($t['priority'])?> priority</span>
      <span style="margin-left:auto;display:flex;gap:8px">
        <a class="btn sec" href="?page=edit&id=<?=(int)$t['id']?>">Edit</a>
        <a class="btn" href="?page=new">+ New</a>
      </span>
    </div>
    <div class="grid g2">
      <div class="card">
        <h3 style="margin-top:0">Customer</h3>
        <p><strong><?=e($t['customer_name'])?></strong><br>
          <?php if($t['customer_phone']):?><span class="muted">Phone:</span> <?=e($t['customer_phone'])?><br><?php endif;?>
          <?php if($t['customer_email']):?><span class="muted">Email:</span> <?=e($t['customer_email'])?><?php endif;?>
        </p>
        <h3>Items</h3>
        <p><span class="muted">Items checked in:</span> <strong><?=count($items)?></strong></p>
      </div>
      <div class="card">
        <h3 style="margin-top:0">Details</h3>
        <p>
          <span class="muted">Technician:</span> <?=e($t['technician']?:'—')?><br>
          <span class="muted">Estimate:</span> <?= $t['estimate']!==null ? '$'.number_format((float)$t['estimate'],2) : '—' ?><br>
          <span class="muted">Received:</span> <?=e($t['created_at'])?><br>
          <span class="muted">Updated:</span> <?=e($t['updated_at'])?>
        </p>
        <h3>Problem</h3>
        <p><?= $t['problem'] ? nl2br(e($t['problem'])) : '<span class="muted">—</span>' ?></p>
      </div>
    </div>
    <div class="card" style="padding:0;overflow:hidden">
      <div style="padding:16px 20px 0"><h3 style="margin:0">Items checked in (<?=count($items)?>)</h3></div>
      <table style="margin-top:10px">
        <thead><tr><th style="width:40px">#</th><th>Device / Item</th><th>Model</th><th>Serial #</th><th>Notes / condition</th></tr></thead>
        <tbody>
        <?php foreach($items as $n => $it): ?>
          <tr>
            <td class="muted"><?=$n+1?></td>
            <td><strong><?=e($it['device'])?></strong></td>
            <td><?=e($it['model']?:'—')?></td>
            <td class="mono"><?=e($it['serial']?:'—')?></td>
            <td><?= $it['notes'] ? nl2br(e($it['notes'])) : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$items): ?><tr><td colspan="5" class="muted" style="padding:18px">No items recorded.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card">
      <h3 style="margin-top:0">History &amp; notes</h3>
      <form method="post" style="display:flex;gap:10px;margin-bottom:18px">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="do" value="note">
        <input type="hidden" name="id" value="<?=(int)$t['id']?>">
        <input name="note" placeholder="Add a note…" style="flex:1">
        <button class="btn">Add</button>
      </form>
      <div class="hist">
        <?php foreach($hist as $it): ?>
          <div class="item">
            <div><?=nl2br(e($it['note']))?></div>
            <div class="meta"><?=e($it['created_at'])?><?= $it['author'] ? ' · '.e($it['author']) : '' ?></div>
          </div>
        <?php endforeach; ?>
        <?php if(!$hist): ?><p class="muted">No history yet.</p><?php endif; ?>
      </div>
    </div>
    <p><a href="?page=dashboard">&larr; Back to all tickets</a></p>
    <?php
    layout_bottom(); exit;
}

/* ---- USERS (admin) ---- */
if ($page === 'users') {
    require_admin();
    $users = db()->query("SELECT id, username, role, created_at FROM users ORDER BY role, username COLLATE NOCASE")->fetchAll();
    // active workload per user for a quick glance
    $load = [];
    foreach (db()->query("SELECT technician, COUNT(*) c FROM tickets
                          WHERE status NOT IN ('Completed','Cancelled') AND technician <> '' GROUP BY technician") as $r) {
        $load[$r['technician']] = (int)$r['c'];
    }
    layout_top('Users');
    if ($f = flash()) echo '<div class="flash">'.e($f).'</div>';
    ?>
    <h2>Users</h2>
    <div class="card">
      <h3 style="margin-top:0">Add user</h3>
      <form method="post" class="grid g3" style="align-items:end">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="do" value="user_add">
        <div><label>Username</label><input name="username" required></div>
        <div><label>Password (min 8)</label><input type="password" name="password" required></div>
        <div style="display:flex;gap:10px;align-items:end">
          <div style="flex:1"><label>Role</label><select name="role"><option value="tech">Technician</option><option value="admin">Admin</option></select></div>
          <button class="btn">Add</button>
        </div>
      </form>
      <p class="muted" style="margin-bottom:0">Admins manage users; technicians work tickets. Any user can be assigned to a repair.</p>
    </div>
    <div class="card" style="padding:0;overflow:hidden">
      <table>
        <thead><tr><th>User</th><th>Role</th><th>Active repairs</th><th>Added</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach($users as $usr): $me = $usr['username'] === current_user(); ?>
          <tr>
            <td><strong><?=e($usr['username'])?></strong><?= $me ? ' <span class="muted">(you)</span>' : '' ?></td>
            <td><span class="badge <?= $usr['role']==='admin'?'st-readyforpickup':'st-received' ?>"><?=e($usr['role'])?></span></td>
            <td><?= $load[$usr['username']] ?? 0 ?></td>
            <td class="muted"><?=e(substr($usr['created_at'],0,10))?></td>
            <td class="right">
              <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                  <input type="hidden" name="do" value="user_role">
                  <input type="hidden" name="id" value="<?=(int)$usr['id']?>">
                  <input type="hidden" name="role" value="<?= $usr['role']==='admin'?'tech':'admin' ?>">
                  <button class="btn sec" title="Toggle role"><?= $usr['role']==='admin'?'Make tech':'Make admin' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return promptReset(this)">
                  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                  <input type="hidden" name="do" value="user_reset">
                  <input type="hidden" name="id" value="<?=(int)$usr['id']?>">
                  <input type="hidden" name="password" value="">
                  <button class="btn sec">Reset pw</button>
                </form>
                <?php if(!$me): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete <?=e($usr['username'])?>?')">
                  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                  <input type="hidden" name="do" value="user_delete">
                  <input type="hidden" name="id" value="<?=(int)$usr['id']?>">
                  <button class="btn sec" style="color:#fca5a5">Delete</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>
    function promptReset(f){var p=prompt('New password (min 8 characters):');if(!p)return false;if(p.length<8){alert('Too short');return false;}f.password.value=p;return true;}
    </script>
    <?php
    layout_bottom(); exit;
}

/* ---- ACCOUNT (change own password) ---- */
if ($page === 'account') {
    require_login();
    layout_top('Account');
    if ($f = flash()) echo '<div class="flash">'.e($f).'</div>';
    ?>
    <h2>Account</h2>
    <div class="card" style="max-width:440px">
      <p><span class="muted">Signed in as</span> <strong><?=e(current_user())?></strong> · <span class="muted"><?=e(current_role())?></span></p>
      <h3>Change password</h3>
      <form method="post" class="grid" style="gap:12px">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="do" value="pw_change">
        <div><label>Current password</label><input type="password" name="old" required></div>
        <div><label>New password (min 8)</label><input type="password" name="new" required></div>
        <div><label>Confirm new password</label><input type="password" name="confirm" required></div>
        <button class="btn">Update password</button>
      </form>
    </div>
    <?php
    layout_bottom(); exit;
}

/* ---- WORKLOAD (in-progress by technician) ---- */
if ($page === 'workload') {
    require_login();
    $active = db()->query("SELECT * FROM tickets WHERE status NOT IN ('Completed','Cancelled')
        ORDER BY CASE priority WHEN 'Rush' THEN 0 WHEN 'High' THEN 1 WHEN 'Normal' THEN 2 ELSE 3 END, updated_at DESC")
        ->fetchAll();
    // group by technician (empty -> Unassigned)
    $groups = [];
    foreach ($active as $t) {
        $key = trim((string)$t['technician']) === '' ? '' : $t['technician'];
        $groups[$key][] = $t;
    }
    // ensure every user shows, even with zero active jobs
    foreach (all_usernames() as $u) { if (!isset($groups[$u])) $groups[$u] = []; }
    uksort($groups, function($a, $b){ if ($a === '') return 1; if ($b === '') return -1; return strcasecmp($a, $b); });

    $done = db()->query("SELECT * FROM tickets WHERE status='Completed' ORDER BY updated_at DESC LIMIT 15")->fetchAll();

    layout_top('Workload');
    ?>
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
      <h2 style="margin:0">Repairs in progress</h2>
      <span class="muted"><?=count($active)?> tickets · <?=array_sum(array_map(fn($t)=>(int)($t['qty']??1),$active))?> items</span>
      <a class="btn" style="margin-left:auto" href="?page=new">+ New ticket</a>
    </div>

    <?php foreach ($groups as $tech => $items): ?>
      <div class="card">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:<?= $items?'12px':'0' ?>">
          <h3 style="margin:0"><?= $tech==='' ? 'Unassigned' : e($tech) ?></h3>
          <span class="badge <?= $items ? 'st-inprogress' : 'st-received' ?>"><?=count($items)?> active</span>
        </div>
        <?php if ($items): ?>
        <table>
          <thead><tr><th>Ticket</th><th>Customer</th><th>Item</th><th>Status</th><th>Priority</th><th>Updated</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($items as $r): ?>
            <tr>
              <td class="mono"><a href="?page=view&id=<?=(int)$r['id']?>"><?=e($r['ticket_no'])?></a></td>
              <td><?=e($r['customer_name'])?></td>
              <td><?=e($r['device'])?><?= (int)($r['qty']??1)>1 ? ' <span class="muted">&times;'.(int)$r['qty'].'</span>' : '' ?><?= $r['model'] ? ' <span class="muted">'.e($r['model']).'</span>' : '' ?></td>
              <td><span class="badge <?=status_class($r['status'])?>"><?=e($r['status'])?></span></td>
              <td class="pri pri-<?=e($r['priority'])?>"><?=e($r['priority'])?></td>
              <td class="muted"><?=e(substr($r['updated_at'],0,10))?></td>
              <td class="right"><a href="?page=view&id=<?=(int)$r['id']?>">View</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <span class="muted" style="display:none"></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="card">
      <h3 style="margin-top:0">Recently completed</h3>
      <?php if ($done): ?>
      <table>
        <thead><tr><th>Ticket</th><th>Customer</th><th>Item</th><th>Technician</th><th>Completed</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($done as $r): ?>
          <tr>
            <td class="mono"><a href="?page=view&id=<?=(int)$r['id']?>"><?=e($r['ticket_no'])?></a></td>
            <td><?=e($r['customer_name'])?></td>
            <td><?=e($r['device'])?></td>
            <td class="muted"><?=e($r['technician']?:'—')?></td>
            <td class="muted"><?=e(substr($r['updated_at'],0,10))?></td>
            <td class="right"><a href="?page=view&id=<?=(int)$r['id']?>">View</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?><p class="muted">No completed repairs yet.</p><?php endif; ?>
    </div>
    <?php
    layout_bottom(); exit;
}

/* ---- DASHBOARD (list) ---- */
$filter = $_GET['status'] ?? '';
$q      = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM tickets WHERE 1=1";
$args = [];
if (in_array($filter, $STATUSES, true)) { $sql .= " AND status = ?"; $args[] = $filter; }
if ($q !== '') {
    $sql .= " AND (ticket_no LIKE ? OR customer_name LIKE ? OR device LIKE ? OR serial LIKE ?
                   OR id IN (SELECT ticket_id FROM ticket_items WHERE device LIKE ? OR model LIKE ? OR serial LIKE ?))";
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like, $like, $like, $like);
}
$sql .= " ORDER BY (status IN ('Completed','Cancelled')) ASC,
          CASE priority WHEN 'Rush' THEN 0 WHEN 'High' THEN 1 WHEN 'Normal' THEN 2 ELSE 3 END,
          updated_at DESC";
$stmt = db()->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// counts per status
$counts = [];
foreach (db()->query("SELECT status, COUNT(*) c FROM tickets GROUP BY status") as $r) $counts[$r['status']] = (int)$r['c'];
$open = 0; foreach ($counts as $s => $c) if (!in_array($s, ['Completed','Cancelled'], true)) $open += $c;

// item quantities: currently in shop (active) and checked in today
$items_in_shop = (int) db()->query("SELECT COALESCE(SUM(qty),0) FROM tickets WHERE status NOT IN ('Completed','Cancelled')")->fetchColumn();
$items_today   = (int) db()->query("SELECT COALESCE(SUM(qty),0) FROM tickets WHERE date(created_at)=date('now','localtime')")->fetchColumn();

layout_top('Tickets');
if ($f = flash()) echo '<div class="flash">'.e($f).'</div>';
?>
<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
  <h2 style="margin:0">Repair tickets</h2>
  <a class="btn" style="margin-left:auto" href="?page=new">+ New ticket</a>
</div>
<div class="stat">
  <div><div class="n"><?=$items_in_shop?></div><div class="l">Items in shop</div></div>
  <div><div class="n"><?=$items_today?></div><div class="l">Checked in today</div></div>
  <div><div class="n"><?=$open?></div><div class="l">Open tickets</div></div>
  <div><div class="n"><?=$counts['Awaiting Parts']??0?></div><div class="l">Awaiting parts</div></div>
  <div><div class="n"><?=$counts['Ready for Pickup']??0?></div><div class="l">Ready for pickup</div></div>
  <div><div class="n"><?=array_sum($counts)?></div><div class="l">Total tickets</div></div>
</div>
<form method="get" class="filters">
  <input type="hidden" name="page" value="dashboard">
  <a class="chip<?=$filter===''?' on':''?>" href="?page=dashboard">All</a>
  <?php foreach($STATUSES as $s): $c=$counts[$s]??0; ?>
    <a class="chip<?=$filter===$s?' on':''?>" href="?page=dashboard&status=<?=urlencode($s)?>"><?=e($s)?> <?=$c?'('.$c.')':''?></a>
  <?php endforeach; ?>
  <input name="q" value="<?=e($q)?>" placeholder="Search ticket, customer, device, serial…" style="flex:1;min-width:180px;margin-left:auto">
  <button class="btn sec">Search</button>
</form>
<div class="card" style="padding:0;overflow:hidden">
<table>
  <thead><tr><th>Ticket</th><th>Customer</th><th>Item</th><th>Status</th><th>Priority</th><th>Tech</th><th>Updated</th><th></th></tr></thead>
  <tbody>
  <?php foreach($rows as $r): ?>
    <tr>
      <td class="mono"><a href="?page=view&id=<?=(int)$r['id']?>"><?=e($r['ticket_no'])?></a></td>
      <td><?=e($r['customer_name'])?></td>
      <td><?=e($r['device'])?><?= (int)($r['qty']??1)>1 ? ' <span class="muted">&times;'.(int)$r['qty'].'</span>' : '' ?><?= $r['model'] ? ' <span class="muted">'.e($r['model']).'</span>' : '' ?></td>
      <td><span class="badge <?=status_class($r['status'])?>"><?=e($r['status'])?></span></td>
      <td class="pri pri-<?=e($r['priority'])?>"><?=e($r['priority'])?></td>
      <td class="muted"><?=e($r['technician']?:'—')?></td>
      <td class="muted"><?=e(substr($r['updated_at'],0,10))?></td>
      <td class="right"><a href="?page=view&id=<?=(int)$r['id']?>">View</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if(!$rows): ?><tr><td colspan="8" class="muted" style="padding:24px;text-align:center">No tickets<?= $q||$filter ? ' match your filter.' : ' yet. Create your first one.' ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php
layout_bottom();

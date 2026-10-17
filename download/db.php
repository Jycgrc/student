<?php
declare(strict_types=1);

/*
 * Configuration, database connection, helpers and the shared page frame
 * (including all CSS). Every page only needs: require_once 'db.php';
 */

// Dates on the dashboard use this timezone. Change it to match your MySQL server.
date_default_timezone_set('Asia/Manila');

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$host = 'sql111.infinityfree.com';
$dbname = 'if0_43135421_php_crud';
$username = 'if0_43135421';
$password = 'rNkSouieVdWJq'; // Default XAMPP password

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    exit('Database connection failed. Check your configuration.');
}

/* ---------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------- */

// Escape output to help prevent XSS.
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Escape text and wrap the part that matches the search in <mark>.
function highlight(string $text, string $q): string
{
    if ($q === '') {
        return e($text);
    }
    $parts = preg_split('/(' . preg_quote($q, '/') . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return e($text);
    }
    $out = '';
    foreach ($parts as $i => $part) {
        $out .= $i % 2 === 1 ? '<mark>' . e($part) . '</mark>' : e($part);
    }

    return $out;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message, array $extra = []): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message] + $extra;
}

function pull_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

function positive_int($value): ?int
{
    $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $int === false ? null : $int;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed.');
    }
}

// Sort options for the student list. The SQL is a whitelist, never user input.
function student_sort_options(): array
{
    return [
        'newest' => ['label' => 'Newest first', 'sql' => 'id DESC'],
        'oldest' => ['label' => 'Oldest first', 'sql' => 'id ASC'],
        'name' => ['label' => 'Name (A to Z)', 'sql' => 'name ASC, id DESC'],
        'course' => ['label' => 'Course (A to Z)', 'sql' => 'course ASC, name ASC'],
    ];
}

/* ---------- CSRF ---------- */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid request token. Please refresh and try again.');
    }
}

/* ---------- Validation (used by create.php and edit.php) ---------- */

function validate_student(array $input): array
{
    $data = [
        'name' => trim((string) ($input['name'] ?? '')),
        'email' => trim((string) ($input['email'] ?? '')),
        'course' => trim((string) ($input['course'] ?? '')),
    ];
    $errors = [];

    if ($data['name'] === '') {
        $errors['name'] = 'Enter the student\'s full name.';
    } elseif (mb_strlen($data['name']) > 100) {
        $errors['name'] = 'Use 100 characters or fewer.';
    }

    if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address, like name@example.com.';
    } elseif (mb_strlen($data['email']) > 150) {
        $errors['email'] = 'Use 150 characters or fewer.';
    }

    if ($data['course'] === '') {
        $errors['course'] = 'Enter the course the student is enrolled in.';
    } elseif (mb_strlen($data['course']) > 100) {
        $errors['course'] = 'Use 100 characters or fewer.';
    }

    return [$data, $errors];
}

/* ---------------------------------------------------------------
 * View helpers
 * ------------------------------------------------------------- */

function icon(string $name, int $size = 18): string
{
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'plus' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'menu' => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'trending' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'mail' => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'sun' => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
        'moon' => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'alert' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'check' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'x-circle' => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
        'chevron-left' => '<polyline points="15 18 9 12 15 6"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    ];

    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';

    return mb_strtoupper($first . $last);
}

// One of five colour tones, picked from a checksum so the same text always gets the
// same colour. The live preview script in render_student_form() uses the same maths.
function tone(string $value): int
{
    return crc32($value) % 5;
}

function avatar(string $name, string $size = ''): string
{
    return '<span class="avatar tone-' . tone($name) . ($size !== '' ? ' avatar-' . e($size) : '') . '">'
        . e(initials($name)) . '</span>';
}

function course_chip(string $course): string
{
    return '<span class="course-chip tone-' . tone($course) . '" title="' . e($course) . '">' . e($course) . '</span>';
}

// "5 min ago", "2 days ago", then a plain date after a week.
function time_ago(?string $value): string
{
    if (!$value) {
        return '';
    }

    try {
        $then = new DateTimeImmutable($value);
    } catch (Exception $ex) {
        return '';
    }

    $seconds = max(0, time() - $then->getTimestamp());
    $plural = fn (int $n, string $unit): string => $n . ' ' . $unit . ($n === 1 ? '' : 's') . ' ago';

    if ($seconds < 60) {
        return 'Just now';
    }
    if ($seconds < 3600) {
        return $plural(intdiv($seconds, 60), 'min');
    }
    if ($seconds < 86400) {
        return $plural(intdiv($seconds, 3600), 'hour');
    }
    if ($seconds < 7 * 86400) {
        $days = intdiv($seconds, 86400);

        return $days === 1 ? 'Yesterday' : $days . ' days ago';
    }

    return $then->format('M j, Y');
}

function format_date(?string $value, string $format = 'M j, Y'): string
{
    if (!$value) {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Exception $ex) {
        return '';
    }
}

function course_list(PDO $pdo): array
{
    return $pdo->query('SELECT DISTINCT course FROM students ORDER BY course')
        ->fetchAll(PDO::FETCH_COLUMN);
}

/* ---------------------------------------------------------------
 * Sidebar data + pictures (all inline SVG, so no image files are needed)
 * ------------------------------------------------------------- */

// Small illustration for empty states (inline SVG, no image file).
function empty_art(bool $search = false): string
{
    $core = $search
        ? '<circle cx="52" cy="48" r="20" fill="none" stroke="currentColor" stroke-width="5"/><path d="M67 63l16 16" stroke="currentColor" stroke-width="5" stroke-linecap="round"/>'
        : '<circle cx="60" cy="38" r="13" fill="currentColor" fill-opacity=".3"/><path d="M34 78c2-15 12-22 26-22s24 7 26 22" fill="currentColor" fill-opacity=".3"/>';

    return '<svg class="empty-art" viewBox="0 0 120 100" aria-hidden="true">'
        . '<rect x="14" y="10" width="92" height="80" rx="16" fill="currentColor" fill-opacity=".07"/>'
        . '<rect x="14" y="10" width="92" height="80" rx="16" fill="none" stroke="currentColor" stroke-opacity=".3" stroke-dasharray="4 5"/>'
        . '<g style="color: var(--accent)">' . $core . '</g></svg>';
}

// Student total and progress towards the next round-number milestone.
function sidebar_stats(): array
{
    global $pdo;

    $total = 0;
    try {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
    } catch (PDOException $e) {
        error_log($e->getMessage());
    }

    $goal = 10;
    foreach ([10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000] as $step) {
        $goal = $step;
        if ($total < $step) {
            break;
        }
    }
    $pct = (int) min(100, floor($total / $goal * 100));

    return [
        'total' => $total,
        'goal' => $goal,
        'pct' => $pct,
        'left' => max(0, $goal - $total),
        'dash' => round($pct * 1.634, 1), // ring circumference is about 163.4
    ];
}

// Illustrated profile picture for the signed-in administrator.
function admin_avatar(int $size = 46): string
{
    return '<svg class="admin-photo" width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" role="img" aria-label="Administrator profile picture">'
        . '<defs><linearGradient id="adminBg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c7caff"/><stop offset="1" stop-color="#6c70f0"/></linearGradient></defs>'
        . '<rect width="64" height="64" fill="url(#adminBg)"/>'
        . '<circle cx="52" cy="12" r="14" fill="#fff" fill-opacity=".18"/>'
        . '<path d="M8 64c1-12 10-19 24-19s23 7 24 19z" fill="#1a1d3d"/>'
        . '<path d="M25 46l7 9 7-9-7-3z" fill="#fff"/>'
        . '<path d="M32 55l-2.5 9h5z" fill="#6c70f0"/>'
        . '<rect x="27.5" y="35" width="9" height="11" rx="4" fill="#e3ae88"/>'
        . '<ellipse cx="32" cy="27" rx="10.5" ry="12" fill="#f4cfae"/>'
        . '<path d="M21 26c-1-10 5-15 11-15 7 0 12 5 11 15-2-5-5-8-11-8s-9 3-11 8z" fill="#2b1d16"/>'
        . '<circle cx="27.7" cy="28" r="1.2" fill="#2b1d16"/><circle cx="36.3" cy="28" r="1.2" fill="#2b1d16"/>'
        . '<path d="M28.5 33c2.2 2 4.8 2 7 0" fill="none" stroke="#b9714f" stroke-width="1.4" stroke-linecap="round"/>'
        . '</svg>';
}

/* ---------------------------------------------------------------
 * Page frame + design (CSS)
 * ------------------------------------------------------------- */

function render_header(string $title, string $active = ''): void
{
    $nav = [
        'Overview' => [
            'dashboard' => ['index.php', 'Dashboard', 'grid'],
        ],
        'Manage' => [
            'students' => ['index.php#students', 'Students', 'users'],
            'create' => ['create.php', 'Add student', 'plus'],
        ],
        'Insights' => [
            'growth' => ['index.php#growth', 'Enrollment growth', 'trending'],
            'courses' => ['index.php#courses', 'Course mix', 'book'],
        ],
    ];
    $side = sidebar_stats();
    $badges = ['students' => number_format($side['total'])];
    $flashIcons = ['success' => 'check', 'danger' => 'x-circle', 'warning' => 'alert', 'info' => 'info'];
    $flashes = pull_flashes();
    ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#4f46e5">
    <title><?= e($title) ?> - Student Management</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%234f46e5'/%3E%3Ctext x='16' y='22' font-size='14' font-family='sans-serif' font-weight='700' text-anchor='middle' fill='%23ffffff'%3ESM%3C/text%3E%3C/svg%3E">

    <!-- Apply the saved theme before first paint to avoid a flash. -->
    <script>
        (function () {
            try {
                var t = localStorage.getItem('sm-theme');
                if (!t) { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
                document.documentElement.setAttribute('data-bs-theme', t);
                var a = localStorage.getItem('sm-accent');
                if (a && a !== 'indigo') { document.documentElement.setAttribute('data-accent', a); }
            } catch (err) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
          rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        /* =====================================================================
           Design system
           One set of tokens, light + dark, three accent colours. Components below
           only read these variables, so nothing needs a per-theme override.
           ===================================================================== */
        :root, [data-bs-theme="light"] {
            --bg: #f3f4f9; --surface: #ffffff; --surface-2: #f8f9fc;
            --ink: #14162b; --muted: #6a6f8c;
            --line: #e8eaf3; --line-strong: #d6d9e8;
            --hover: #f1f2f9; --track: #e8eaf3;
            --acc: 79,70,229; --acc2: 139,92,246; --accent-ink: #ffffff;
            --danger: #e11d48; --ok: #059669; --warn: #d97706; --hl: #fde047;
            --t1: 245,158,11; --t2: 16,185,129; --t3: 236,72,153; --t4: 14,165,233;
            --banner-shade: rgba(20,22,43,0);
            --shadow: 0 1px 2px rgba(20,22,43,.04), 0 10px 28px -14px rgba(20,22,43,.12);
            --shadow-lift: 0 22px 40px -22px rgba(20,22,43,.35);
            --shadow-pop: 0 28px 64px -20px rgba(20,22,43,.4);
        }
        [data-bs-theme="dark"] {
            color-scheme: dark;
            --bg: #0a0b13; --surface: #12141f; --surface-2: #161826;
            --ink: #eceefb; --muted: #9296b4;
            --line: #1f2234; --line-strong: #2d3148;
            --hover: #1a1d2e; --track: #252940;
            --acc: 129,140,248; --acc2: 167,139,250; --accent-ink: #0a0b13;
            --danger: #fb7185; --ok: #34d399; --warn: #fbbf24;
            --t1: 251,191,36; --t2: 52,211,153; --t3: 244,114,182; --t4: 56,189,248;
            --banner-shade: rgba(8,9,20,.5);
            --shadow: 0 1px 2px rgba(0,0,0,.4), 0 10px 28px -14px rgba(0,0,0,.6);
            --shadow-lift: 0 22px 40px -22px rgba(0,0,0,.9);
            --shadow-pop: 0 28px 64px -20px rgba(0,0,0,.9);
        }
        html[data-accent="teal"][data-bs-theme="light"] { --acc: 13,148,136; --acc2: 6,182,212; }
        html[data-accent="teal"][data-bs-theme="dark"]  { --acc: 45,212,191; --acc2: 34,211,238; }
        html[data-accent="rose"][data-bs-theme="light"] { --acc: 225,29,72; --acc2: 251,113,65; }
        html[data-accent="rose"][data-bs-theme="dark"]  { --acc: 251,113,133; --acc2: 251,146,60; }

        :root, [data-bs-theme] {
            --t0: var(--acc);
            --accent: rgb(var(--acc));
            --accent-soft: rgba(var(--acc), .1);
            --ring: rgba(var(--acc), .3);
            --grad: linear-gradient(135deg, rgb(var(--acc)), rgb(var(--acc2)));
            --radius: 12px; --radius-lg: 18px; --sidebar-width: 264px;
            --font: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
            --bs-body-font-family: var(--font);
            --bs-body-color: var(--ink);
            --bs-body-bg: var(--bg);
            --bs-border-color: var(--line);
            --bs-link-color: var(--accent);
            --bs-link-hover-color: var(--ink);
            --bs-modal-bg: var(--surface);
            --bs-modal-color: var(--ink);
            --bs-offcanvas-bg: var(--surface);
            --bs-offcanvas-color: var(--ink);
        }

        /* ---------- Base ---------- */
        body { font-family: var(--font); font-size: .92rem; line-height: 1.55; background: var(--bg); color: var(--ink); -webkit-font-smoothing: antialiased; }
        h1, h2, h3, h4, h5, .h4, .h5 { font-family: var(--font); font-weight: 700; letter-spacing: -.02em; color: var(--ink); }
        html { scroll-behavior: smooth; }
        ::selection { background: var(--hl); color: #14162b; }
        .text-muted { color: var(--muted) !important; }
        .icon { flex: none; }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        * { scrollbar-width: thin; scrollbar-color: var(--line-strong) transparent; }
        kbd { background: var(--hover); color: var(--muted); border: 1px solid var(--line-strong); border-bottom-width: 2px; border-radius: 6px; padding: 0 .4rem; font: 600 .7rem var(--font); }
        mark { background: rgba(253,224,71,.6); color: inherit; padding: 0 .12em; border-radius: 4px; }
        .skip-link { position: absolute; left: .75rem; top: -4rem; z-index: 3000; background: var(--accent); color: var(--accent-ink); padding: .5rem 1rem; border-radius: var(--radius); font-weight: 700; text-decoration: none; }
        .skip-link:focus { top: .75rem; }
        .load-bar { position: fixed; top: 0; left: 0; height: 3px; width: 0; background: var(--grad); z-index: 3000; pointer-events: none; transition: width .5s ease; }
        .load-bar.is-on { width: 80%; }
        #main:focus { outline: none; }

        /* ---------- Sidebar ---------- */
        .app-sidebar { --bs-offcanvas-width: min(284px, 86vw); border: 0; }
        .app-sidebar .offcanvas-body { padding: 0; }
        .sidebar-panel { background: var(--surface); border-right: 1px solid var(--line); min-height: 100%; width: 100%; padding: 1.25rem 1rem; display: flex; flex-direction: column; gap: 1.5rem; }
        .brand { display: flex; align-items: center; gap: .75rem; padding: .1rem .35rem; }
        .brand-mark { width: 40px; height: 40px; flex: none; border-radius: 13px; display: grid; place-items: center; background: var(--grad); color: #fff; font-weight: 800; font-size: .85rem; letter-spacing: -.02em; box-shadow: 0 10px 20px -10px rgba(var(--acc), .9); }
        .brand-text { display: grid; line-height: 1.2; min-width: 0; }
        .brand-name { font-weight: 800; font-size: 1rem; letter-spacing: -.02em; color: var(--ink); }
        .brand-text small { color: var(--muted); font-size: .74rem; font-weight: 500; }
        .side-groups { display: grid; gap: 1.35rem; }
        .side-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); padding: 0 .75rem; margin-bottom: .4rem; opacity: .8; }
        .side-nav { display: grid; gap: .2rem; }
        .side-link { display: flex; align-items: center; gap: .75rem; padding: .62rem .8rem; border-radius: 12px; color: var(--muted); font-weight: 600; text-decoration: none; transition: background-color .15s, color .15s; }
        .side-link:hover { background: var(--hover); color: var(--ink); }
        .side-link.is-active { background: var(--accent-soft); color: var(--accent); }
        .side-badge { margin-left: auto; font-size: .7rem; font-weight: 700; padding: .08rem .55rem; border-radius: 99px; background: var(--hover); color: var(--muted); font-variant-numeric: tabular-nums; }
        .side-link.is-active .side-badge { background: var(--accent); color: var(--accent-ink); }
        .side-footer { margin-top: auto; display: grid; gap: .9rem; }
        .profile { display: flex; align-items: center; gap: .75rem; padding: .7rem; border: 1px solid var(--line); border-radius: 16px; background: var(--surface-2); }
        .profile-photo { position: relative; width: 44px; height: 44px; flex: none; }
        .admin-photo { width: 44px; height: 44px; border-radius: 14px; display: block; }
        .profile-photo::after { content: ""; position: absolute; right: -3px; bottom: -3px; width: 12px; height: 12px; border-radius: 50%; background: #34d399; border: 2px solid var(--surface-2); }
        .profile-text { display: grid; line-height: 1.3; min-width: 0; }
        .profile-text strong { font-size: .88rem; font-weight: 700; }
        .profile-text small { color: var(--muted); font-size: .75rem; }
        .swatches { display: flex; align-items: center; gap: .55rem; padding: 0 .35rem; }
        .swatches span { margin-right: auto; font-size: .78rem; font-weight: 600; color: var(--muted); }
        .swatch { width: 22px; height: 22px; padding: 0; border-radius: 50%; border: 2px solid var(--surface); background: var(--c); box-shadow: 0 0 0 1px var(--line-strong); transition: transform .12s; }
        .swatch:hover { transform: scale(1.12); }
        .swatch[aria-pressed="true"] { box-shadow: 0 0 0 2px var(--ink); }
        .side-note { font-size: .76rem; color: var(--muted); padding: 0 .35rem; display: flex; flex-wrap: wrap; gap: .35rem .9rem; align-items: center; }
        @media (min-width: 992px) {
            .app-sidebar { position: fixed; inset: 0 auto 0 0; width: var(--sidebar-width); height: 100vh; z-index: 1020; display: flex; flex-direction: column; }
            .app-sidebar .offcanvas-body { flex: 1 1 auto !important; min-height: 0; overflow-y: auto !important; }
            .app-main { margin-left: var(--sidebar-width); }
        }
        @media (min-width: 992px) and (max-height: 760px) { .side-note { display: none; } .sidebar-panel { gap: 1.1rem; } }

        /* ---------- App bar + mobile top bar ---------- */
        .appbar, .topbar { display: flex; align-items: center; gap: .75rem; position: sticky; top: 0; z-index: 1015; background: var(--bg); background: color-mix(in srgb, var(--bg) 80%, transparent); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); }
        .appbar { display: none; justify-content: space-between; padding: .85rem clamp(1rem, 3vw, 2.25rem); }
        @media (min-width: 992px) { .appbar { display: flex; } }
        .crumbs { display: flex; align-items: center; gap: .55rem; color: var(--muted); font-weight: 500; }
        .crumbs strong { color: var(--ink); font-weight: 700; }
        .crumbs .sep { opacity: .5; }
        .appbar-actions { display: flex; align-items: center; gap: .6rem; }
        .appbar-date { color: var(--muted); font-size: .82rem; font-weight: 600; padding: .38rem .8rem; border: 1px solid var(--line); border-radius: 99px; background: var(--surface); }
        .topbar { padding: .65rem 1rem; padding-top: calc(.65rem + env(safe-area-inset-top, 0px)); border-bottom: 1px solid var(--line); }
        .topbar .theme-toggle { margin-left: auto; }
        .btn-menu { background: transparent; border: 0; color: var(--ink); padding: .25rem; display: grid; place-items: center; border-radius: 10px; }
        .theme-toggle { width: 38px; height: 38px; display: grid; place-items: center; border: 1px solid var(--line); background: var(--surface); color: var(--muted); border-radius: 12px; transition: background-color .15s, color .15s; }
        .theme-toggle:hover { background: var(--hover); color: var(--ink); }
        .theme-toggle .when-dark { display: none; }
        .theme-toggle .when-light { display: inline-flex; }
        [data-bs-theme="dark"] .theme-toggle .when-dark { display: inline-flex; }
        [data-bs-theme="dark"] .theme-toggle .when-light { display: none; }

        /* ---------- Page frame ---------- */
        .content { position: relative; max-width: 1200px; margin-inline: auto; padding: 1rem clamp(1rem, 3vw, 2.25rem) 3.5rem; min-width: 0; }
        @media (min-width: 1600px) { .content { max-width: 1320px; } }
        .app-main { min-width: 0; }
        .page-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
        .page-head h1 { font-size: clamp(1.5rem, 2.6vw, 1.9rem); font-weight: 800; letter-spacing: -.03em; margin: 0; }
        .page-head p { margin: .25rem 0 0; color: var(--muted); }
        .head-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        .back-link { display: inline-flex; align-items: center; gap: .4rem; color: var(--muted); font-weight: 600; font-size: .88rem; text-decoration: none; margin-bottom: 1rem; }
        .back-link:hover { color: var(--ink); }
        .app-footer { color: var(--muted); font-size: .78rem; margin-top: 2.5rem; padding-top: 1.25rem; border-top: 1px solid var(--line); text-align: center; }
        .reveal { animation: rise .5s cubic-bezier(.2,.7,.2,1) backwards; animation-delay: calc(var(--i, 0) * 60ms); }
        @keyframes rise { from { opacity: 0; transform: translateY(10px); } }

        /* ---------- Panels ---------- */
        .panel { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); box-shadow: var(--shadow); }
        .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.2rem 1.4rem .6rem; }
        .panel-head h2 { font-size: 1.05rem; font-weight: 700; margin: 0; }
        .panel-head .sub { display: block; margin-top: .1rem; color: var(--muted); font-size: .82rem; }
        .panel-body { padding: .6rem 1.4rem 1.4rem; }
        .panel[id] { scroll-margin-top: 5rem; }

        /* ---------- Welcome banner ---------- */
        .dash-head { position: relative; overflow: hidden; padding: 1.9rem 2.1rem; margin-bottom: 1.25rem; border-radius: 24px; color: #fff; background: linear-gradient(var(--banner-shade), var(--banner-shade)), var(--grad); box-shadow: var(--shadow-lift); }
        .dash-head::before { content: ""; position: absolute; width: 340px; height: 340px; right: -80px; top: -150px; border-radius: 50%; background: rgba(255,255,255,.14); }
        .dash-head::after { content: ""; position: absolute; width: 220px; height: 220px; right: 150px; bottom: -150px; border-radius: 50%; background: rgba(255,255,255,.1); }
        .dash-head > * { position: relative; z-index: 1; }
        .dash-head .dash-date { margin: 0 0 .5rem; color: rgba(255,255,255,.85); font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; }
        .dash-head h1 { color: #fff; font-size: clamp(1.8rem, 3.4vw, 2.5rem); font-weight: 800; letter-spacing: -.04em; line-height: 1.1; }
        .dash-head .dash-sub { margin: .6rem 0 0; color: rgba(255,255,255,.92); max-width: 54ch; font-size: .98rem; }
        .dash-head .btn-primary { --bs-btn-color: #14162b; --bs-btn-hover-color: #14162b; --bs-btn-active-color: #14162b; background: #fff; background-image: none; border-color: #fff; box-shadow: 0 10px 22px -10px rgba(0,0,0,.45); }
        .dash-head .btn-primary:hover { filter: none; background: #f1f2f9; }

        /* ---------- KPI cards ---------- */
        .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
        @media (max-width: 1199.98px) { .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 479.98px) { .kpis { grid-template-columns: minmax(0, 1fr); } }
        .kpi { position: relative; overflow: hidden; min-width: 0; padding: 1.15rem 1.3rem; transition: transform .2s, box-shadow .2s; }
        .kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow-lift); }
        .kpi::after { content: ""; position: absolute; right: -40px; top: -40px; width: 130px; height: 130px; border-radius: 50%; background: radial-gradient(circle, rgba(var(--t), .16), transparent 70%); pointer-events: none; }
        .kpi > * { position: relative; z-index: 1; }
        .kpi-top { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
        .kpi-label { color: var(--muted); font-size: .82rem; font-weight: 600; }
        .kpi-icon { width: 40px; height: 40px; border-radius: 13px; display: grid; place-items: center; background: rgba(var(--t), .13); color: rgb(var(--t)); }
        .kpi-body { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem; margin-top: .8rem; min-height: 40px; }
        .kpi-value { font-size: 2.3rem; font-weight: 800; line-height: 1; letter-spacing: -.045em; font-variant-numeric: tabular-nums; }
        .spark { width: 76px; height: 38px; flex: none; overflow: visible; color: rgb(var(--t)); }
        .kpi-foot { margin-top: .8rem; font-size: .79rem; color: var(--muted); }
        .delta { font-weight: 700; color: var(--muted); }
        .delta-up { color: var(--ok); } .delta-down { color: var(--danger); }
        .seg { display: flex; gap: 3px; height: 6px; margin-top: .9rem; border-radius: 99px; overflow: hidden; background: var(--track); }
        .seg span { display: block; min-width: 4px; }
        .seg + .kpi-foot { margin-top: .6rem; }

        /* ---------- Tones (avatars, chips, legends) ---------- */
        :where(.avatar, .course-chip, .kpi, .timeline li) { --t: var(--t0); }
        .tone-0 { --t: var(--t0); } .tone-1 { --t: var(--t1); } .tone-2 { --t: var(--t2); } .tone-3 { --t: var(--t3); } .tone-4 { --t: var(--t4); }
        .avatar { width: 40px; height: 40px; border-radius: 14px; display: grid; place-items: center; flex: none; font-weight: 700; font-size: .78rem; background: linear-gradient(135deg, rgba(var(--t), .24), rgba(var(--t), .1)); color: rgb(var(--t)); }
        .avatar-md { width: 52px; height: 52px; font-size: 1rem; border-radius: 16px; }
        .avatar-lg { width: 64px; height: 64px; font-size: 1.15rem; border-radius: 20px; }
        .course-chip { display: inline-block; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; padding: .22rem .75rem; border-radius: 99px; background: rgba(var(--t), .12); color: rgb(var(--t)); font-size: .78rem; font-weight: 700; }
        .is-empty.course-chip { background: var(--hover); color: var(--muted); }

        /* ---------- Charts ---------- */
        .dash-grid { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); gap: 1.25rem; margin-bottom: 1.25rem; }
        @media (max-width: 991.98px) { .dash-grid { grid-template-columns: minmax(0, 1fr); } }
        .chart-box { position: relative; height: 262px; }
        .chart-box:not(.is-ready), .donut-box:not(.is-ready) { border-radius: 14px; background: linear-gradient(100deg, var(--hover) 30%, var(--track) 50%, var(--hover) 70%); background-size: 200% 100%; animation: shimmer 1.4s linear infinite; }
        .chart-box:not(.is-ready) canvas, .donut-box:not(.is-ready) canvas { opacity: 0; }
        @keyframes shimmer { to { background-position: -200% 0; } }
        .range-toggle { display: inline-flex; padding: 3px; background: var(--hover); border: 1px solid var(--line); border-radius: 12px; }
        .range-toggle button { border: 0; background: transparent; color: var(--muted); font-weight: 700; font-size: .78rem; padding: .28rem .8rem; border-radius: 9px; transition: background-color .12s, color .12s; }
        .range-toggle button:hover { color: var(--ink); }
        .range-toggle button.is-active { background: var(--surface); color: var(--ink); box-shadow: 0 1px 3px rgba(20,22,43,.16); }
        .donut-wrap { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 1.5rem; align-items: center; min-height: 262px; }
        @media (max-width: 575.98px) { .donut-wrap { grid-template-columns: minmax(0, 1fr); justify-items: center; gap: 1.1rem; } .legend { width: 100%; } }
        .donut-box { position: relative; width: 150px; height: 150px; }
        .donut-center { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; pointer-events: none; }
        .donut-center strong { font-size: 1.6rem; font-weight: 800; line-height: 1.05; letter-spacing: -.04em; }
        .donut-center span { color: var(--muted); font-size: .76rem; font-weight: 600; }
        .legend { list-style: none; margin: 0; padding: 0; display: grid; gap: .7rem; }
        .legend li { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: .65rem; }
        .legend .dot { width: 10px; height: 10px; border-radius: 4px; background: var(--c); }
        .legend .name { min-width: 0; overflow-wrap: anywhere; font-weight: 600; line-height: 1.3; }
        .legend .num { color: var(--muted); font-size: .82rem; font-variant-numeric: tabular-nums; }
        .legend .pct { min-width: 2.7rem; text-align: right; font-weight: 700; font-size: .82rem; font-variant-numeric: tabular-nums; }

        .timeline { list-style: none; margin: 0; padding: 0; }
        .timeline li { position: relative; display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; padding: .85rem 0 .85rem 1.7rem; }
        .timeline li + li { border-top: 1px dashed var(--line-strong); }
        .timeline li::before { content: ""; position: absolute; left: 2px; top: 1.2rem; width: 11px; height: 11px; border-radius: 50%; background: rgb(var(--t)); box-shadow: 0 0 0 4px rgba(var(--t), .16); }
        .timeline .what { min-width: 0; }
        .timeline .what > a { font-weight: 700; color: var(--ink); text-decoration: none; }
        .timeline .what > a:hover { text-decoration: underline; text-underline-offset: 3px; }
        .timeline .course-chip { margin-left: .25rem; }
        .timeline .when { color: var(--muted); font-size: .8rem; white-space: nowrap; }
        .week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .75rem; height: 206px; }
        .week-col { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; justify-items: center; gap: .5rem; }
        .week-n { font-size: .78rem; font-weight: 700; color: var(--muted); font-variant-numeric: tabular-nums; }
        .week-bar { width: 100%; max-width: 34px; display: flex; align-items: flex-end; background: var(--hover); border-radius: 12px; overflow: hidden; }
        .week-bar span { display: block; width: 100%; min-height: 4px; border-radius: 12px; transform-origin: bottom; background: linear-gradient(180deg, rgba(var(--acc), .45), rgba(var(--acc), .8)); animation: grow .8s .2s cubic-bezier(.2,.7,.2,1) backwards; }
        @keyframes grow { from { transform: scaleY(0); } }
        .week-d { font-size: .76rem; font-weight: 600; color: var(--muted); }
        .week-col.is-today .week-bar span { background: var(--grad); }
        .week-col.is-today .week-d { color: var(--accent); font-weight: 800; }
        .week-col.is-today .week-n { color: var(--ink); }

        /* ---------- Directory ---------- */
        .directory { margin-bottom: 1.25rem; }
        .toolbar { display: flex; flex-wrap: wrap; gap: .65rem; padding: .4rem 1.4rem 1rem; }
        .search-field { position: relative; flex: 1 1 260px; }
        .search-field .icon { position: absolute; left: .95rem; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
        .search-field .form-control { padding-left: 2.7rem; padding-right: 2.6rem; background: var(--surface-2); }
        .search-field kbd { position: absolute; right: .7rem; top: 50%; transform: translateY(-50%); pointer-events: none; }
        .toolbar .form-select { width: auto; flex: 0 1 190px; background-color: var(--surface-2); }
        .filters { display: flex; gap: .45rem; overflow-x: auto; padding: 0 1.4rem 1rem; border-bottom: 1px solid var(--line); scrollbar-width: none; }
        .filters::-webkit-scrollbar { display: none; }
        .pill { white-space: nowrap; display: inline-flex; align-items: center; gap: .45rem; padding: .32rem .9rem; border: 1px solid var(--line-strong); border-radius: 99px; color: var(--ink); font-size: .82rem; font-weight: 600; text-decoration: none; transition: background-color .12s, border-color .12s; }
        .pill:hover { background: var(--hover); color: var(--ink); }
        .pill .n { color: var(--muted); font-weight: 600; font-variant-numeric: tabular-nums; }
        .pill.is-active { background: var(--grad); border-color: transparent; color: var(--accent-ink); }
        .pill.is-active .n { color: inherit; opacity: .8; }

        .table-clean { margin: 0; --bs-table-bg: transparent; --bs-table-color: var(--ink); --bs-table-hover-bg: var(--hover); --bs-table-hover-color: var(--ink); --bs-table-border-color: var(--line); }
        .table-clean thead th { background: var(--surface-2); color: var(--muted); font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; padding: .7rem 1.4rem; border-bottom: 1px solid var(--line); white-space: nowrap; }
        .table-clean tbody td { padding: .9rem 1.4rem; border-color: var(--line); vertical-align: middle; }
        .table-clean tbody tr:last-child td { border-bottom: 0; }
        .table-clean tbody tr { transition: background-color .12s; }
        .th-sort { display: inline-flex; align-items: center; gap: .4rem; color: inherit; text-decoration: none; }
        .th-sort:hover { color: var(--ink); }
        .th-sort.is-active { color: var(--accent); }
        .th-sort::after { content: ""; border-left: 4px solid transparent; border-right: 4px solid transparent; border-top: 5px solid currentColor; opacity: .35; }
        .th-sort.is-active::after { opacity: 1; }
        .th-sort.is-asc::after { border-top: 0; border-bottom: 5px solid currentColor; }
        .person { display: flex; align-items: center; gap: .85rem; min-width: 0; }
        .person-name { font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
        .person-name a { color: var(--ink); text-decoration: none; }
        .person-name a:hover { color: var(--accent); }
        .person-email { display: flex; align-items: center; gap: .3rem; color: var(--muted); font-size: .82rem; overflow-wrap: anywhere; }
        .cell-muted { color: var(--muted); white-space: nowrap; }
        .table-clean th:last-child, .table-clean td:last-child { width: 1%; white-space: nowrap; }
        .row-actions { display: flex; justify-content: flex-end; gap: .3rem; opacity: 0; transition: opacity .12s; }
        tr:hover .row-actions, tr:focus-within .row-actions, tr.is-open .row-actions { opacity: 1; }
        @media (hover: none) { .row-actions { opacity: 1; } }
        .icon-btn { width: 34px; height: 34px; display: inline-grid; place-items: center; border: 0; border-radius: 11px; background: transparent; color: var(--muted); text-decoration: none; transition: background-color .12s, color .12s; }
        .icon-btn:hover { background: var(--hover); color: var(--ink); }
        .icon-btn.danger:hover { background: var(--danger); color: #fff; }
        .row-toggle .icon { transition: transform .15s; }
        .row-toggle[aria-expanded="true"] { background: var(--accent-soft); color: var(--accent); }
        .row-toggle[aria-expanded="true"] .icon { transform: rotate(180deg); }
        .copy-btn { border: 0; background: transparent; color: var(--muted); padding: .1rem .2rem; border-radius: 6px; line-height: 1; opacity: 0; transition: opacity .12s; }
        tr:hover .copy-btn, .copy-btn:focus-visible, .copy-btn.copied { opacity: 1; }
        @media (hover: none) { .copy-btn { display: none; } }
        .copy-btn:hover { color: var(--ink); }
        .copy-btn .i-ok { display: none; }
        .copy-btn.copied { color: var(--ok); }
        .copy-btn.copied .i-ok { display: inline; }
        .copy-btn.copied .i-copy { display: none; }
        .table-clean tbody tr[hidden] { display: none !important; }
        .table-clean tbody tr.row-detail td { background: var(--surface-2); padding-top: 1.1rem; padding-bottom: 1.3rem; }
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem 1.5rem; margin: 0 0 1rem; }
        .detail-grid dt { font-size: .76rem; font-weight: 600; color: var(--muted); margin-bottom: .1rem; }
        .detail-grid dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
        .detail-grid a { color: var(--accent); }
        .table-clean tbody tr.is-new td { animation: rowflash 2.6s ease-out; }
        @keyframes rowflash { from { background-color: rgba(253,224,71,.5); } to { background-color: transparent; } }
        .directory.is-compact .table-clean tbody td { padding-top: .4rem; padding-bottom: .4rem; }
        .directory.is-compact .avatar { width: 30px; height: 30px; border-radius: 10px; font-size: .66rem; }
        .directory.is-compact .person { gap: .65rem; }
        .directory.is-compact .person-email { font-size: .78rem; }
        .table-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: .85rem 1.4rem; border-top: 1px solid var(--line); color: var(--muted); font-size: .84rem; font-weight: 500; }
        .pager { display: flex; flex-wrap: wrap; gap: .3rem; }
        .pager a, .pager span { min-width: 34px; height: 34px; padding: 0 .55rem; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--line-strong); border-radius: 11px; color: var(--ink); font-weight: 600; text-decoration: none; font-variant-numeric: tabular-nums; }
        .pager a:hover { background: var(--hover); }
        .pager .is-current { background: var(--grad); border-color: transparent; color: var(--accent-ink); }
        .pager .is-disabled { opacity: .4; }
        .pager .gap { border-color: transparent; min-width: 18px; padding: 0; }
        .empty-state { text-align: center; padding: 3.5rem 1.5rem; }
        .empty-art { display: block; width: 140px; margin: 0 auto 1.1rem; color: var(--muted); }
        .empty-state .btn + .btn { margin-left: .4rem; }
        .empty-icon { width: 60px; height: 60px; margin: 0 auto 1rem; border-radius: 18px; display: grid; place-items: center; background: var(--accent-soft); color: var(--accent); }
        .empty-icon.is-danger { background: rgba(225,29,72,.1); color: var(--danger); }

        /* ---------- Buttons & forms ---------- */
        .btn { --bs-btn-focus-shadow-rgb: var(--acc); display: inline-flex; align-items: center; justify-content: center; gap: .45rem; padding: .55rem 1rem; border-radius: var(--radius); font-size: .9rem; font-weight: 700; transition: transform .12s, filter .12s, background-color .12s, box-shadow .12s; }
        .btn:active { transform: translateY(1px); }
        .btn-sm { padding: .35rem .75rem; font-size: .82rem; border-radius: 10px; }
        .btn-primary { --bs-btn-color: var(--accent-ink); --bs-btn-hover-color: var(--accent-ink); --bs-btn-active-color: var(--accent-ink); --bs-btn-disabled-color: var(--accent-ink); --bs-btn-bg: rgb(var(--acc)); --bs-btn-hover-bg: rgb(var(--acc)); --bs-btn-active-bg: rgb(var(--acc)); --bs-btn-disabled-bg: rgb(var(--acc)); --bs-btn-border-color: transparent; --bs-btn-hover-border-color: transparent; --bs-btn-active-border-color: transparent; --bs-btn-disabled-border-color: transparent; background-image: var(--grad); box-shadow: 0 10px 20px -10px rgba(var(--acc), .85); }
        .btn-primary:hover { filter: brightness(1.07); transform: translateY(-1px); }
        .btn-outline-secondary { --bs-btn-color: var(--ink); --bs-btn-border-color: var(--line-strong); --bs-btn-hover-color: var(--ink); --bs-btn-hover-bg: var(--hover); --bs-btn-hover-border-color: var(--line-strong); --bs-btn-active-color: var(--ink); --bs-btn-active-bg: var(--track); --bs-btn-active-border-color: var(--line-strong); }
        .btn-outline-danger { --bs-btn-color: var(--danger); --bs-btn-border-color: var(--line-strong); --bs-btn-hover-color: #fff; --bs-btn-hover-bg: var(--danger); --bs-btn-hover-border-color: var(--danger); --bs-btn-active-bg: var(--danger); --bs-btn-active-border-color: var(--danger); }
        .btn-danger { --bs-btn-color: #fff; --bs-btn-bg: #e11d48; --bs-btn-border-color: #e11d48; --bs-btn-hover-color: #fff; --bs-btn-hover-bg: #be123c; --bs-btn-hover-border-color: #be123c; --bs-btn-active-bg: #be123c; }
        .btn.is-loading { pointer-events: none; }
        .btn.is-loading::after { content: ""; width: 1em; height: 1em; border-radius: 50%; border: 2px solid currentColor; border-right-color: transparent; animation: spin .7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .form-control, .form-select { background-color: var(--surface); color: var(--ink); border: 1px solid var(--line-strong); border-radius: var(--radius); padding: .65rem .95rem; font-size: .92rem; }
        .form-control::placeholder { color: var(--muted); opacity: .7; }
        .form-control:focus, .form-select:focus { background-color: var(--surface); color: var(--ink); border-color: var(--accent); box-shadow: 0 0 0 .25rem var(--ring); }
        .form-label { font-size: .84rem; font-weight: 700; margin-bottom: .4rem; }
        .form-text { color: var(--muted); }
        .form-control.is-valid, .form-control.is-invalid { background-image: none; padding-right: .95rem; }
        .form-control.is-valid { border-color: var(--ok); }
        .form-control.is-invalid { border-color: var(--danger); }
        .form-control.is-valid:focus { box-shadow: 0 0 0 .25rem rgba(5,150,105,.2); }
        .form-control.is-invalid:focus { box-shadow: 0 0 0 .25rem rgba(225,29,72,.2); }

        .form-layout { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 1.25rem; max-width: 940px; align-items: start; }
        @media (max-width: 991.98px) { .form-layout { grid-template-columns: minmax(0, 1fr); max-width: 640px; } }
        .form-panel .panel-body { padding: 1.9rem; }
        .form-head { display: flex; align-items: center; gap: .9rem; margin-bottom: 1.5rem; padding-bottom: 1.3rem; border-bottom: 1px solid var(--line); }
        .form-head-icon { width: 46px; height: 46px; flex: none; border-radius: 14px; display: grid; place-items: center; background: var(--grad); color: #fff; box-shadow: 0 10px 20px -10px rgba(var(--acc), .85); }
        .form-head h2 { font-size: 1.15rem; margin: 0; }
        .form-head p { margin: .1rem 0 0; color: var(--muted); font-size: .85rem; }
        .form-panel .mb-3, .form-panel .mb-4 { margin-bottom: 1.35rem !important; }
        .input-wrap { position: relative; }
        .input-wrap .icon { position: absolute; left: .95rem; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
        .input-wrap .form-control { padding-left: 2.7rem; padding-top: .75rem; padding-bottom: .75rem; }
        .field-error { display: flex; align-items: center; gap: .4rem; color: var(--danger); font-size: .84rem; font-weight: 500; margin-top: .4rem; }
        .chip-row { display: flex; flex-wrap: wrap; gap: .45rem; margin-top: .7rem; }
        .chip-btn { padding: .25rem .8rem; border: 1px solid var(--line-strong); border-radius: 99px; background: transparent; color: var(--ink); font-size: .8rem; font-weight: 600; transition: background-color .12s, border-color .12s, color .12s; }
        .chip-btn:hover { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
        .form-actions { display: flex; gap: .6rem; margin-top: 1.75rem; padding-top: 1.3rem; border-top: 1px solid var(--line); }
        .form-actions .btn-primary { padding-inline: 1.5rem; }
        .form-tips { position: sticky; top: 5rem; padding: 1.4rem; border-radius: var(--radius-lg); background: var(--accent-soft); border: 1px solid rgba(var(--acc), .18); }
        .form-tips h2 { font-size: 1rem; margin: 0 0 .9rem; }
        .form-tips ul { list-style: none; margin: 0; padding: 0; display: grid; gap: .95rem; }
        .form-tips li { display: flex; gap: .7rem; font-size: .85rem; line-height: 1.5; color: var(--muted); }
        .form-tips li .icon { margin-top: .15rem; color: var(--accent); }
        .danger-zone { max-width: 940px; margin-top: 1.25rem; padding: 1.15rem 1.4rem; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; border-color: rgba(225,29,72,.3); }
        .danger-zone h2 { font-size: 1rem; margin: 0 0 .1rem; }
        .danger-zone p { margin: 0; color: var(--muted); font-size: .84rem; }

        /* ---------- Dialogs, toasts, accent, fab ---------- */
        .modal-content { background: var(--surface); border: 1px solid var(--line-strong); border-radius: 20px; box-shadow: var(--shadow-pop); }
        .modal-backdrop { --bs-backdrop-opacity: .55; }
        .modal-icon { width: 42px; height: 42px; flex: none; border-radius: 14px; display: grid; place-items: center; background: rgba(225,29,72,.1); color: var(--danger); }
        .btn-close { filter: none; }
        [data-bs-theme="dark"] .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .alert { display: flex; align-items: flex-start; gap: .7rem; border-radius: 14px; }
        .alert .icon { margin-top: .12rem; }
        .alert .btn-close { margin-left: auto; position: static; padding: .35rem; }
        .toast-stack { position: fixed; right: 1rem; bottom: 1rem; z-index: 2000; display: grid; gap: .55rem; width: min(400px, calc(100vw - 2rem)); }
        .toast-stack .alert { margin: 0; background: var(--surface); color: var(--ink); border: 1px solid var(--line); border-left: 4px solid var(--accent); box-shadow: var(--shadow-pop); }
        .toast-stack .alert-success { border-left-color: var(--ok); } .toast-stack .alert-success .icon { color: var(--ok); }
        .toast-stack .alert-danger { border-left-color: var(--danger); } .toast-stack .alert-danger .icon { color: var(--danger); }
        .toast-stack .alert-warning { border-left-color: var(--warn); } .toast-stack .alert-warning .icon { color: var(--warn); }
        .alert-action { margin-left: auto; }
        .alert-action + .btn-close { margin-left: .25rem; }
        .alert-action .btn { white-space: nowrap; }
        .fab { position: fixed; right: 1.1rem; bottom: calc(1.1rem + env(safe-area-inset-bottom, 0px)); z-index: 1030; width: 58px; height: 58px; border-radius: 20px; display: grid; place-items: center; background: var(--grad); color: var(--accent-ink); box-shadow: 0 16px 30px -10px rgba(var(--acc), .8); text-decoration: none; }
        .fab:hover { filter: brightness(1.08); color: var(--accent-ink); }

        /* ---------- Tablet & phone ---------- */
        @media (max-width: 991.98px) {
            .content { padding-top: 1.25rem; }
            .form-tips { position: static; }
        }
        @media (max-width: 767.98px) {
            .dash-head { padding: 1.5rem 1.4rem; border-radius: 20px; }
            .panel-head { flex-wrap: wrap; padding: 1.1rem 1.15rem .5rem; }
            .panel-body { padding: .5rem 1.15rem 1.25rem; }
            .toolbar { padding: .3rem 1.15rem .9rem; }
            .filters { padding: 0 1.15rem .9rem; }
            .table-clean thead th, .table-clean tbody td, .table-footer { padding-left: 1.15rem; padding-right: 1.15rem; }
            .form-panel .panel-body { padding: 1.4rem; }
            .week { gap: .5rem; height: 180px; }
            .timeline li { flex-wrap: wrap; gap: .1rem 1rem; }
        }
        @media (max-width: 575.98px) {
            .content { padding: 1rem 1rem 3rem; }
            .dash-head .btn { width: 100%; margin-top: .25rem; }
            .head-actions { width: 100%; }
            .toolbar .form-select { flex: 1 1 100%; }
            .density-toggle { display: none; }
            .table-footer { flex-direction: column; gap: .6rem; }
            .pager a, .pager span { min-width: 38px; height: 38px; }
            .chart-box { height: 220px; }
            .form-actions { flex-direction: column-reverse; }
            .form-actions .btn, .danger-zone .btn { width: 100%; }
            .danger-zone { flex-direction: column; align-items: stretch; }
            .modal-footer { flex-direction: column-reverse; }
            .modal-footer .btn { width: 100%; margin: 0; }
            .toast-stack { left: 1rem; right: 1rem; width: auto; top: calc(4.5rem + env(safe-area-inset-top, 0px)); bottom: auto; }
            .table-clean thead { display: none; }
            .table-clean, .table-clean tbody { display: block; width: 100%; }
            .table-clean tbody tr { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .6rem .75rem; align-items: center; padding: .95rem 1.1rem; border-bottom: 1px solid var(--line); }
            .table-clean tbody tr:last-child { border-bottom: 0; }
            .table-clean tbody td { display: block; width: auto !important; padding: 0 !important; border: 0 !important; background: transparent; }
            .table-clean tbody td:nth-child(1) { grid-column: 1; grid-row: 1; }
            .table-clean tbody td:nth-child(2) { grid-column: 1; grid-row: 2; padding-left: 53px !important; }
            .table-clean tbody td:last-child { grid-column: 2; grid-row: 1 / span 2; }
            .person > div { min-width: 0; }
            .person-email { overflow-wrap: normal; }
            .person-email > span:first-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .row-toggle { display: none; }
            .table-clean tbody tr.row-detail { display: block; padding: 0; }
            .table-clean tbody tr.row-detail td { display: block; padding: 1rem 1.15rem 1.2rem !important; background: var(--surface-2); }
        }
        @media (hover: none) {
            .side-note { display: none; }
            .icon-btn { width: 40px; height: 40px; }
            .btn, .pill, .chip-btn { min-height: 40px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { transition: none !important; animation: none !important; scroll-behavior: auto !important; }
        }
        @media (forced-colors: active) {
            .pill.is-active, .side-link.is-active, .pager .is-current { outline: 2px solid CanvasText; }
            .week-bar span, .seg span { forced-color-adjust: none; }
        }
        @media print {
            .app-sidebar, .appbar, .topbar, .toast-stack, .row-actions, .toolbar, .filters, .head-actions, .skip-link, .fab { display: none !important; }
            .app-main { margin: 0 !important; }
            .panel { box-shadow: none; break-inside: avoid; }
        }
    </style>
</head>
<body>

<a class="skip-link" href="#main">Skip to content</a>
<div class="load-bar" id="loadBar" aria-hidden="true"></div>

<div class="topbar d-lg-none">
    <button class="btn-menu" type="button" data-bs-toggle="offcanvas"
            data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu">
        <?= icon('menu', 22) ?>
    </button>
    <span class="brand-name">Student Management</span>
    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode">
        <span class="when-light"><?= icon('moon') ?></span>
        <span class="when-dark"><?= icon('sun') ?></span>
    </button>
</div>

<div class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="sidebar"
     aria-label="Main navigation">
    <div class="offcanvas-body">
        <div class="sidebar-panel">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">SM</span>
                <span class="brand-text">
                    <span class="brand-name">Student Management</span>
                    <small>Admin dashboard</small>
                </span>
                <button type="button" class="btn-close d-lg-none ms-auto"
                        data-bs-dismiss="offcanvas" data-bs-target="#sidebar"
                        aria-label="Close menu"></button>
            </div>

            <nav class="side-groups" aria-label="Primary">
                <?php foreach ($nav as $group => $items): ?>
                    <div>
                        <div class="side-label"><?= e($group) ?></div>
                        <div class="side-nav">
                            <?php foreach ($items as $key => [$href, $label, $iconName]): ?>
                                <a href="<?= e($href) ?>"
                                   class="side-link<?= $active === $key ? ' is-active' : '' ?>"
                                   <?= $active === $key ? 'aria-current="page"' : '' ?>>
                                    <?= icon($iconName) ?>
                                    <span><?= e($label) ?></span>
                                    <?php if (isset($badges[$key])): ?>
                                        <span class="side-badge"><?= e($badges[$key]) ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </nav>

            <div class="side-footer">
                <div class="profile">
                    <span class="profile-photo"><?= admin_avatar() ?></span>
                    <span class="profile-text">
                        <strong>Administrator</strong>
                        <small>Registrar's office</small>
                    </span>
                </div>
                <div class="swatches" role="group" aria-label="Accent colour">
                    <span>Accent</span>
                    <button type="button" class="swatch" data-accent-set="indigo" style="--c: #4f46e5" aria-label="Indigo" aria-pressed="true"></button>
                    <button type="button" class="swatch" data-accent-set="teal" style="--c: #0f9488" aria-label="Teal" aria-pressed="false"></button>
                    <button type="button" class="swatch" data-accent-set="rose" style="--c: #e11d48" aria-label="Rose" aria-pressed="false"></button>
                </div>
                <div class="side-note"><span><kbd>/</kbd> Search</span><span><kbd>N</kbd> New student</span></div>
            </div>
        </div>
    </div>
</div>

<main class="app-main" id="main" tabindex="-1">
    <header class="appbar">
        <div class="crumbs"><span>Student Management</span><span class="sep">/</span><strong><?= e($title) ?></strong></div>
        <div class="appbar-actions">
            <span class="appbar-date"><?= e(date('D, M j')) ?></span>
            <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">
                <span class="when-light"><?= icon('moon', 18) ?></span>
                <span class="when-dark"><?= icon('sun', 18) ?></span>
            </button>
        </div>
    </header>
    <div class="content">
        <div class="toast-stack" aria-live="polite">
        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= icon($flashIcons[$flash['type']] ?? 'info', 20) ?>
                <div><?= e($flash['message']) ?></div>
                <?php if (!empty($flash['undo'])): ?>
                    <form method="POST" action="delete.php" class="alert-action">
                        <?= csrf_field() ?>
                        <input type="hidden" name="undo" value="1">
                        <input type="hidden" name="back" value="<?= e($flash['back'] ?? '') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Undo</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
            </div>
        <?php endforeach; ?>
        </div>
<?php
}

function render_footer(): void
{
    ?>
        <div class="app-footer">&copy; <?= e(date('Y')) ?> Student Management</div>
    </div>
</main>

<?php if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php'): ?>
<a href="create.php" class="fab d-lg-none" aria-label="Add student"><?= icon('plus', 24) ?></a>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        var root = document.documentElement;

        function syncToggles() {
            var dark = root.getAttribute('data-bs-theme') === 'dark';
            document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
                b.setAttribute('aria-pressed', dark ? 'true' : 'false');
            });
        }
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-bs-theme', next);
                try { localStorage.setItem('sm-theme', next); } catch (err) {}
                syncToggles();
                window.dispatchEvent(new CustomEvent('themechange', { detail: next }));
            });
        });
        syncToggles();

        // Headline numbers count up once when the page loads.
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.querySelectorAll('[data-count]').forEach(function (el) {
                var end = parseInt(el.getAttribute('data-count'), 10);
                var finalText = el.textContent;
                if (!isFinite(end) || end < 2) { return; }
                var start = null;
                function step(ts) {
                    if (start === null) { start = ts; }
                    var p = Math.min(1, (ts - start) / 700);
                    el.textContent = p < 1 ? Math.round(end * (1 - Math.pow(1 - p, 3))).toLocaleString() : finalText;
                    if (p < 1) { requestAnimationFrame(step); }
                }
                requestAnimationFrame(step);
            });
        }

        // Accent colour picker (saved in this browser).
        function syncAccent() {
            var a = root.getAttribute('data-accent') || 'indigo';
            document.querySelectorAll('[data-accent-set]').forEach(function (b) {
                b.setAttribute('aria-pressed', b.getAttribute('data-accent-set') === a ? 'true' : 'false');
            });
        }
        document.querySelectorAll('[data-accent-set]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var a = btn.getAttribute('data-accent-set');
                if (a === 'indigo') { root.removeAttribute('data-accent'); } else { root.setAttribute('data-accent', a); }
                try { localStorage.setItem('sm-accent', a); } catch (err) {}
                syncAccent();
                window.dispatchEvent(new CustomEvent('themechange', { detail: root.getAttribute('data-bs-theme') }));
            });
        });
        syncAccent();

        // Charts show a shimmer until drawn; a thin bar shows while the next page loads.
        window.addEventListener('load', function () {
            setTimeout(function () {
                document.querySelectorAll('.chart-box, .donut-box').forEach(function (b) { b.classList.add('is-ready'); });
            }, 120);
        });
        var loadBar = document.getElementById('loadBar');
        window.addEventListener('beforeunload', function () { if (loadBar) { loadBar.classList.add('is-on'); } });
        window.addEventListener('pageshow', function () { if (loadBar) { loadBar.classList.remove('is-on'); } });

        // Success messages dismiss themselves.
        document.querySelectorAll('.alert-success').forEach(function (el) {
            setTimeout(function () { bootstrap.Alert.getOrCreateInstance(el).close(); }, 6000);
        });

        // Guard against double submits; restore the button if the page is restored from cache.
        document.querySelectorAll('form[data-once]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('[type="submit"]');
                if (btn) { setTimeout(function () { btn.disabled = true; btn.classList.add('is-loading'); }, 0); }
            });
        });
        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) { return; }
            document.querySelectorAll('.is-loading').forEach(function (b) { b.disabled = false; b.classList.remove('is-loading'); });
        });
    })();
</script>
</body>
</html>
<?php
}

function render_error_page(int $code, string $heading, string $message): void
{
    http_response_code($code);
    render_header($heading, 'dashboard');
    ?>
    <div class="panel empty-state mx-auto" style="max-width: 560px;">
        <div class="empty-icon is-danger"><?= icon('alert', 28) ?></div>
        <h1 class="h4 mb-2"><?= e($heading) ?></h1>
        <p class="text-muted mb-4"><?= e($message) ?></p>
        <a href="index.php" class="btn btn-primary"><?= icon('arrow-left', 16) ?> Back to dashboard</a>
    </div>
    <?php
    render_footer();
    exit;
}

// The add/edit form with a live preview card, shared by create.php and edit.php.
function render_student_form(array $values, array $errors, string $submitLabel, array $courseOptions): void
{
    $focus = array_key_first(array_intersect_key($errors, ['name' => 1, 'email' => 1, 'course' => 1])) ?? 'name';
    $invalid = fn (string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
    $aria = fn (string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="' . $k . '-error"' : '';
    $error = function (string $k) use ($errors): void {
        if (isset($errors[$k])) {
            echo '<div class="field-error" id="' . $k . '-error">' . icon('alert', 15) . '<span>' . e($errors[$k]) . '</span></div>';
        }
    };
    ?>
<div class="form-layout">
    <section class="panel form-panel">
        <div class="panel-body">
            <div class="form-head">
                <span class="form-head-icon"><?= icon('user', 20) ?></span>
                <div><h2>Student details</h2><p>All three fields are required.</p></div>
            </div>
            <form method="POST" id="student-form" novalidate data-once>
                <?= csrf_field() ?>

                <?php if (!empty($errors['form'])): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= icon('x-circle', 20) ?><div><?= e($errors['form']) ?></div>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="name" class="form-label">Full name</label>
                    <div class="input-wrap">
                        <?= icon('user') ?>
                        <input type="text" id="name" name="name" maxlength="100" required autocomplete="off"
                               placeholder="e.g. Maria Santos"
                               class="form-control<?= $invalid('name') ?>"<?= $aria('name') ?>
                               value="<?= e($values['name']) ?>"<?= $focus === 'name' ? ' autofocus' : '' ?>>
                    </div>
                    <?php $error('name'); ?>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <div class="input-wrap">
                        <?= icon('mail') ?>
                        <input type="email" id="email" name="email" maxlength="150" required autocomplete="off"
                               placeholder="name@example.com"
                               class="form-control<?= $invalid('email') ?>"<?= $aria('email') ?>
                               value="<?= e($values['email']) ?>"<?= $focus === 'email' ? ' autofocus' : '' ?>>
                    </div>
                    <?php $error('email'); ?>
                </div>

                <div class="mb-4">
                    <label for="course" class="form-label">Course</label>
                    <div class="input-wrap">
                        <?= icon('book') ?>
                        <input type="text" id="course" name="course" maxlength="100" required
                               list="course-options" autocomplete="off"
                               placeholder="e.g. Computer Science"
                               class="form-control<?= $invalid('course') ?>"<?= $aria('course') ?>
                               value="<?= e($values['course']) ?>"<?= $focus === 'course' ? ' autofocus' : '' ?>>
                    </div>
                    <datalist id="course-options">
                        <?php foreach ($courseOptions as $option): ?>
                            <option value="<?= e($option) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <?php $error('course'); ?>
                    <?php if (!isset($errors['course'])): ?>
                        <div class="form-text">Pick an existing course or type a new one.</div>
                    <?php endif; ?>
                    <?php if ($courseOptions): ?>
                        <div class="chip-row" aria-label="Existing courses">
                            <?php foreach (array_slice($courseOptions, 0, 6) as $option): ?>
                                <button type="button" class="chip-btn" data-course="<?= e($option) ?>"><?= e($option) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= e($submitLabel) ?></button>
                    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </section>

    <aside class="form-tips" aria-label="Tips">
        <h2>Good to know</h2>
        <ul>
            <li><?= icon('mail', 16) ?><span>Each email address can belong to only one student.</span></li>
            <li><?= icon('book', 16) ?><span>Pick an existing course so the dashboard groups students together.</span></li>
            <li><?= icon('edit', 16) ?><span>You can edit or delete the record at any time.</span></li>
        </ul>
    </aside>
</div>

<script>
    (function () {
        var form = document.getElementById('student-form');
        var courseEl = document.getElementById('course');
        if (!form) { return; }

        var rules = {
            name: function (v) { return v === '' ? "Enter the student's full name." : (v.length > 100 ? 'Use 100 characters or fewer.' : ''); },
            email: function (v) {
                if (v === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { return 'Enter a valid email address, like name@example.com.'; }
                return v.length > 150 ? 'Use 150 characters or fewer.' : '';
            },
            course: function (v) { return v === '' ? 'Enter the course the student is enrolled in.' : (v.length > 100 ? 'Use 100 characters or fewer.' : ''); }
        };
        var touched = {};

        function check(el) {
            var msg = rules[el.id](el.value.trim());
            var wrap = el.closest('.mb-3, .mb-4');
            var box = wrap.querySelector('.field-error');
            if (msg) {
                if (!box) {
                    box = document.createElement('div');
                    box.className = 'field-error';
                    box.id = el.id + '-error';
                    box.setAttribute('aria-live', 'polite');
                    wrap.querySelector('.input-wrap').after(box);
                }
                var span = box.querySelector('span');
                if (span) { span.textContent = msg; } else { box.textContent = msg; }
                el.setAttribute('aria-invalid', 'true');
                el.setAttribute('aria-describedby', el.id + '-error');
            } else {
                if (box) { box.remove(); }
                el.removeAttribute('aria-invalid');
                el.removeAttribute('aria-describedby');
            }
            el.classList.toggle('is-invalid', !!msg);
            el.classList.toggle('is-valid', !msg && !!touched[el.id]);
            return !msg;
        }

        ['name', 'email', 'course'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el.classList.contains('is-invalid')) { touched[id] = true; }
            el.addEventListener('blur', function () { touched[id] = true; check(el); });
            el.addEventListener('input', function () { if (touched[id]) { check(el); } });
        });

        document.querySelectorAll('[data-course]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                courseEl.value = btn.getAttribute('data-course');
                touched.course = true;
                check(courseEl);
                courseEl.focus();
            });
        });

        // Runs before the double-submit guard in the page footer, so a blocked submit leaves the button usable.
        form.addEventListener('submit', function (ev) {
            var firstBad = null;
            ['name', 'email', 'course'].forEach(function (id) {
                var el = document.getElementById(id);
                touched[id] = true;
                if (!check(el) && !firstBad) { firstBad = el; }
            });
            if (firstBad) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
                firstBad.focus();
            }
        });
    })();
</script>
<?php
}

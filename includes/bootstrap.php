<?php
declare(strict_types=1);

/**
 * Bootstrap: config, session, DB, and shared helper functions.
 */

$GLOBALS['DSAU_CFG'] = require __DIR__ . '/../config.php';

function cfg(string $key, $default = null)
{
    return $GLOBALS['DSAU_CFG'][$key] ?? $default;
}

date_default_timezone_set((string) cfg('timezone', 'Africa/Kampala'));

if (session_status() === PHP_SESSION_NONE) {
    session_name('dsau_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* ---------------------------------------------------------------
 * Database
 * ------------------------------------------------------------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c   = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        exit('<h2 style="font-family:sans-serif">Database connection failed</h2>'
            . '<p style="font-family:sans-serif">Check the <code>db</code> settings in <code>config.php</code> '
            . 'and make sure you imported <code>database.sql</code>.</p>');
    }
    return $pdo;
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/* ---------------------------------------------------------------
 * Output / URLs
 * ------------------------------------------------------------- */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) cfg('base_url', ''), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $relativePath = ltrim($path, '/');
    $filePath = __DIR__ . '/../assets/' . $relativePath;
    $assetUrl = url('assets/' . $relativePath);
    return is_file($filePath) ? $assetUrl . '?v=' . filemtime($filePath) : $assetUrl;
}

/** Logo URL: local file in assets/img wins, otherwise the live website logo. */
function logo_url(bool $white = true): string
{
    $file = $white ? 'logow.png' : 'logo.png';
    if (is_file(__DIR__ . '/../assets/img/' . $file)) {
        return asset('img/' . $file);
    }
    return (string) cfg($white ? 'logo_white_url' : 'logo_url');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function money($n): string
{
    return cfg('currency', 'UGX') . ' ' . number_format((float) $n);
}

function old(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function fmt_date(?string $d, string $format = 'd M Y'): string
{
    if (!$d) {
        return '-';
    }
    $t = strtotime($d);
    return $t ? date($format, $t) : '-';
}

/* ---------------------------------------------------------------
 * Flash messages
 * ------------------------------------------------------------- */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $out = '';
    foreach ($_SESSION['flash'] as $f) {
        $out .= '<div class="alert alert-' . e($f['type']) . '" role="alert">' . e($f['msg'])
              . '<button type="button" class="alert-close" aria-label="Dismiss">&times;</button></div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------------------------------------------------------------
 * CSRF
 * ------------------------------------------------------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Your session expired. Please go back, refresh the page and try again.');
    }
}

/* ---------------------------------------------------------------
 * Admin auth
 * ------------------------------------------------------------- */
function admin(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function require_admin(): void
{
    if (!admin()) {
        redirect(url('admin/login.php'));
    }
}

function require_role_admin(): void
{
    require_admin();
    if ((admin()['role'] ?? '') !== 'admin') {
        flash('danger', 'You do not have permission to do that.');
        redirect(url('admin/index.php'));
    }
}

function dashboard_course_id(): int
{
    if (array_key_exists('course', $_GET)) {
        $selected = is_scalar($_GET['course']) ? filter_var($_GET['course'], FILTER_VALIDATE_INT) : false;
        $courseId = $selected !== false && $selected >= 0 ? (int) $selected : 0;
        if ($courseId > 0 && (int) q('SELECT COUNT(*) FROM courses WHERE id = ?', [$courseId])->fetchColumn() === 0) {
            $courseId = 0;
        }
        $_SESSION['admin_course_id'] = $courseId;
    }

    $courseId = (int) ($_SESSION['admin_course_id'] ?? 0);
    if ($courseId > 0 && (int) q('SELECT COUNT(*) FROM courses WHERE id = ?', [$courseId])->fetchColumn() === 0) {
        unset($_SESSION['admin_course_id']);
        return 0;
    }
    return $courseId;
}

/* ---------------------------------------------------------------
 * Domain constants and badges
 * ------------------------------------------------------------- */
const APP_STATUSES = ['Pending', 'Under Review', 'Admitted', 'Waitlisted', 'Rejected'];

function status_badge(string $status): string
{
    $map = [
        'Pending'      => 'warn',
        'Under Review' => 'info',
        'Admitted'     => 'success',
        'Waitlisted'   => 'purple',
        'Rejected'     => 'danger',
    ];
    return '<span class="badge badge-' . ($map[$status] ?? 'muted') . '">' . e($status) . '</span>';
}

/** Payment status from amount due vs amount paid. */
function pay_status(int $due, int $paid): string
{
    if ($paid <= 0) {
        return 'Unpaid';
    }
    return $paid >= $due ? 'Paid' : 'Partial';
}

function pay_badge(string $status): string
{
    $map = ['Unpaid' => 'danger', 'Partial' => 'warn', 'Paid' => 'success'];
    return '<span class="badge badge-' . ($map[$status] ?? 'muted') . '">' . e($status) . '</span>';
}

/* ---------------------------------------------------------------
 * Applications: shared filter builder (used by list + CSV export)
 * Returns [$whereSql, $params]. Query must alias applications as a
 * and the payment total as p.paid (see applications_base_sql()).
 * ------------------------------------------------------------- */
function applications_base_sql(): string
{
    return 'FROM applications a
            JOIN courses c ON c.id = a.course_id
            JOIN intakes i ON i.id = a.intake_id
            LEFT JOIN (SELECT application_id, SUM(amount) AS paid FROM payments GROUP BY application_id) p
                   ON p.application_id = a.id';
}

function build_application_filters(array $src): array
{
    $where  = [];
    $params = [];

    $search = trim((string) ($src['q'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[]  = '(a.full_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ? OR a.reference LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($src['course'])) {
        $where[]  = 'a.course_id = ?';
        $params[] = (int) $src['course'];
    }
    if (!empty($src['intake'])) {
        $where[]  = 'a.intake_id = ?';
        $params[] = (int) $src['intake'];
    }
    if (!empty($src['status']) && in_array($src['status'], APP_STATUSES, true)) {
        $where[]  = 'a.status = ?';
        $params[] = $src['status'];
    }
    if (!empty($src['level']) && in_array($src['level'], ['Beginner', 'Advanced'], true)) {
        $where[]  = 'a.level = ?';
        $params[] = $src['level'];
    }
    if (!empty($src['payment'])) {
        if ($src['payment'] === 'Unpaid') {
            $where[] = 'COALESCE(p.paid,0) = 0';
        } elseif ($src['payment'] === 'Partial') {
            $where[] = 'COALESCE(p.paid,0) > 0 AND COALESCE(p.paid,0) < a.fee_due';
        } elseif ($src['payment'] === 'Paid') {
            $where[] = 'COALESCE(p.paid,0) >= a.fee_due AND a.fee_due > 0';
        }
    }
    if (!empty($src['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $src['from'])) {
        $where[]  = 'DATE(a.created_at) >= ?';
        $params[] = $src['from'];
    }
    if (!empty($src['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $src['to'])) {
        $where[]  = 'DATE(a.created_at) <= ?';
        $params[] = $src['to'];
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

/* ---------------------------------------------------------------
 * Pagination HTML
 * ------------------------------------------------------------- */
function pagination(int $total, int $page, int $perPage, array $query = []): string
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) {
        return '';
    }
    unset($query['page']);
    $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($query): string {
        if ($disabled) {
            return '<span class="page disabled">' . $label . '</span>';
        }
        $qs = http_build_query($query + ['page' => $p]);
        return '<a class="page' . ($active ? ' active' : '') . '" href="?' . e($qs) . '">' . $label . '</a>';
    };
    $out = '<nav class="pagination" aria-label="Pagination">';
    $out .= $link($page - 1, '&laquo; Prev', false, $page <= 1);
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    if ($start > 1) {
        $out .= $link(1, '1') . ($start > 2 ? '<span class="page disabled">&hellip;</span>' : '');
    }
    for ($i = $start; $i <= $end; $i++) {
        $out .= $link($i, (string) $i, $i === $page);
    }
    if ($end < $pages) {
        $out .= ($end < $pages - 1 ? '<span class="page disabled">&hellip;</span>' : '') . $link($pages, (string) $pages);
    }
    $out .= $link($page + 1, 'Next &raquo;', false, $page >= $pages);
    return $out . '</nav>';
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'course';
}

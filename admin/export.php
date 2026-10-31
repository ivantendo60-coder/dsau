<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$courseId = dashboard_course_id();
$filterInput = $_GET;
$filterInput['course'] = $courseId;
[$where, $params] = build_application_filters($filterInput);

$st = q('SELECT a.reference, a.created_at, i.name AS intake, c.title AS course, a.level, a.status,
                a.full_name, a.gender, a.date_of_birth, a.phone, a.email, a.location,
                a.education, a.education_other, a.employment, a.employment_other, a.heard_about, a.heard_about_other,
                a.has_laptop, a.has_internet, a.has_smartphone, a.has_basic_skills,
                a.fee_due, COALESCE(p.paid,0) AS paid '
    . applications_base_sql() . " $where ORDER BY a.created_at DESC, a.id DESC", $params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="dsau-applications-' . date('Ymd-His') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly

/** Neutralise spreadsheet formula injection in text cells. */
$safe = function ($v) {
    $v = (string) ($v ?? '');
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
};

fputcsv($out, ['Reference', 'Applied on', 'Intake', 'Course', 'Level', 'Status', 'Full name', 'Gender', 'Date of birth', 'Phone', 'Email',
               'Location', 'Education', 'Employment', 'Heard about', 'Laptop', 'Internet', 'Smartphone', 'Basic computer skills',
               'Fee due', 'Paid', 'Balance', 'Payment status']);

while ($r = $st->fetch()) {
    $edu   = $r['education'] === 'Other' && $r['education_other'] ? 'Other: ' . $r['education_other'] : $r['education'];
    $emp   = $r['employment'] === 'Other' && $r['employment_other'] ? 'Other: ' . $r['employment_other'] : $r['employment'];
    $heard = $r['heard_about'] . ($r['heard_about_other'] ? ' (' . $r['heard_about_other'] . ')' : '');
    $due   = (int) $r['fee_due'];
    $paid  = (int) $r['paid'];
    fputcsv($out, array_map($safe, [
        $r['reference'], $r['created_at'], $r['intake'], $r['course'], $r['level'], $r['status'],
        $r['full_name'], $r['gender'], $r['date_of_birth'], $r['phone'], $r['email'], $r['location'],
        $edu, $emp, $heard, $r['has_laptop'], $r['has_internet'], $r['has_smartphone'], $r['has_basic_skills'],
        $due, $paid, max($due - $paid, 0), pay_status($due, $paid),
    ]));
}
fclose($out);

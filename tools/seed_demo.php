<?php
/**
 * OPTIONAL: fills the database with FAKE demo applications and payments
 * so you can preview the dashboard charts.
 *
 *   php tools/seed_demo.php          (add demo data)
 *   php tools/seed_demo.php --clear  (remove ONLY the demo data again)
 *
 * Demo rows use e-mail addresses ending in @demo.invalid so they are easy to find and remove.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require __DIR__ . '/../includes/bootstrap.php';

if (in_array('--clear', $argv, true)) {
    $n = q("DELETE FROM applications WHERE email LIKE '%@demo.invalid'")->rowCount();
    echo "Removed $n demo application(s) (their payments were removed too).\n";
    exit(0);
}

$courses = q('SELECT id, fee_beginner, fee_advanced FROM courses WHERE is_active = 1')->fetchAll();
$intake  = q('SELECT id FROM intakes WHERE is_open = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
$admin   = q('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn() ?: null;
if (!$courses || !$intake) {
    exit("Import database.sql first (needs courses and an open intake).\n");
}

$first = ['Aisha', 'Brian', 'Carol', 'David', 'Esther', 'Frank', 'Grace', 'Henry', 'Irene', 'Joel', 'Kevin', 'Lydia', 'Moses', 'Nina', 'Oscar', 'Patricia', 'Ronald', 'Sandra', 'Timothy', 'Winnie'];
$last  = ['Okello', 'Nakato', 'Mugisha', 'Kato', 'Namukasa', 'Ssemwogerere', 'Auma', 'Byaruhanga', 'Tumusiime', 'Akello', 'Opio', 'Nabirye'];
$edu   = ['UCE/O-Level', 'UACE/A-Level', 'Certificate', "Bachelor's Degree", 'Postgraduate Diploma', "Master's Degree"];
$emp   = ['Student', 'Full time Employment', 'Part-time employment', 'Unemployed', 'Business owner'];
$heard = ['Facebook', 'Instagram', 'TikTok', 'LinkedIn', 'Whatsapp', 'DSAU Alumni', 'Organisation/company', 'A friend or other referral'];
$places = ['Kampala', 'Wakiso', 'Entebbe', 'Jinja', 'Mukono', 'Mbarara', 'Gulu'];
$methods = ['Mobile Money', 'Mobile Money', 'Bank Transfer', 'Cash'];
$statuses = ['Pending', 'Pending', 'Under Review', 'Admitted', 'Admitted', 'Admitted', 'Waitlisted', 'Rejected'];

$pdo = db();
$pdo->beginTransaction();
$count = 0;
for ($i = 0; $i < 60; $i++) {
    $c      = $courses[array_rand($courses)];
    $level  = mt_rand(0, 2) ? 'Beginner' : 'Advanced';
    $fee    = (int) ($level === 'Beginner' ? $c['fee_beginner'] : $c['fee_advanced']);
    $status = $statuses[array_rand($statuses)];
    $name   = $first[array_rand($first)] . ' ' . $last[array_rand($last)];
    $created = date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 29) . ' days -' . mt_rand(0, 23) . ' hours'));
    $e = $edu[array_rand($edu)];
    $m = $emp[array_rand($emp)];

    q('INSERT INTO applications (reference, intake_id, course_id, level, full_name, has_laptop, has_internet, has_smartphone, has_basic_skills,
        ack_fees, ack_installments, gender, date_of_birth, phone, email, location, education, employment, heard_about, fee_due, status,
        reviewed_by, reviewed_at, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        'DEMO-' . bin2hex(random_bytes(6)), $intake, $c['id'], $level, $name,
        mt_rand(0, 4) ? 'Yes' : 'No', 'Yes', 'Yes', mt_rand(0, 5) ? 'Yes' : 'No',
        1, mt_rand(0, 1), mt_rand(0, 1) ? 'Female' : 'Male',
        date('Y-m-d', strtotime('-' . mt_rand(18, 40) . ' years -' . mt_rand(0, 300) . ' days')),
        '07' . mt_rand(10000000, 99999999), strtolower(str_replace(' ', '.', $name)) . $i . '@demo.invalid',
        $places[array_rand($places)], $e, $m, $heard[array_rand($heard)] . (mt_rand(0, 3) === 0 ? ', ' . $heard[array_rand($heard)] : ''),
        $fee, $status, $status === 'Pending' ? null : $admin, $status === 'Pending' ? null : $created, $created,
    ]);
    $appId = (int) $pdo->lastInsertId();
    q('UPDATE applications SET reference = ? WHERE id = ?', [sprintf('DEMO-%04d', $appId), $appId]);

    if ($status === 'Admitted') {
        $paid = [0, 0.5, 0.75, 1, 1][array_rand([0, 0.5, 0.75, 1, 1])] * $fee;
        $paid = (int) $paid;
        if ($paid > 0) {
            q('INSERT INTO payments (application_id, amount, method, reference_no, paid_on, notes, recorded_by) VALUES (?,?,?,?,?,?,?)',
              [$appId, $paid, $methods[array_rand($methods)], 'TX' . mt_rand(100000, 999999), date('Y-m-d', strtotime($created) + 86400), 'Demo payment', $admin]);
        }
    }
    $count++;
}
$pdo->commit();
echo "Added $count demo applications. Remove them anytime with:  php tools/seed_demo.php --clear\n";

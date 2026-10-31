<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function notification_application(int $id): ?array
{
    $application = q('SELECT a.id, a.reference, a.full_name, a.email, a.level, a.status, a.fee_due,
                             c.title AS course_title, i.name AS intake_name
                      FROM applications a
                      JOIN courses c ON c.id = a.course_id
                      JOIN intakes i ON i.id = a.intake_id
                      WHERE a.id = ?', [$id])->fetch();
    return $application ?: null;
}

function send_text_email(string $toEmail, string $toName, string $subject, string $message, string $context): bool
{
    $settings = (array) cfg('mail', []);
    $smtpHost = trim((string) ($settings['smtp_host'] ?? ''));
    $fromEmail = trim((string) ($settings['from_email'] ?? ''));
    if ($smtpHost === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('[DSAU mail] ' . $context . ' email not sent: configure mail.smtp_host and mail.from_email in config.php.');
        return false;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('[DSAU mail] ' . $context . ' email not sent: Composer dependencies are missing. Run composer install.');
        return false;
    }
    require_once $autoload;

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('[DSAU mail] ' . $context . ' email not sent: the recipient has an invalid email address.');
        return false;
    }

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $smtpHost;
        $mailer->Port = max(1, (int) ($settings['smtp_port'] ?? 587));
        $mailer->Timeout = 15;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->SMTPAuth = trim((string) ($settings['smtp_username'] ?? '')) !== '';
        $mailer->Username = (string) ($settings['smtp_username'] ?? '');
        $mailer->Password = (string) ($settings['smtp_password'] ?? '');

        $encryption = strtolower((string) ($settings['smtp_encryption'] ?? 'tls'));
        $mailer->SMTPSecure = match ($encryption) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        $mailer->SMTPAutoTLS = $encryption === 'tls';
        $mailer->setFrom($fromEmail, (string) ($settings['from_name'] ?? cfg('app_name')));
        $mailer->addAddress($toEmail, $toName);
        $mailer->Subject = $subject;
        $mailer->Body = $message . "\n\nRegards,\n" . (string) cfg('app_name');
        $mailer->isHTML(false);
        return $mailer->send();
    } catch (Throwable $exception) {
        error_log('[DSAU mail] ' . $context . ' email failed: ' . $exception->getMessage());
        return false;
    }
}

function send_applicant_email(array $application, string $subject, string $message): bool
{
    $name = (string) ($application['full_name'] ?? 'Applicant');
    return send_text_email(
        (string) ($application['email'] ?? ''),
        $name,
        $subject,
        'Hello ' . $name . ",\n\n" . $message,
        'Applicant'
    );
}

function send_admin_password_reset_email(array $admin, string $resetUrl): bool
{
    $name = (string) ($admin['name'] ?? 'Administrator');
    $message = 'Hello ' . $name . ",\n\n"
        . "A password reset was requested for your administrator account. Use the link below within 60 minutes to choose a new password:\n\n"
        . $resetUrl . "\n\n"
        . "If you did not request this, you can ignore this email.";

    return send_text_email(
        (string) ($admin['email'] ?? ''),
        $name,
        'Admin password reset',
        $message,
        'Admin password reset'
    );
}

function notify_application_submitted(int $id): bool
{
    $application = notification_application($id);
    if (!$application) {
        return false;
    }

    $message = "We have received your application. It is now pending review.\n\n"
        . 'Reference: ' . $application['reference'] . "\n"
        . 'Course: ' . $application['course_title'] . "\n"
        . 'Level: ' . $application['level'] . "\n"
        . 'Intake: ' . $application['intake_name'] . "\n"
        . 'Tuition fee: ' . money($application['fee_due']) . "\n\n"
        . 'Our admissions team will email you when your application status changes.';

    return send_applicant_email($application, 'Application received: ' . $application['reference'], $message);
}

function notify_application_status_changed(int $id, string $previousStatus): bool
{
    $application = notification_application($id);
    if (!$application || $application['status'] === $previousStatus) {
        return false;
    }

    if ($application['status'] === 'Admitted') {
        return notify_application_admitted($application);
    }

    $message = 'Your application status has changed from ' . $previousStatus . ' to ' . $application['status'] . ".\n\n"
        . 'Reference: ' . $application['reference'] . "\n"
        . 'Course: ' . $application['course_title'] . "\n"
        . 'Intake: ' . $application['intake_name'] . "\n\n"
        . 'Please contact the admissions team if you have questions about this update.';

    return send_applicant_email(
        $application,
        'Application status update: ' . $application['status'] . ' (' . $application['reference'] . ')',
        $message
    );
}

function notify_application_admitted(array $application): bool
{
    $message = "Congratulations! You have been admitted to the course you applied for.\n\n"
        . 'Course: ' . $application['course_title'] . "\n"
        . 'Level: ' . $application['level'] . "\n"
        . 'Intake: ' . $application['intake_name'] . "\n"
        . 'Application reference: ' . $application['reference'] . "\n\n"
        . 'Please contact our admissions team at ' . cfg('admissions_email')
        . ' if you have questions about your admission.';

    return send_applicant_email(
        $application,
        'Congratulations! You are admitted to ' . $application['course_title'],
        $message
    );
}

function notify_application_payment_status(int $id, string $eventMessage): bool
{
    $application = notification_application($id);
    if (!$application) {
        return false;
    }

    $paid = (int) q('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE application_id = ?', [$id])->fetchColumn();
    $due = (int) $application['fee_due'];
    $paymentStatus = pay_status($due, $paid);
    $balance = max($due - $paid, 0);
    $message = $eventMessage . "\n\n"
        . 'Payment status: ' . $paymentStatus . "\n"
        . 'Course: ' . $application['course_title'] . "\n"
        . 'Reference: ' . $application['reference'] . "\n"
        . 'Tuition fee: ' . money($due) . "\n"
        . 'Total paid: ' . money($paid) . "\n"
        . 'Balance: ' . money($balance);

    return send_applicant_email(
        $application,
        'Payment status update: ' . $paymentStatus . ' (' . $application['reference'] . ')',
        $message
    );
}
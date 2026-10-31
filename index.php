<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/notifications.php';

/* Options copied from the DSAU Google Form */
const EDUCATION_OPTIONS  = ['UCE/O-Level', 'UACE/A-Level', 'Certificate', "Bachelor's Degree", 'Postgraduate Diploma', "Master's Degree", 'Doctorate (PhD)', 'Other'];
const EMPLOYMENT_OPTIONS = ['Student', 'Full time Employment', 'Part-time employment', 'Unemployed', 'Business owner', 'Other'];
const HEARD_OPTIONS      = ['Facebook', 'Instagram', 'TikTok', 'LinkedIn', 'Whatsapp', 'DSAU Alumni', 'Organisation/company', 'A friend or other referral', 'Other'];
const ACCESS_ITEMS       = ['laptop' => 'A laptop', 'internet' => 'Steady Internet', 'smartphone' => 'A smart phone', 'basic_skills' => 'Basic computer skills'];

$courses = q('SELECT * FROM courses WHERE is_active = 1 ORDER BY sort_order, title')->fetchAll();
$intakes = q('SELECT * FROM intakes WHERE is_open = 1 AND (application_deadline IS NULL OR application_deadline >= CURDATE()) ORDER BY id DESC')->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $intakes && $courses) {
    csrf_check();

    // Honeypot: bots fill hidden fields
    if (!empty($_POST['website'])) {
        redirect(url('thanks.php'));
    }

    $in = fn(string $k): string => trim((string) ($_POST[$k] ?? ''));

    $courseId = (int) $in('course_id');
    $intakeId = count($intakes) === 1 ? (int) $intakes[0]['id'] : (int) $in('intake_id');
    $level    = $in('level');
    $name     = preg_replace('/\s+/', ' ', $in('full_name')) ?? '';
    $gender   = $in('gender');
    $dob      = $in('date_of_birth');
    $phone    = preg_replace('/[^\d+]/', '', $in('phone')) ?? '';
    $email    = strtolower($in('email'));
    $location = $in('location');
    $edu      = $in('education');
    $eduOther = $in('education_other');
    $emp      = $in('employment');
    $empOther = $in('employment_other');
    $heardArr = array_values(array_intersect(HEARD_OPTIONS, (array) ($_POST['heard_about'] ?? [])));
    $heardOth = $in('heard_about_other');
    $ackFees  = !empty($_POST['ack_fees']) ? 1 : 0;
    $ackInst  = !empty($_POST['ack_installments']) ? 1 : 0;

    $course = null;
    foreach ($courses as $c) {
        if ((int) $c['id'] === $courseId) {
            $course = $c;
        }
    }
    $intakeOk = false;
    foreach ($intakes as $i) {
        if ((int) $i['id'] === $intakeId) {
            $intakeOk = true;
        }
    }

    if (!$course)                                              $errors['course_id'] = 'Please choose a course.';
    if (!$intakeOk)                                            $errors['intake_id'] = 'Please choose an intake.';
    if (!in_array($level, ['Beginner', 'Advanced'], true))     $errors['level'] = 'Please choose a level.';
    if (mb_strlen($name) < 3 || mb_strlen($name) > 160)        $errors['full_name'] = 'Please enter your full name.';

    $access = [];
    foreach (ACCESS_ITEMS as $key => $label) {
        $v = $in('access_' . $key);
        if (!in_array($v, ['Yes', 'No'], true)) {
            $errors['access'] = 'Please answer every entry-requirement question.';
        }
        $access[$key] = $v;
    }

    if (!$ackFees)                                             $errors['ack'] = 'You need to acknowledge that DSAU programs are paid.';
    if (!in_array($gender, ['Female', 'Male'], true))          $errors['gender'] = 'Please select your gender.';

    $dobTs = strtotime($dob);
    if (!$dobTs || date('Y-m-d', $dobTs) !== $dob || $dobTs > strtotime('-10 years') || $dobTs < strtotime('-100 years')) {
        $errors['date_of_birth'] = 'Please enter a valid date of birth.';
    }
    if (!preg_match('/^\+?\d{9,15}$/', $phone))                $errors['phone'] = 'Please enter a valid phone number (WhatsApp enabled).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) $errors['email'] = 'Please enter a valid email address.';
    if (mb_strlen($location) < 2 || mb_strlen($location) > 190) $errors['location'] = 'Please enter where you live.';
    if (!in_array($edu, EDUCATION_OPTIONS, true))              $errors['education'] = 'Please select your highest level of education.';
    if ($edu === 'Other' && $eduOther === '')                  $errors['education'] = 'Please specify your level of education.';
    if (!in_array($emp, EMPLOYMENT_OPTIONS, true))             $errors['employment'] = 'Please select your employment status.';
    if ($emp === 'Other' && $empOther === '')                  $errors['employment'] = 'Please specify your employment status.';
    if (!$heardArr)                                            $errors['heard_about'] = 'Please tell us how you heard about DSAU.';
    if (in_array('Other', $heardArr, true) && $heardOth === '') $errors['heard_about'] = 'Please specify how you heard about us.';

    // Simple abuse limits
    if (!$errors) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $recent = (int) q('SELECT COUNT(*) FROM applications WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$ip])->fetchColumn();
        if ($recent >= 8) {
            $errors['general'] = 'Too many applications from this connection. Please try again later or contact us on WhatsApp.';
        }
        $dup = (int) q('SELECT COUNT(*) FROM applications WHERE email = ? AND course_id = ? AND intake_id = ?', [$email, $courseId, $intakeId])->fetchColumn();
        if ($dup > 0) {
            $errors['email'] = 'An application for this course and intake already exists for this email address.';
        }
    }

    if (!$errors) {
        $fee = $level === 'Advanced' ? (int) $course['fee_advanced'] : (int) $course['fee_beginner'];
        $pdo = db();
        try {
            $pdo->beginTransaction();
            q('INSERT INTO applications
                (reference, intake_id, course_id, level, full_name, has_laptop, has_internet, has_smartphone, has_basic_skills,
                 ack_fees, ack_installments, gender, date_of_birth, phone, email, location, education, education_other,
                 employment, employment_other, heard_about, heard_about_other, fee_due, status, ip_address)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                bin2hex(random_bytes(10)), $intakeId, $courseId, $level, $name,
                $access['laptop'], $access['internet'], $access['smartphone'], $access['basic_skills'],
                $ackFees, $ackInst, $gender, $dob, $phone, $email, $location,
                $edu, $edu === 'Other' ? $eduOther : null,
                $emp, $emp === 'Other' ? $empOther : null,
                implode(', ', $heardArr), in_array('Other', $heardArr, true) ? $heardOth : null,
                $fee, 'Pending', $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $id  = (int) $pdo->lastInsertId();
            $ref = sprintf('DSAU-%s-%04d', date('Y'), $id);
            q('UPDATE applications SET reference = ? WHERE id = ?', [$ref, $id]);
            $pdo->commit();
            unset($_SESSION['csrf']);
            notify_application_submitted($id);
            redirect(url('thanks.php?ref=' . urlencode($ref)));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors['general'] = 'Sorry, we could not save your application. Please try again.';
        }
    }
}

function field_err(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="error-text">' . e($errors[$key]) . '</div>' : '';
}

$wa = 'https://wa.me/' . preg_replace('/\D/', '', (string) cfg('whatsapp_number'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Apply Now | <?= e(cfg('app_name')) ?></title>
<meta name="description" content="Apply for a digital skills course at Digital Shift Academy Uganda.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="public-body">
<header class="pub-header">
  <div class="inner">
    <a href="<?= e(cfg('website')) ?>"><img src="<?= e(logo_url(false)) ?>" alt="<?= e(cfg('app_name')) ?>"
       onerror="this.outerHTML='<strong style=&quot;font-size:1.2rem&quot;>Digital Shift Academy Uganda</strong>'"></a>
    <a class="link" href="<?= e(cfg('website')) ?>">&larr; Back to website</a>
  </div>
</header>

<div class="pub-wrap">
  <div class="card pub-intro">
    <h1>Course Application Form</h1>
    <p class="muted mb-0">Welcome to the DSAU application. It takes about 3&ndash;5 minutes. Please make sure everything you share is accurate and complete.
      All our courses are delivered <strong>online</strong>. Tuition ranges from <strong>UGX 300,000 to UGX 400,000</strong> depending on the programme level that suits your skills.</p>
    <p class="mt-1 mb-0"><a class="btn btn-sm btn-accent" href="<?= e($wa) ?>" target="_blank" rel="noopener">Chat with us on WhatsApp</a></p>
  </div>

<?php if (!$intakes || !$courses): ?>
  <div class="card pub-section">
    <h2>Applications are currently closed</h2>
    <p>There is no intake open for applications right now. Please contact us on WhatsApp <strong><?= e(cfg('phone_numbers')) ?></strong>
       or email <a href="mailto:<?= e(cfg('admissions_email')) ?>"><?= e(cfg('admissions_email')) ?></a> to hear about the next intake.</p>
  </div>
<?php else: ?>
  <form method="post" action="" novalidate data-once>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

    <?php if (isset($errors['general'])): ?><div class="alert alert-danger mt-2"><?= e($errors['general']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['general'])): ?><div class="alert alert-danger mt-2">Please fix the highlighted fields and submit again.</div><?php endif; ?>

    <!-- 1. Programme -->
    <section class="card pub-section">
      <h2><span class="step">1</span> Choose your programme</h2>
      <div class="form-grid">
        <?php if (count($intakes) > 1): ?>
        <div class="field full <?= isset($errors['intake_id']) ? 'has-error' : '' ?>">
          <label for="intake_id">Intake <span class="req">*</span></label>
          <select id="intake_id" name="intake_id" required>
            <option value="">Select intake</option>
            <?php foreach ($intakes as $i): ?>
              <option value="<?= (int) $i['id'] ?>" <?= (string) old('intake_id') === (string) $i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option>
            <?php endforeach; ?>
          </select><?= field_err($errors, 'intake_id') ?>
        </div>
        <?php else: ?>
        <div class="full"><span class="badge badge-primary"><?= e($intakes[0]['name']) ?> &mdash; applications open</span></div>
        <?php endif; ?>

        <div class="field full <?= isset($errors['course_id']) ? 'has-error' : '' ?>">
          <label for="course_id">Course <span class="req">*</span></label>
          <select id="course_id" name="course_id" required>
            <option value="">Select a course</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int) $c['id'] ?>"
                data-fee-beginner="<?= (int) $c['fee_beginner'] ?>" data-fee-advanced="<?= (int) $c['fee_advanced'] ?>"
                data-duration="<?= (int) $c['duration_months'] ?>"
                data-topics="<?= e(implode('|', array_filter(array_map('trim', preg_split('/\R/', (string) $c['topics']) ?: [])))) ?>"
                <?= (string) old('course_id') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['title']) ?></option>
            <?php endforeach; ?>
          </select><?= field_err($errors, 'course_id') ?>
          <div id="course-topics"></div>
        </div>

        <div class="field full <?= isset($errors['level']) ? 'has-error' : '' ?>">
          <span class="lbl">Programme level <span class="req">*</span></span>
          <div class="choice-list cols">
            <label class="choice"><input type="radio" name="level" value="Beginner" <?= old('level', 'Beginner') === 'Beginner' ? 'checked' : '' ?>> Beginner</label>
            <label class="choice"><input type="radio" name="level" value="Advanced" <?= old('level') === 'Advanced' ? 'checked' : '' ?>> Advanced</label>
          </div>
          <?= field_err($errors, 'level') ?>
        </div>
        <div class="full"><div id="fee-preview" class="fee-box" style="display:none"></div></div>
      </div>
    </section>

    <!-- 2. Entry requirements -->
    <section class="card pub-section">
      <h2><span class="step">2</span> Entry requirements</h2>
      <p class="muted mt-0">Do you have access to the following? (These are entry requirements for the programme.) <span class="req">*</span></p>
      <div class="table-wrap <?= isset($errors['access']) ? 'has-error' : '' ?>">
        <table class="grid-q">
          <thead><tr><th></th><th>Yes</th><th>No</th></tr></thead>
          <tbody>
          <?php foreach (ACCESS_ITEMS as $key => $label): ?>
            <tr>
              <td><?= e($label) ?></td>
              <td><input type="radio" name="access_<?= e($key) ?>" value="Yes" aria-label="<?= e($label) ?>: Yes" <?= old('access_' . $key) === 'Yes' ? 'checked' : '' ?>></td>
              <td><input type="radio" name="access_<?= e($key) ?>" value="No" aria-label="<?= e($label) ?>: No" <?= old('access_' . $key) === 'No' ? 'checked' : '' ?>></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= field_err($errors, 'access') ?>
    </section>

    <!-- 3. Fees -->
    <section class="card pub-section">
      <h2><span class="step">3</span> Important information about fees</h2>
      <p class="muted mt-0">Please review and acknowledge. <span class="req">*</span></p>
      <div class="choice-list <?= isset($errors['ack']) ? 'has-error' : '' ?>">
        <label class="choice"><input type="checkbox" name="ack_fees" value="1" <?= old('ack_fees') ? 'checked' : '' ?>>
          <span>I understand that DSAU offers paid digital training programs and I will have to pay the programme fees (Beginner sessions: 300,000/=; Advanced sessions: 400,000/=).</span></label>
        <label class="choice"><input type="checkbox" name="ack_installments" value="1" <?= old('ack_installments') ? 'checked' : '' ?>>
          <span>I understand that I can pay these fees in installments.</span></label>
      </div>
      <?= field_err($errors, 'ack') ?>
    </section>

    <!-- 4. Personal details -->
    <section class="card pub-section">
      <h2><span class="step">4</span> Your details</h2>
      <div class="form-grid">
        <div class="field full <?= isset($errors['full_name']) ? 'has-error' : '' ?>">
          <label for="full_name">Full name <span class="req">*</span></label>
          <input type="text" id="full_name" name="full_name" maxlength="160" autocomplete="name" value="<?= e(old('full_name')) ?>" required>
          <?= field_err($errors, 'full_name') ?>
        </div>

        <div class="field <?= isset($errors['gender']) ? 'has-error' : '' ?>">
          <span class="lbl">Gender <span class="req">*</span></span>
          <div class="choice-list cols">
            <label class="choice"><input type="radio" name="gender" value="Female" <?= old('gender') === 'Female' ? 'checked' : '' ?>> Female</label>
            <label class="choice"><input type="radio" name="gender" value="Male" <?= old('gender') === 'Male' ? 'checked' : '' ?>> Male</label>
          </div>
          <?= field_err($errors, 'gender') ?>
        </div>

        <div class="field <?= isset($errors['date_of_birth']) ? 'has-error' : '' ?>">
          <label for="date_of_birth">Date of birth <span class="req">*</span></label>
          <input type="date" id="date_of_birth" name="date_of_birth" max="<?= e(date('Y-m-d', strtotime('-10 years'))) ?>" value="<?= e(old('date_of_birth')) ?>" required>
          <?= field_err($errors, 'date_of_birth') ?>
        </div>

        <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
          <label for="phone">Phone number <span class="req">*</span></label>
          <input type="tel" id="phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="e.g. 0740 123456" value="<?= e(old('phone')) ?>" required>
          <div class="hint">Please share the number for calls and WhatsApp.</div>
          <?= field_err($errors, 'phone') ?>
        </div>

        <div class="field <?= isset($errors['email']) ? 'has-error' : '' ?>">
          <label for="email">Email address <span class="req">*</span></label>
          <input type="email" id="email" name="email" autocomplete="email" maxlength="190" value="<?= e(old('email')) ?>" required>
          <?= field_err($errors, 'email') ?>
        </div>

        <div class="field full <?= isset($errors['location']) ? 'has-error' : '' ?>">
          <label for="location">Location (place of residence) <span class="req">*</span></label>
          <input type="text" id="location" name="location" maxlength="190" autocomplete="address-level2" value="<?= e(old('location')) ?>" required>
          <?= field_err($errors, 'location') ?>
        </div>
      </div>
    </section>

    <!-- 5. Background -->
    <section class="card pub-section">
      <h2><span class="step">5</span> Your background</h2>
      <div class="form-grid">
        <div class="field full <?= isset($errors['education']) ? 'has-error' : '' ?>">
          <span class="lbl">Highest level of education <span class="req">*</span></span>
          <div class="choice-list cols">
            <?php foreach (EDUCATION_OPTIONS as $o): ?>
              <label class="choice"><input type="radio" name="education" value="<?= e($o) ?>" data-other-group="edu" <?= old('education') === $o ? 'checked' : '' ?>> <?= e($o) ?></label>
            <?php endforeach; ?>
          </div>
          <div data-other-target="edu" class="mt-1" style="display:none"><input type="text" name="education_other" maxlength="120" placeholder="Please specify" value="<?= e(old('education_other')) ?>"></div>
          <?= field_err($errors, 'education') ?>
        </div>

        <div class="field full <?= isset($errors['employment']) ? 'has-error' : '' ?>">
          <span class="lbl">Employment status <span class="req">*</span></span>
          <div class="choice-list cols">
            <?php foreach (EMPLOYMENT_OPTIONS as $o): ?>
              <label class="choice"><input type="radio" name="employment" value="<?= e($o) ?>" data-other-group="emp" <?= old('employment') === $o ? 'checked' : '' ?>> <?= e($o) ?></label>
            <?php endforeach; ?>
          </div>
          <div data-other-target="emp" class="mt-1" style="display:none"><input type="text" name="employment_other" maxlength="120" placeholder="Please specify" value="<?= e(old('employment_other')) ?>"></div>
          <?= field_err($errors, 'employment') ?>
        </div>

        <div class="field full <?= isset($errors['heard_about']) ? 'has-error' : '' ?>">
          <span class="lbl">How did you hear about our programs at DSAU? <span class="req">*</span></span>
          <div class="choice-list cols">
            <?php $heardOld = (array) ($_POST['heard_about'] ?? []); foreach (HEARD_OPTIONS as $o): ?>
              <label class="choice"><input type="checkbox" name="heard_about[]" value="<?= e($o) ?>" data-other-group="heard" <?= in_array($o, $heardOld, true) ? 'checked' : '' ?>> <?= e($o) ?></label>
            <?php endforeach; ?>
          </div>
          <div data-other-target="heard" class="mt-1" style="display:none"><input type="text" name="heard_about_other" maxlength="120" placeholder="Please specify" value="<?= e(old('heard_about_other')) ?>"></div>
          <?= field_err($errors, 'heard_about') ?>
        </div>
      </div>
    </section>

    <div class="card pub-section flex between center wrap gap">
      <span class="muted small">By submitting you confirm that the information above is accurate.</span>
      <button type="submit" class="btn">Submit application</button>
    </div>
  </form>
<?php endif; ?>
</div>

<div class="pub-footer">
  &copy; <?= date('Y') ?> <?= e(cfg('app_name')) ?> &middot; <?= e(cfg('tagline')) ?><br>
  <a href="mailto:<?= e(cfg('admissions_email')) ?>"><?= e(cfg('admissions_email')) ?></a> &middot; <?= e(cfg('phone_numbers')) ?>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>

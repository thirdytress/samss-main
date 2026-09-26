<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$step1_link = 'register.php';
$step2_link = !empty($_SESSION['sams_registration']['step1']) ? 'register1.php' : '#';
$step3_link = (!empty($_SESSION['sams_registration']['step1']) && !empty($_SESSION['sams_registration']['step2'])) ? 'register2.php' : '#';
$step4_link = (!empty($_SESSION['sams_registration']['step1']) && !empty($_SESSION['sams_registration']['step2']) && !empty($_SESSION['sams_registration']['step3'])) ? 'register3.php' : '#';

$errors = [];
$values = [
    'full_name'      => '',
    'student_id'     => '',
    'email'          => '',
    'contact_number' => '',
    'date_of_birth'  => '',
    'gender'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['full_name']      = trim($_POST['full_name']      ?? '');
    $values['student_id']     = trim($_POST['student_id']     ?? '');
    $values['email']          = trim($_POST['email']          ?? '');
    $values['contact_number'] = trim($_POST['contact_number'] ?? '');
    $values['date_of_birth']  = trim($_POST['date_of_birth']  ?? '');
    $values['gender']         = trim($_POST['gender']         ?? '');

    if ($values['full_name']      === '') $errors['full_name']      = 'Full name is required.';
    if ($values['student_id']     === '') $errors['student_id']     = 'Student ID is required.';
    if ($values['email']          === '') $errors['email']          = 'Email address is required.';
    elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($values['contact_number'] === '') $errors['contact_number'] = 'Contact number is required.';
    if ($values['date_of_birth']  === '') $errors['date_of_birth']  = 'Date of birth is required.';
    if ($values['gender']         === '') $errors['gender']         = 'Gender is required.';

    if (empty($errors)) {
      $pdo = sams_pdo();
      if (!sams_column_exists($pdo, 'students', 'student_id_number')) {
        throw new RuntimeException('Students table must have student_id_number column.');
      }
      if (!sams_column_exists($pdo, 'users', 'phone_number')) {
        throw new RuntimeException('Users table must have phone_number column. Please apply the latest database migration.');
      }

      $normalizedPhone = preg_replace('/\D+/', '', $values['contact_number']);

      $duplicateStatement = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM users WHERE email = :email) AS email_count,
            (SELECT COUNT(*) FROM users WHERE phone_number = :phone_number) AS phone_count,
            (SELECT COUNT(*) FROM students WHERE student_id_number = :student_id) AS student_count'
      );
      $duplicateStatement->execute([
        'email' => $values['email'],
        'phone_number' => $normalizedPhone,
        'student_id' => $values['student_id'],
      ]);
      $duplicateCounts = $duplicateStatement->fetch(PDO::FETCH_ASSOC) ?: [];

      if ((int) ($duplicateCounts['email_count'] ?? 0) > 0) {
        $errors['email'] = 'This email is already registered. Please use a different email or log in.';
      }

      if ((int) ($duplicateCounts['phone_count'] ?? 0) > 0) {
        $errors['contact_number'] = 'This phone number is already registered. Please use a different phone number.';
      }

      if ((int) ($duplicateCounts['student_count'] ?? 0) > 0) {
        $errors['student_id'] = 'This student ID is already registered. Please use a different student ID or log in.';
      }
    }

    if (empty($errors)) {
      $_SESSION['sams_registration'] = array_merge($_SESSION['sams_registration'] ?? [], [
        'step1' => $values,
      ]);

      header('Location: register1.php');
      exit;
    }
}

function val(string $key, array $values): string {
    return htmlspecialchars($values[$key] ?? '');
}
function err(string $key, array $errors): string {
    return isset($errors[$key])
        ? '<p class="form__error" role="alert">' . htmlspecialchars($errors[$key]) . '</p>'
        : '';
}
function fieldClass(string $key, array $errors): string {
    return isset($errors[$key]) ? 'form__input form__input--error' : 'form__input';
}

function sams_register_first_existing_column(PDO $pdo, string $table, array $columns): ?string
{
  foreach ($columns as $column) {
    $statement = $pdo->prepare(
      'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
      'table_name' => $table,
      'column_name' => $column,
    ]);

    if ((int) $statement->fetchColumn() > 0) {
      return $column;
    }
  }

  return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="assets/css/sams-dark-mode.css?v=20260926" />
  <title>Apply – Student Assistant | SAMS NU Lipa</title>
  <meta name="description" content="Apply as a Student Assistant at National University Lipa through SAMS." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
  <style>
    /* =============================================
       CSS VARIABLES / DESIGN TOKENS
    ============================================= */
    :root {
      --color-primary:       #003087;
      --color-primary-end:   #004aab;
      --color-gold:          #ffb81c;
      --color-dark:          #101828;
      --color-body:          #364153;
      --color-muted:         #4a5565;
      --color-muted-light:   #99a1af;
      --color-white:         #ffffff;
      --color-border:        #e5e7eb;
      --color-input-border:  #d1d5dc;
      --color-input-ph:      rgba(10,10,10,.50);
      --color-bg-step-off:   #f3f4f6;
      --color-page-bg-start: #eff6ff;
      --color-page-bg-mid:   #ffffff;
      --color-page-bg-end:   #fffbeb;
      --color-error:         #b91c1c;
      --color-error-bg:      #fef2f2;
      --color-error-border:  #fecaca;

      --grad-primary:       linear-gradient(90deg,  #003087 0%, #004aab 100%);
      --grad-primary-135:   linear-gradient(135deg, #003087 0%, #004aab 100%);
      --grad-primary-159:   linear-gradient(159deg, #003087 0%, #004aab 100%);
      --grad-progress:      linear-gradient(90deg,  #003087 0%, #ffb81c 100%);
      --grad-page:          linear-gradient(145deg, var(--color-page-bg-start) 0%, var(--color-page-bg-mid) 50%, var(--color-page-bg-end) 100%);

      --shadow-card: 0 10px 15px 0 rgba(0,0,0,.10), 0 4px 6px 0 rgba(0,0,0,.10);

      --radius-sm:  10px;
      --radius-md:  14px;
      --radius-lg:  16px;

      --font-xs:   12px;
      --font-sm:   14px;
      --font-base: 16px;
      --font-lg:   18px;
      --font-xl:   24px;
      --font-2xl:  36px;

      --space-1:  4px;
      --space-2:  8px;
      --space-3:  12px;
      --space-4:  16px;
      --space-5:  20px;
      --space-6:  24px;
      --space-8:  32px;
      --space-10: 40px;
      --space-12: 48px;
    }

    /* =============================================
       RESET & BASE
    ============================================= */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { min-height: 100%; }
    body {
      font-family: 'Inter', sans-serif;
      font-size: var(--font-base);
      color: var(--color-dark);
      background: var(--grad-page);
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    img { display: block; }
    button, input, select { font-family: inherit; }

    /* =============================================
       PAGE LAYOUT
    ============================================= */
    .page {
      max-width: 1024px;
      margin-inline: auto;
      padding: var(--space-8) var(--space-8) var(--space-12);
    }

    /* =============================================
       BACK LINK
    ============================================= */
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-primary);
      margin-bottom: var(--space-8);
      transition: opacity .2s;
    }
    .back-link:hover { opacity: .75; }
    .back-link__icon { width: 20px; height: 20px; flex-shrink: 0; }

    /* =============================================
       PAGE HEADER
    ============================================= */
    .page-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0;
      margin-bottom: var(--space-8);
    }
    .page-header__icon-wrap {
      width: 64px;
      height: 64px;
      border-radius: var(--radius-lg);
      background: var(--grad-primary-135);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: var(--space-5);
    }
    .page-header__icon-wrap img, .page-header__icon-wrap svg { width: 32px; height: 32px; }
    .page-header__title {
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-dark);
      text-align: center;
      line-height: 1.1;
      margin-bottom: var(--space-2);
    }
    .page-header__subtitle {
      font-size: var(--font-lg);
      font-weight: 500;
      color: var(--color-muted);
      text-align: center;
    }

    /* =============================================
       PROGRESS CARD
    ============================================= */
    .progress-card {
      background: var(--color-white);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: var(--space-8);
      margin-bottom: var(--space-8);
    }
    .progress-card__meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: var(--space-5);
    }
    .progress-card__step-label {
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-muted);
    }
    .progress-card__pct-label {
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-primary);
    }
    .progress-card__bar-track {
      height: 12px;
      background: var(--color-border);
      border-radius: 9999px;
      overflow: hidden;
      margin-bottom: var(--space-6);
    }
    .progress-card__bar-fill {
      height: 100%;
      width: 25%;
      background: var(--grad-progress);
      border-radius: 9999px;
    }

    /* Step tabs */
    .progress-card__steps {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: var(--space-4);
    }
    .step-tab {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: var(--space-2);
      padding: var(--space-4) var(--space-4);
      border-radius: var(--radius-md);
      background: var(--color-bg-step-off);
      cursor: pointer;
      border: none;
      transition: background .2s;
      text-decoration: none;
    }
    .step-tab--active {
      background: var(--grad-primary-159);
    }
    .step-tab__icon { width: 24px; height: 24px; flex-shrink: 0; }
    .step-tab__label {
      font-size: var(--font-xs);
      font-weight: 700;
      color: var(--color-muted-light);
      text-align: center;
      white-space: nowrap;
    }
    .step-tab--active .step-tab__label { color: var(--color-white); }

    /* SVG icons for step tabs */
    .step-tab__svg { width: 24px; height: 24px; flex-shrink: 0; }
    .step-tab--active .step-tab__svg { color: var(--color-white); }
    .step-tab:not(.step-tab--active) .step-tab__svg { color: var(--color-muted-light); }

    /* =============================================
       FORM CARD
    ============================================= */
    .form-card {
      background: var(--color-white);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: var(--space-8);
      margin-bottom: var(--space-8);
    }
    .form-card__heading {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      margin-bottom: var(--space-6);
    }
    .form-card__heading-icon { width: 32px; height: 32px; flex-shrink: 0; }
    .form-card__heading-text {
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-dark);
    }

    /* Two-column grid */
    .form__grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-6) var(--space-6);
    }

    /* Field group */
    .form__group {
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
    }
    .form__label {
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-body);
      line-height: 1.43;
    }
    .form__input {
      width: 100%;
      height: 52px;
      border: 2px solid var(--color-input-border);
      border-radius: var(--radius-md);
      padding: 12px 16px;
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-dark);
      background: var(--color-white);
      outline: none;
      transition: border-color .2s, box-shadow .2s;
      line-height: normal;
      appearance: none;
      -webkit-appearance: none;
    }
    .form__input::placeholder { color: var(--color-input-ph); }
    .form__input:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(0,48,135,.12);
    }
    .form__input--error {
      border-color: var(--color-error);
    }
    .form__input--error:focus {
      border-color: var(--color-error);
      box-shadow: 0 0 0 3px rgba(185,28,28,.12);
    }
    .form__error {
      font-size: var(--font-xs);
      font-weight: 500;
      color: var(--color-error);
      line-height: 1.4;
    }

    /* Select specific */
    .form__select-wrap {
      position: relative;
    }
    .form__select-wrap::after {
      content: '';
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      width: 0;
      height: 0;
      border-left: 5px solid transparent;
      border-right: 5px solid transparent;
      border-top: 6px solid var(--color-muted);
      pointer-events: none;
    }
    .form__select {
      width: 100%;
      height: 52px;
      border: 2px solid var(--color-input-border);
      border-radius: var(--radius-md);
      padding: 12px 36px 12px 16px;
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-dark);
      background: var(--color-white);
      outline: none;
      transition: border-color .2s, box-shadow .2s;
      appearance: none;
      -webkit-appearance: none;
      cursor: pointer;
    }
    .form__select:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(0,48,135,.12);
    }
    .form__select--error { border-color: var(--color-error); }
    .form__select option[value=""] { color: var(--color-input-ph); }

    /* =============================================
       NAVIGATION BUTTONS
    ============================================= */
    .form-nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .form-nav__back {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 56px;
      padding: 0 var(--space-8);
      border-radius: var(--radius-md);
      background: var(--color-border);
      border: none;
      cursor: pointer;
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-muted-light);
      text-decoration: none;
      transition: background .2s;
    }
    .form-nav__back:hover { background: #d1d5db; }
    .form-nav__back img, .form-nav__back svg { width: 20px; height: 20px; display: block; }
    .form-nav__next {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 56px;
      padding: 0 var(--space-8);
      border-radius: var(--radius-md);
      background: var(--grad-primary);
      border: none;
      cursor: pointer;
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
      text-decoration: none;
      transition: opacity .2s;
    }
    .form-nav__next:hover { opacity: .88; }
    .form-nav__next img, .form-nav__next svg { width: 20px; height: 20px; display: block; }

    /* =============================================
       RESPONSIVE – TABLET (≤1024px)
    ============================================= */
    @media (max-width: 1024px) {
      .page { padding-inline: var(--space-6); }
      .progress-card__steps { grid-template-columns: repeat(4, 1fr); gap: var(--space-2); }
      .step-tab { padding: var(--space-3) var(--space-2); }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      .page { padding: var(--space-4) var(--space-4) var(--space-10); }
      .page-header__title { font-size: 26px; }
      .page-header__subtitle { font-size: var(--font-base); }

      .progress-card__steps {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--space-2);
      }

      .form__grid {
        grid-template-columns: 1fr;
        gap: var(--space-4);
      }

      .form-nav__back,
      .form-nav__next { padding: 0 var(--space-6); }
    }
  </style>
</head>
<body>

  <main class="page" role="main">

    <!-- Back to Home -->
    <a class="back-link" href="index.php">
      <svg class="back-link__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Back to Home
    </a>

    <!-- Page header -->
    <header class="page-header">
      <div class="page-header__icon-wrap" aria-hidden="true">
        <svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="m3 12 13-7 13 7-13 7L3 12Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M8 15v6c3 3 13 3 16 0v-6M29 12v7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </div>
      <h1 class="page-header__title">Student Assistant Application</h1>
      <p class="page-header__subtitle">Complete the 4-step process to apply</p>
    </header>

    <!-- Progress card -->
    <div class="progress-card" aria-label="Application progress">
      <div class="progress-card__meta">
        <span class="progress-card__step-label">Step 1 of 4</span>
        <span class="progress-card__pct-label">25% Complete</span>
      </div>

      <div class="progress-card__bar-track" role="progressbar" aria-valuenow="25" aria-valuemin="0" aria-valuemax="100" aria-label="Application progress 25%">
        <div class="progress-card__bar-fill"></div>
      </div>

      <!-- Step tabs -->
      <nav class="progress-card__steps" aria-label="Application steps">

        <!-- Step 1 – active -->
        <div class="step-tab step-tab--active" aria-current="step" aria-label="Step 1: Personal Info (current)">
          <svg class="step-tab__svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
          </svg>
          <span class="step-tab__label">Personal Info</span>
        </div>

        <!-- Step 2 -->
        <a class="step-tab" href="<?= $step2_link ?>" aria-label="Step 2: Academic Info">
          <svg class="step-tab__svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
          </svg>
          <span class="step-tab__label">Academic Info</span>
        </a>

        <!-- Step 3 -->
        <a class="step-tab" href="<?= $step3_link ?>" aria-label="Step 3: Requirements">
          <svg class="step-tab__svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <span class="step-tab__label">Requirements</span>
        </a>

        <!-- Step 4 -->
        <a class="step-tab" href="<?= $step4_link ?>" aria-label="Step 4: Assessment">
          <svg class="step-tab__svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
          </svg>
          <span class="step-tab__label">Assessment</span>
        </a>

      </nav>
    </div>

    <!-- Personal Information form card -->
    <section class="form-card" aria-labelledby="personal-info-heading">
      <div class="form-card__heading">
        <svg class="form-card__heading-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M4 21c.8-4.1 3.5-6 8-6s7.2 1.9 8 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        <h2 class="form-card__heading-text" id="personal-info-heading">Personal Information</h2>
      </div>

      <form class="personal-form" method="POST" action="" novalidate id="personal-form">

        <div class="form__grid">

          <!-- Full Name -->
          <div class="form__group">
            <label class="form__label" for="full_name">Full Name *</label>
            <input
              class="<?php echo fieldClass('full_name', $errors); ?>"
              type="text"
              id="full_name"
              name="full_name"
              placeholder="Juan Dela Cruz"
              value="<?php echo val('full_name', $values); ?>"
              autocomplete="name"
              required
            />
            <?php echo err('full_name', $errors); ?>
          </div>

          <!-- Student ID -->
          <div class="form__group">
            <label class="form__label" for="student_id">Student ID *</label>
            <input
              class="<?php echo fieldClass('student_id', $errors); ?>"
              type="text"
              id="student_id"
              name="student_id"
              placeholder="2021-12345"
              value="<?php echo val('student_id', $values); ?>"
              autocomplete="off"
              required
            />
            <?php echo err('student_id', $errors); ?>
          </div>

          <!-- Email Address -->
          <div class="form__group">
            <label class="form__label" for="email">Email Address *</label>
            <input
              class="<?php echo fieldClass('email', $errors); ?>"
              type="email"
              id="email"
              name="email"
              placeholder="juan.delacruz@nu-lipa.edu.ph"
              value="<?php echo val('email', $values); ?>"
              autocomplete="email"
              required
            />
            <?php echo err('email', $errors); ?>
          </div>

          <!-- Contact Number -->
          <div class="form__group">
            <label class="form__label" for="contact_number">Contact Number *</label>
            <input
              class="<?php echo fieldClass('contact_number', $errors); ?>"
              type="tel"
              id="contact_number"
              name="contact_number"
              placeholder="09XX-XXX-XXXX"
              value="<?php echo val('contact_number', $values); ?>"
              autocomplete="tel"
              required
            />
            <?php echo err('contact_number', $errors); ?>
          </div>

          <!-- Date of Birth -->
          <div class="form__group">
            <label class="form__label" for="date_of_birth">Date of Birth *</label>
            <input
              class="<?php echo fieldClass('date_of_birth', $errors); ?>"
              type="date"
              id="date_of_birth"
              name="date_of_birth"
              value="<?php echo val('date_of_birth', $values); ?>"
              required
            />
            <?php echo err('date_of_birth', $errors); ?>
          </div>

          <!-- Gender -->
          <div class="form__group">
            <label class="form__label" for="gender">Gender *</label>
            <div class="form__select-wrap">
              <select
                class="form__select<?php echo isset($errors['gender']) ? ' form__select--error' : ''; ?>"
                id="gender"
                name="gender"
                required
              >
                <option value="" <?php echo $values['gender'] === '' ? 'selected' : ''; ?>>Select gender</option>
                <option value="male"   <?php echo $values['gender'] === 'male'   ? 'selected' : ''; ?>>Male</option>
                <option value="female" <?php echo $values['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                <option value="other"  <?php echo $values['gender'] === 'other'  ? 'selected' : ''; ?>>Prefer not to say</option>
              </select>
            </div>
            <?php echo err('gender', $errors); ?>
          </div>

        </div><!-- /.form__grid -->

      </form>
    </section>

    <!-- Navigation buttons -->
    <div class="form-nav">

      <a class="form-nav__back" href="index.php" aria-label="Go back to home">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back
      </a>

      <button
        class="form-nav__next"
        type="submit"
        form="personal-form"
        aria-label="Proceed to next step"
      >
        Next
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>

    </div>

  </main>

  <script>
    (function () {
      'use strict';

      /* ---- Client-side validation before submit ---- */
      var form = document.getElementById('personal-form');
      if (form) {
        form.addEventListener('submit', function (e) {
          var valid = true;
          var fields = form.querySelectorAll('[required]');

          fields.forEach(function (field) {
            var group = field.closest('.form__group');
            var existingErr = group ? group.querySelector('.form__client-error') : null;
            if (existingErr) existingErr.remove();

            field.classList.remove('form__input--error', 'form__select--error');

            if (!field.value.trim()) {
              valid = false;
              field.classList.add(field.tagName === 'SELECT' ? 'form__select--error' : 'form__input--error');
              if (group) {
                var errEl = document.createElement('p');
                errEl.className = 'form__error form__client-error';
                errEl.setAttribute('role', 'alert');
                errEl.textContent = 'This field is required.';
                group.appendChild(errEl);
              }
            } else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
              valid = false;
              field.classList.add('form__input--error');
              if (group) {
                var errEl = document.createElement('p');
                errEl.className = 'form__error form__client-error';
                errEl.setAttribute('role', 'alert');
                errEl.textContent = 'Enter a valid email address.';
                group.appendChild(errEl);
              }
            }
          });

          if (!valid) {
            e.preventDefault();
            var firstErr = form.querySelector('.form__input--error, .form__select--error');
            if (firstErr) firstErr.focus();
          }
        });
      }
    })();
  </script>

<script src="assets/js/sams-theme.js?v=20260926"></script>
</body>
</html>
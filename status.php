<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$submission = $_SESSION['registration_submission'] ?? [];

// Fallback demo values so the page still renders if opened directly.
$student_name   = 'Juan Dela Cruz';
$student_id     = '2021-12345';
$course         = 'BSIT';
$year_level     = '3rd Year';
$date_submitted = date('F j, Y');
$status         = 'PENDING';
$status_title   = '⏳ Application Under Review';
$status_sub     = 'Your application is currently being reviewed by Miss Zai. This typically takes 1-3 business days.';
$showAvailabilityCta = false;

if (!empty($submission['success'])) {
    $date_submitted = date('F j, Y');
    $status_title = '✅ Application Submitted Successfully';
    $status_sub = (string) ($submission['message'] ?? 'Your application has been submitted and is now in the review queue.');
    $showAvailabilityCta = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NU SAMS – Application Status</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <style>
        /* =============================================
           CSS VARIABLES – Design System
        ============================================= */
        :root {
            /* Brand */
            --color-primary:        #003087;
            --color-primary-light:  #0047ab;

            /* Neutral */
            --color-heading:        #101828;
            --color-body:           #4a5565;
            --color-label:          #364153;
            --color-muted:          #6a7282;
            --color-border:         #d1d5dc;
            --color-border-card:    #f3f4f6;
            --color-white:          #ffffff;

            /* Status — Pending / Yellow */
            --color-yellow:         #ffb81c;
            --color-yellow-dark:    #bb4d00;
            --color-yellow-border:  #fee685;
            --color-yellow-badge-bg:#fef3c6;
            --color-yellow-bg:      #fffbeb;
            --color-yellow-step-bg: #fef3c6;
            --color-yellow-step-bd: #fe9a00;
            --color-orange-btn:     #e17100;

            /* Status — Step colours */
            --color-green:          #00c950;
            --color-green-bg:       #dcfce7;
            --color-gray-step-bg:   #f3f4f6;
            --color-gray-step-bd:   #d1d5dc;

            /* Info card */
            --color-blue-muted:     #bedbff;
            --color-blue-light:     #dbeafe;

            /* Gradients */
            --gradient-page:    linear-gradient(132deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%);
            --gradient-icon:    linear-gradient(135deg, #003087 0%, #0047ab 100%);
            --gradient-help:    linear-gradient(163.66deg, #003087 0%, #0047ab 100%);

            /* Radii */
            --radius-card:   16px;
            --radius-inner:  14px;
            --radius-badge:  9999px;
            --radius-btn:    14px;
            --radius-icon:   16px;

            /* Shadows */
            --shadow-card: 0 10px 15px rgba(0,0,0,.10), 0 4px 6px rgba(0,0,0,.10);

            /* Typography */
            --font-xs:   12px;
            --font-sm:   14px;
            --font-base: 16px;
            --font-md:   18px;
            --font-lg:   24px;
            --font-xl:   30px;
            --font-2xl:  36px;

            --lh-xs:   16px;
            --lh-sm:   20px;
            --lh-base: 24px;
            --lh-md:   28px;
            --lh-lg:   32px;
            --lh-xl:   36px;
            --lh-2xl:  40px;
        }

        /* =============================================
           RESET & BASE
        ============================================= */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { min-height: 100%; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gradient-page);
            min-height: 100vh;
            color: var(--color-heading);
        }
        a { text-decoration: none; color: inherit; }
        img { display: block; max-width: 100%; }
        button { font-family: inherit; cursor: pointer; border: none; background: none; }

        /* =============================================
           PAGE WRAPPER
        ============================================= */
        .page {
            min-height: 100vh;
            padding: 48px 0 64px;
        }

        /* =============================================
           CONTENT CONTAINER
        ============================================= */
        .container {
            max-width: 896px; /* 832px content + 32px each side */
            width: 100%;
            margin: 0 auto;
            padding: 0 32px;
            display: flex;
            flex-direction: column;
            gap: 0; /* gaps handled per section */
        }

        /* =============================================
           HERO / PAGE HEADING
        ============================================= */
        .hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 32px;
        }
        .hero__icon-wrap {
            width: 64px;
            height: 64px;
            background: var(--gradient-icon);
            border-radius: var(--radius-icon);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .hero__icon-wrap svg { width: 32px; height: 32px; }
        .hero__title {
            font-size: var(--font-2xl);
            font-weight: 900;
            line-height: var(--lh-2xl);
            color: var(--color-heading);
            text-align: center;
            margin-bottom: 4px;
        }
        .hero__sub {
            font-size: var(--font-md);
            font-weight: 500;
            line-height: var(--lh-md);
            color: var(--color-body);
            text-align: center;
        }

        /* =============================================
           STATUS CARD (yellow / pending)
        ============================================= */
        .status-card {
            background: var(--color-yellow-bg);
            border: 2px solid var(--color-yellow-border);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 34px;
            display: flex;
            flex-direction: column;
            gap: 24px;
            margin-bottom: 32px;
        }

        /* Status hero block */
        .status-card__hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }
        .status-card__clock {
            width: 96px;
            height: 96px;
            background: var(--color-yellow-bg);
            border: 4px solid var(--color-yellow-border);
            border-radius: var(--radius-badge);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .status-card__clock svg { width: 48px; height: 48px; }
        .status-card__state-title {
            font-size: var(--font-xl);
            font-weight: 900;
            line-height: var(--lh-xl);
            color: var(--color-heading);
            text-align: center;
        }
        .status-card__state-sub {
            font-size: var(--font-md);
            font-weight: 500;
            line-height: var(--lh-md);
            color: var(--color-label);
            text-align: center;
            max-width: 645px;
        }

        /* Application details inner card */
        .details-card {
            background: var(--color-white);
            border-radius: var(--radius-inner);
            padding: 24px;
        }
        .details-card__title {
            font-size: var(--font-md);
            font-weight: 900;
            line-height: var(--lh-md);
            color: var(--color-heading);
            margin-bottom: 16px;
        }
        .details-card__grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            row-gap: 20px;
            column-gap: 16px;
        }
        .detail-field__label {
            font-size: var(--font-sm);
            font-weight: 700;
            line-height: var(--lh-sm);
            color: var(--color-body);
            display: block;
            margin-bottom: 4px;
        }
        .detail-field__value {
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-heading);
        }
        .detail-field__value--empty {
            color: var(--color-muted);
            font-weight: 500;
        }

        /* Status badge */
        .status-badge {
            display: inline-block;
            height: 28px;
            padding: 4px 12px;
            border-radius: var(--radius-badge);
            font-size: var(--font-sm);
            font-weight: 900;
            line-height: var(--lh-sm);
            white-space: nowrap;
        }
        .status-badge--pending  { background: var(--color-yellow-badge-bg); color: var(--color-yellow-dark); }
        .status-badge--approved { background: #dcfce7; color: #00a63e; }
        .status-badge--rejected { background: #fee2e2; color: #dc2626; }

        /* Refresh button */
        .status-card__btn-row {
            display: flex;
            justify-content: center;
        }
        .btn-refresh {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 56px;
            padding: 0 32px;
            background: var(--color-orange-btn);
            border: none;
            border-radius: var(--radius-btn);
            box-shadow: var(--shadow-card);
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-white);
            cursor: pointer;
            transition: opacity .15s;
            text-decoration: none;
        }
        .btn-refresh:hover { opacity: .9; }
        .btn-refresh__icon { width: 20px; height: 20px; flex-shrink: 0; }

        /* =============================================
           WHAT HAPPENS NEXT CARD
        ============================================= */
        .next-card {
            background: var(--color-white);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 32px;
            margin-bottom: 32px;
        }
        .next-card__header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        .next-card__header-icon { width: 28px; height: 28px; flex-shrink: 0; }
        .next-card__title {
            font-size: var(--font-lg);
            font-weight: 900;
            line-height: var(--lh-lg);
            color: var(--color-heading);
        }
        .next-steps {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .next-step {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        .next-step__icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-badge);
            border: 2px solid;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .next-step__icon-wrap svg { width: 24px; height: 24px; }
        .next-step__icon-wrap--done   { background: var(--color-green-bg);       border-color: var(--color-green); }
        .next-step__icon-wrap--active { background: var(--color-yellow-step-bg); border-color: var(--color-yellow-step-bd); opacity: .95; }
        .next-step__icon-wrap--future { background: var(--color-gray-step-bg);   border-color: var(--color-gray-step-bd); }

        .next-step__info {}
        .next-step__title {
            font-size: var(--font-md);
            font-weight: 900;
            line-height: var(--lh-md);
            color: var(--color-heading);
            margin-bottom: 4px;
        }
        .next-step__desc {
            font-size: var(--font-base);
            font-weight: 500;
            line-height: var(--lh-base);
            color: var(--color-body);
        }

        /* =============================================
           NEED HELP CARD
        ============================================= */
        .help-card {
            background: var(--gradient-help);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 32px;
            margin-bottom: 36px;
        }
        .help-card__title {
            font-size: var(--font-lg);
            font-weight: 900;
            line-height: var(--lh-lg);
            color: var(--color-white);
            margin-bottom: 12px;
        }
        .help-card__sub {
            font-size: var(--font-base);
            font-weight: 500;
            line-height: var(--lh-base);
            color: var(--color-blue-light);
            margin-bottom: 24px;
        }
        .help-card__contacts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .help-contact {
            background: rgba(255,255,255,.10);
            border-radius: var(--radius-inner);
            height: 76px;
            padding: 0 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .help-contact__icon { width: 24px; height: 24px; flex-shrink: 0; }
        .help-contact__label {
            font-size: var(--font-sm);
            font-weight: 500;
            line-height: var(--lh-sm);
            color: var(--color-blue-muted);
            display: block;
            margin-bottom: 4px;
        }
        .help-contact__value {
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-white);
            white-space: nowrap;
        }

        /* =============================================
           BACK LINK
        ============================================= */
        .back-link {
            display: block;
            text-align: center;
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-primary);
            transition: opacity .15s;
        }
        .back-link:hover { opacity: .75; }

        /* =============================================
           RESPONSIVE – TABLET (≤1024px)
        ============================================= */
        @media (max-width: 1024px) {
            .container { padding: 0 24px; }
            .status-card { padding: 24px; }
            .details-card { padding: 20px; }
            .next-card { padding: 24px; }
            .help-card { padding: 24px; }
        }

        /* =============================================
           RESPONSIVE – MOBILE (≤768px)
        ============================================= */
        @media (max-width: 768px) {
            .page { padding: 32px 0 48px; }
            .container { padding: 0 16px; }

            .hero__title { font-size: 28px; }
            .hero__sub   { font-size: var(--font-base); }

            .status-card { padding: 20px; gap: 20px; }
            .status-card__state-title { font-size: var(--font-lg); }
            .status-card__clock { width: 72px; height: 72px; }

            .details-card__grid {
                grid-template-columns: 1fr;
                row-gap: 16px;
            }

            .next-card { padding: 20px; }
            .next-card__title { font-size: var(--font-md); }
            .next-step__title { font-size: var(--font-base); }
            .next-step__desc  { font-size: var(--font-sm); }

            .help-card { padding: 20px; }
            .help-card__contacts { grid-template-columns: 1fr; }
            .help-card__title { font-size: var(--font-md); }

            .hero__icon-wrap { width: 52px; height: 52px; border-radius: 12px; }
        }
    </style>
</head>
<body>

<main class="page" id="main-content">
    <div class="container">

        <!-- ── HERO ── -->
        <header class="hero">
            <div class="hero__icon-wrap" aria-hidden="true">
                <!-- Clock icon -->
                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="16" cy="16" r="12" stroke="white" stroke-width="2.5"/>
                    <path d="M16 9V16L20 20" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h1 class="hero__title">Application Status</h1>
            <p class="hero__sub">Track your student assistant application</p>
        </header>

        <!-- ── STATUS CARD ── -->
        <section class="status-card" aria-labelledby="status-state-title">

            <!-- Status hero -->
            <div class="status-card__hero">
                <div class="status-card__clock" aria-hidden="true">
                    <!-- Hourglass / clock icon -->
                    <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="24" cy="24" r="18" stroke="#e17100" stroke-width="3"/>
                        <path d="M24 13V24L30 30" stroke="#e17100" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h2 class="status-card__state-title" id="status-state-title"><?= htmlspecialchars($status_title) ?></h2>
                <p class="status-card__state-sub">
                    <?= htmlspecialchars($status_sub) ?>
                </p>
            </div>

            <!-- Application Details -->
            <div class="details-card">
                <h3 class="details-card__title">Application Details</h3>
                <div class="details-card__grid">

                    <div class="detail-field">
                        <span class="detail-field__label">Full Name</span>
                        <span class="detail-field__value"><?= htmlspecialchars($student_name) ?></span>
                    </div>

                    <div class="detail-field">
                        <span class="detail-field__label">Student ID</span>
                        <span class="detail-field__value"><?= htmlspecialchars($student_id) ?></span>
                    </div>

                    <div class="detail-field">
                        <span class="detail-field__label">Course</span>
                        <span class="detail-field__value"><?= htmlspecialchars($course) ?></span>
                    </div>

                    <div class="detail-field">
                        <span class="detail-field__label">Year Level</span>
                        <span class="detail-field__value"><?= htmlspecialchars($year_level) ?></span>
                    </div>

                    <div class="detail-field">
                        <span class="detail-field__label">Date Submitted</span>
                        <span class="detail-field__value"><?= htmlspecialchars($date_submitted) ?></span>
                    </div>

                    <div class="detail-field">
                        <span class="detail-field__label">Status</span>
                        <?php
                        $badge_class = 'status-badge--pending';
                        if ($status === 'APPROVED') $badge_class = 'status-badge--approved';
                        if ($status === 'REJECTED') $badge_class = 'status-badge--rejected';
                        ?>
                        <span class="status-badge <?= $badge_class ?>" role="status">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </div>

                </div>
            </div>

            <!-- Refresh button -->
            <div class="status-card__btn-row">
                <a href="status.php" class="btn-refresh" role="button" aria-label="Refresh application status">
                    <!-- Refresh icon -->
                    <svg class="btn-refresh__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M3.33 8.33A6.67 6.67 0 0 1 16.5 6.5" stroke="white" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M3 5V9H7" stroke="white" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16.67 11.67A6.67 6.67 0 0 1 3.5 13.5" stroke="white" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M17 15v-4h-4" stroke="white" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Refresh Status
                </a>
                <?php if ($showAvailabilityCta): ?>
                <a href="students/availability.php" class="btn-refresh" role="button" aria-label="Continue to availability">
                    Continue to Availability
                </a>
                <?php endif; ?>
            </div>

        </section>

        <!-- ── WHAT HAPPENS NEXT ── -->
        <section class="next-card" aria-labelledby="next-heading">
            <div class="next-card__header">
                <!-- Calendar/arrow icon -->
                <svg class="next-card__header-icon" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="3.5" y="4" width="21" height="20" rx="3" stroke="#003087" stroke-width="2"/>
                    <path d="M3.5 10H24.5" stroke="#003087" stroke-width="2"/>
                    <path d="M9 2V6" stroke="#003087" stroke-width="2" stroke-linecap="round"/>
                    <path d="M19 2V6" stroke="#003087" stroke-width="2" stroke-linecap="round"/>
                    <path d="M10 17l2.5 2.5 5-5" stroke="#003087" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <h2 class="next-card__title" id="next-heading">What Happens Next?</h2>
            </div>

            <ol class="next-steps" aria-label="Application process steps">

                <!-- Step 1 – Done -->
                <li class="next-step">
                    <div class="next-step__icon-wrap next-step__icon-wrap--done" aria-label="Completed">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="#00c950" stroke-width="2"/>
                            <path d="M8 12L11 15L16 9" stroke="#00c950" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="next-step__info">
                        <p class="next-step__title">Step 1: Application Submitted ✅</p>
                        <p class="next-step__desc">Your application has been successfully submitted and is now in the review queue.</p>
                    </div>
                </li>

                <!-- Step 2 – Active (Under Review) -->
                <li class="next-step">
                    <div class="next-step__icon-wrap next-step__icon-wrap--active" aria-label="In progress">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="#fe9a00" stroke-width="2"/>
                            <path d="M12 7V12L15 15" stroke="#fe9a00" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="next-step__info">
                        <p class="next-step__title">Step 2: Under Review ⏳</p>
                        <p class="next-step__desc">Miss Zai is currently reviewing your application and documents. Expected: 1-3 business days.</p>
                    </div>
                </li>

                <!-- Step 3 – Future -->
                <li class="next-step">
                    <div class="next-step__icon-wrap next-step__icon-wrap--future" aria-label="Pending">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="#d1d5dc" stroke-width="2"/>
                            <path d="M9 10.5C9 9.12 10.12 8 11.5 8h.5a2 2 0 0 1 1 3.73L12 12.5V14" stroke="#9ca3af" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="12" cy="16.5" r=".75" fill="#9ca3af"/>
                        </svg>
                    </div>
                    <div class="next-step__info">
                        <p class="next-step__title">Step 3: Decision &amp; Notification</p>
                        <p class="next-step__desc">You'll receive an email notification once your application is approved or if additional information is needed.</p>
                    </div>
                </li>

                <!-- Step 4 – Future -->
                <li class="next-step">
                    <div class="next-step__icon-wrap next-step__icon-wrap--future" aria-label="Pending">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="3" y="3" width="18" height="18" rx="3" stroke="#d1d5dc" stroke-width="2"/>
                            <path d="M8 9H16M8 13H14M8 17H11" stroke="#9ca3af" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="next-step__info">
                        <p class="next-step__title">Step 4: Access Dashboard</p>
                        <p class="next-step__desc">Once approved, you'll gain access to the student dashboard to view schedules and log attendance.</p>
                    </div>
                </li>

            </ol>
        </section>

        <!-- ── NEED HELP ── -->
        <section class="help-card" aria-labelledby="help-heading">
            <h2 class="help-card__title" id="help-heading">Need Help?</h2>
            <p class="help-card__sub">
                If you have questions about your application or need assistance, please contact the SDAO office:
            </p>
            <div class="help-card__contacts">

                <div class="help-contact">
                    <!-- Email icon -->
                    <svg class="help-contact__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect x="2" y="4" width="20" height="16" rx="3" stroke="#bedbff" stroke-width="1.67"/>
                        <path d="M2 7l10 7 10-7" stroke="#bedbff" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <div>
                        <span class="help-contact__label">Email</span>
                        <a href="mailto:sdao@nu-lipa.edu.ph" class="help-contact__value">sdao@nu-lipa.edu.ph</a>
                    </div>
                </div>

                <div class="help-contact">
                    <!-- Phone icon -->
                    <svg class="help-contact__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 12l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2Z" stroke="#bedbff" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <div>
                        <span class="help-contact__label">Phone</span>
                        <a href="tel:+6343723 0706" class="help-contact__value">(043) 723-0706</a>
                    </div>
                </div>

            </div>
        </section>

        <!-- ── BACK LINK ── -->
        <a href="index.php" class="back-link" aria-label="Back to Home">← Back to Home</a>

    </div>
</main>

<script>
(function () {
    'use strict';
    // No interactive behaviours required beyond the anchor-based refresh.
    // Hamburger nav is not present in this design (standalone page, no sidebar).
})();
</script>

</body>
</html>
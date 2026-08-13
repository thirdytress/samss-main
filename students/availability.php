<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$submission = $_SESSION['registration_submission'] ?? [];
$user = sams_authenticated_user();

$isRegistration = !empty($_SESSION['registration_submission']);
if (!$isRegistration) {
    if (!$user || ($user['role'] ?? null) !== 'student') {
        header('Location: ../login.php');
        exit;
    }
}
$pageTitle = $isRegistration ? 'Complete Your Application' : 'Set Your Availability';
$pageSubtitle = $isRegistration 
    ? 'Please set your weekly time availability below to complete and submit your application.' 
    : 'Complete your schedule preferences so the office can match you with shifts for the current term.';
$buttonText = $isRegistration ? 'Submit Application' : 'Save Availability';

$applicationId = (int) ($submission['application_id'] ?? 0);
$termId = (int) ($submission['term_id'] ?? 0);
$studentId = (int) ($submission['student_id'] ?? 0);
$message = '';

if (!empty($submission['message'])) {
    $message = (string) $submission['message'];
}

if (($applicationId <= 0 || $termId <= 0 || $studentId <= 0) && $user) {
    $pdo = sams_pdo();

    $userId = (int) ($user['id'] ?? $user['user_id'] ?? 0);

    if ($userId > 0) {
        $studentStatement = $pdo->prepare(
            'SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1'
        );
        $studentStatement->execute([
            'user_id' => $userId
        ]);

        $studentId = (int) ($studentStatement->fetchColumn() ?: 0);

        if ($studentId > 0) {
            $applicationStatement = $pdo->prepare(
                'SELECT application_id, term_id 
                 FROM applications 
                 WHERE student_id = :student_id 
                 ORDER BY application_id DESC 
                 LIMIT 1'
            );

            $applicationStatement->execute([
                'student_id' => $studentId
            ]);

            $applicationRow = $applicationStatement->fetch();

            if ($applicationRow) {
                $applicationId = (int) $applicationRow['application_id'];
                $termId = (int) $applicationRow['term_id'];
            }
        }
    }
}

$days = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($pageTitle) ?> – SAMS Student Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />

    <style>
        :root {
            --color-primary: #003087;
            --color-primary-end: #0047ab;
            --color-gold: #ffb81c;
            --color-dark: #101828;
            --color-body: #364153;
            --color-muted: #6a7282;
            --color-border: #d1d5dc;
            --color-white: #ffffff;
            --color-bg: linear-gradient(135deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%);
            --shadow-card: 0 10px 15px rgba(0,0,0,.10), 0 4px 6px rgba(0,0,0,.10);
            --radius-card: 16px;
            --radius-md: 14px;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            color: var(--color-dark);
            background: var(--color-bg);
            min-height: 100vh;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1120px;
            margin: 0 auto;
            padding: 32px;
        }

        .hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 24px;
        }

        .hero__badge {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            color: var(--color-white);
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-end) 100%);
            box-shadow: var(--shadow-card);
            margin-bottom: 16px;
            font-size: 28px;
            font-weight: 900;
        }

        .hero__title {
            margin: 0 0 6px;
            font-size: 36px;
            line-height: 1.1;
            font-weight: 900;
        }

        .hero__sub {
            margin: 0;
            font-size: 18px;
            color: var(--color-body);
            max-width: 720px;
        }

        .card {
            background: var(--color-white);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 28px;
            margin-top: 24px;
        }

        .notice {
            background: #fef3c6;
            border: 2px solid #ffb81c;
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 20px;
            color: #7c2d12;
            font-weight: 700;
        }

        .meta {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .meta__item {
            padding: 14px 16px;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            background: #fafafa;
        }

        .meta__label {
            display: block;
            font-size: 12px;
            color: var(--color-muted);
            margin-bottom: 6px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .meta__value {
            font-size: 16px;
            font-weight: 700;
            color: var(--color-dark);
        }

        .availability-days {
            display: grid;
            gap: 14px;
            margin-top: 8px;
        }

        .day-row {
            border: 1px solid var(--color-border);
            border-radius: 14px;
            background: #fff;
            padding: 14px;
            display: grid;
            grid-template-columns: 140px minmax(0, 1fr) minmax(0, 1fr);
            gap: 12px;
            align-items: start;
        }

        .day-row__label {
            font-weight: 800;
            color: var(--color-dark);
            padding-top: 8px;
        }

        .slot-card {
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 12px;
            background: #fafcff;
        }

        .slot-card__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 10px;
        }

        .slot-card__title {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--color-primary);
        }

        .slot-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            color: var(--color-body);
        }

        .time-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 10px;
        }

        .time-field__label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--color-muted);
            margin-bottom: 6px;
        }

        .time-field input[type="time"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--color-border);
            border-radius: 12px;
            font: inherit;
        }

        .time-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .time-wrapper input[type="time"] {
            flex: 1;
            margin: 0;
        }

        .time-ampm {
            font-size: 13px;
            font-weight: 600;
            color: var(--color-muted);
            min-width: 32px;
            text-align: center;
        }

        .slot-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--color-primary);
        }

        .availability-note {
            width: 100%;
            min-height: 72px;
            padding: 10px 12px;
            border: 1px solid var(--color-border);
            border-radius: 12px;
            font: inherit;
            font-size: 13px;
            resize: vertical;
            line-height: 1.4;
        }

        .actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .btn,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 52px;
            padding: 0 22px;
            border-radius: 14px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            font: inherit;
        }

        .btn {
            color: #003087;
            background: linear-gradient(90deg, #ffb81c 0%, #ffa500 100%);
        }

        .btn-secondary {
            color: var(--color-primary);
            background: #fff;
            border: 2px solid var(--color-primary);
        }

        .helper {
            margin-top: 16px;
            font-size: 14px;
            color: var(--color-muted);
        }

        .error {
            padding: 16px;
            margin: 0 0 24px 0;
            color: #7f1d1d;
            background-color: #fee2e2;
            border: 2px solid #b91c1c;
            border-radius: var(--radius-md);
            font-weight: 700;
            font-size: 14px;
            line-height: 1.6;
        }

        @media (max-width: 768px) {
            .page {
                padding: 20px;
            }

            .meta {
                grid-template-columns: 1fr;
            }

            .day-row {
                grid-template-columns: 1fr;
            }

            .day-row__label {
                padding-top: 0;
            }

            .time-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <main class="page">
        <section class="hero">
            <div class="hero__badge">A</div>
            <h1 class="hero__title"><?= htmlspecialchars($pageTitle) ?></h1>
            <p class="hero__sub"><?= htmlspecialchars($pageSubtitle) ?></p>
        </section>

        <section class="card">
            <?php if ($message !== ''): ?>
                <div class="notice"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div id="error-box" class="error" hidden></div>

            <div class="meta">
                <div class="meta__item">
                    <span class="meta__label">Application ID</span>
                    <span class="meta__value"><?= $applicationId > 0 ? htmlspecialchars((string) $applicationId) : 'Not available yet' ?></span>
                </div>

                <div class="meta__item">
                    <span class="meta__label">Student ID</span>
                    <span class="meta__value"><?= $studentId > 0 ? htmlspecialchars((string) $studentId) : 'Not available yet' ?></span>
                </div>

                <div class="meta__item">
                    <span class="meta__label">Term ID</span>
                    <span class="meta__value"><?= $termId > 0 ? htmlspecialchars((string) $termId) : 'Not available yet' ?></span>
                </div>
            </div>

            <form id="availability-form">
                <div class="availability-days">
                    <?php foreach ($days as $dayIndex => $day): ?>
                        <section class="day-row" data-index="<?= (int) $dayIndex ?>" data-day="<?= htmlspecialchars($day) ?>">
                            <div class="day-row__label"><?= htmlspecialchars($day) ?></div>

                            <article class="slot-card">
                                <div class="slot-card__head">
                                    <div class="slot-card__title">Morning</div>
                                    <label class="slot-toggle">
                                        <input type="checkbox" class="availability-enabled-morning" data-index="<?= (int) $dayIndex ?>" checked />
                                        <span>Available</span>
                                    </label>
                                </div>

                                <div class="time-grid">
                                    <div class="time-field">
                                        <label class="time-field__label" for="morning-start-<?= (int) $dayIndex ?>">Start</label>
                                        <div class="time-wrapper">
                                            <input id="morning-start-<?= (int) $dayIndex ?>" type="time" class="availability-start-morning" data-index="<?= (int) $dayIndex ?>" value="08:00" />
                                            <span class="time-ampm">AM</span>
                                        </div>
                                    </div>
                                    <div class="time-field">
                                        <label class="time-field__label" for="morning-end-<?= (int) $dayIndex ?>">End</label>
                                        <div class="time-wrapper">
                                            <input id="morning-end-<?= (int) $dayIndex ?>" type="time" class="availability-end-morning" data-index="<?= (int) $dayIndex ?>" value="12:00" />
                                            <span class="time-ampm">PM</span>
                                        </div>
                                    </div>
                                </div>

                                <textarea
                                    class="availability-note availability-note-morning"
                                    data-index="<?= (int) $dayIndex ?>"
                                    placeholder="Optional: Why did you choose this morning slot?"
                                    maxlength="500"
                                ></textarea>
                            </article>

                            <article class="slot-card">
                                <div class="slot-card__head">
                                    <div class="slot-card__title">Afternoon</div>
                                    <label class="slot-toggle">
                                        <input type="checkbox" class="availability-enabled-afternoon" data-index="<?= (int) $dayIndex ?>" />
                                        <span>Available</span>
                                    </label>
                                </div>

                                <div class="time-grid">
                                    <div class="time-field">
                                        <label class="time-field__label" for="afternoon-start-<?= (int) $dayIndex ?>">Start</label>
                                        <div class="time-wrapper">
                                            <input id="afternoon-start-<?= (int) $dayIndex ?>" type="time" class="availability-start-afternoon" data-index="<?= (int) $dayIndex ?>" value="13:00" />
                                            <span class="time-ampm">PM</span>
                                        </div>
                                    </div>
                                    <div class="time-field">
                                        <label class="time-field__label" for="afternoon-end-<?= (int) $dayIndex ?>">End</label>
                                        <div class="time-wrapper">
                                            <input id="afternoon-end-<?= (int) $dayIndex ?>" type="time" class="availability-end-afternoon" data-index="<?= (int) $dayIndex ?>" value="20:00" />
                                            <span class="time-ampm">PM</span>
                                        </div>
                                    </div>
                                </div>

                                <textarea
                                    class="availability-note availability-note-afternoon"
                                    data-index="<?= (int) $dayIndex ?>"
                                    placeholder="Optional: Why did you choose this afternoon slot?"
                                    maxlength="500"
                                ></textarea>
                            </article>
                        </section>
                    <?php endforeach; ?>
                </div>

                <div class="actions">
                    <button class="btn" type="submit"><?= htmlspecialchars($buttonText) ?></button>
                </div>

                <p class="helper">Set your available hours for morning (8am-12pm) and/or afternoon (1pm-8pm). You can enable/disable each slot and adjust times as needed. Each schedule assigned will be minimum 2 hours, your total weekly availability must be at least 10 hours, and per-slot reason notes are optional.</p>
            </form>
        </section>
    </main>

    <script>
    (function () {
        'use strict';

        var form = document.getElementById('availability-form');
        var errorBox = document.getElementById('error-box');

        var applicationId = <?= (int) $applicationId ?>;
        var studentId = <?= (int) $studentId ?>;
        var termId = <?= (int) $termId ?>;
        var csrfToken = <?= json_encode(sams_csrf_token()) ?>;

        function showError(message) {
            errorBox.textContent = message;
            errorBox.hidden = false;
        }

        function clearError() {
            errorBox.textContent = '';
            errorBox.hidden = true;
        }

        function calculateSlotHours(start, end) {
            if (!start || !end) {
                return 0;
            }

            var startParts = start.split(':');
            var endParts = end.split(':');
            if (startParts.length !== 2 || endParts.length !== 2) {
                return 0;
            }

            var startMinutes = (parseInt(startParts[0], 10) * 60) + parseInt(startParts[1], 10);
            var endMinutes = (parseInt(endParts[0], 10) * 60) + parseInt(endParts[1], 10);
            if (!Number.isFinite(startMinutes) || !Number.isFinite(endMinutes) || endMinutes <= startMinutes) {
                return 0;
            }

            return (endMinutes - startMinutes) / 60;
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                clearError();

                if (!applicationId || !studentId || !termId) {
                    showError('Missing application context. Please go back and submit your application again.');
                    return;
                }

                var entries = [];
                var totalHours = 0;
                var rows = document.querySelectorAll('.day-row[data-day]');

                rows.forEach(function (row) {
                    var index = row.getAttribute('data-index');
                    var day = row.getAttribute('data-day');

                    // Morning slot
                    var enabledMorning = document.querySelector('.availability-enabled-morning[data-index="' + index + '"]');
                    var startMorning = document.querySelector('.availability-start-morning[data-index="' + index + '"]');
                    var endMorning = document.querySelector('.availability-end-morning[data-index="' + index + '"]');
                    var noteMorning = document.querySelector('.availability-note-morning[data-index="' + index + '"]');

                    if (enabledMorning && enabledMorning.checked && startMorning && endMorning) {
                        totalHours += calculateSlotHours(startMorning.value, endMorning.value);
                        entries.push({
                            day_of_week: day,
                            time_start: startMorning.value,
                            time_end: endMorning.value,
                            notes: noteMorning ? noteMorning.value.trim() : '',
                            is_available: 1
                        });
                    }

                    // Afternoon slot
                    var enabledAfternoon = document.querySelector('.availability-enabled-afternoon[data-index="' + index + '"]');
                    var startAfternoon = document.querySelector('.availability-start-afternoon[data-index="' + index + '"]');
                    var endAfternoon = document.querySelector('.availability-end-afternoon[data-index="' + index + '"]');
                    var noteAfternoon = document.querySelector('.availability-note-afternoon[data-index="' + index + '"]');

                    if (enabledAfternoon && enabledAfternoon.checked && startAfternoon && endAfternoon) {
                        totalHours += calculateSlotHours(startAfternoon.value, endAfternoon.value);
                        entries.push({
                            day_of_week: day,
                            time_start: startAfternoon.value,
                            time_end: endAfternoon.value,
                            notes: noteAfternoon ? noteAfternoon.value.trim() : '',
                            is_available: 1
                        });
                    }
                });

                if (entries.length === 0) {
                    showError('Please choose at least one availability slot.');
                    return;
                }

                if (totalHours < 10) {
                    showError('Minimum required availability is 10 hours per week.');
                    return;
                }

                fetch('save_availability.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        application_id: applicationId,
                        student_id: studentId,
                        term_id: termId,
                        availability: entries,
                        _csrf: csrfToken
                    })
                })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok || payload.success === false) {
                            throw new Error(payload.error || 'Unable to save availability.');
                        }

                        return payload;
                    });
                })
                .then(function () {
                    window.location.href = '../status.php';
                })
                .catch(function (error) {
                    showError(error.message || 'Unable to save availability.');
                });
            });
        }
    })();
    </script>
</body>
</html>
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$submission = $_SESSION['registration_submission'] ?? [];
$user = sams_authenticated_user();

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
    <title>Availability – SAMS Student Portal</title>
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

        .availability-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        .availability-table th,
        .availability-table td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--color-border);
            vertical-align: middle;
        }

        .availability-table th {
            text-align: left;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--color-muted);
        }

        .availability-table td:first-child {
            font-weight: 700;
            width: 20%;
        }

        .availability-table input[type="time"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--color-border);
            border-radius: 12px;
            font: inherit;
        }

        .availability-table input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--color-primary);
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
            margin-top: 16px;
            color: #b91c1c;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .page {
                padding: 20px;
            }

            .meta {
                grid-template-columns: 1fr;
            }

            .availability-table,
            .availability-table thead,
            .availability-table tbody,
            .availability-table th,
            .availability-table td,
            .availability-table tr {
                display: block;
                width: 100%;
            }

            .availability-table thead {
                display: none;
            }

            .availability-table tr {
                border: 1px solid var(--color-border);
                border-radius: 14px;
                padding: 12px;
                margin-bottom: 12px;
                background: #fff;
            }

            .availability-table td {
                border: none;
                padding: 8px 0;
            }

            .availability-table td:first-child {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <main class="page">
        <section class="hero">
            <div class="hero__badge">A</div>
            <h1 class="hero__title">Set Your Availability</h1>
            <p class="hero__sub">Complete your schedule preferences so the office can match you with shifts for the current term.</p>
        </section>

        <section class="card">
            <?php if ($message !== ''): ?>
                <div class="notice"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

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
                <table class="availability-table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th>Available</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($days as $dayIndex => $day): ?>
                            <tr data-index="<?= (int) $dayIndex ?>" data-day="<?= htmlspecialchars($day) ?>">
                                <td><?= htmlspecialchars($day) ?></td>
                                <td>
                                    <input type="checkbox" class="availability-enabled" data-index="<?= (int) $dayIndex ?>" checked />
                                </td>
                                <td>
                                    <input type="time" class="availability-start" data-index="<?= (int) $dayIndex ?>" value="08:00" />
                                </td>
                                <td>
                                    <input type="time" class="availability-end" data-index="<?= (int) $dayIndex ?>" value="17:00" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="actions">
                    <a class="btn-secondary" href="../status.php">Skip for now</a>
                    <button class="btn" type="submit">Save Availability</button>
                </div>

                <p class="helper">At least one active day is required. You can edit this later once your schedule is finalized.</p>
                <div id="error-box" class="error" hidden></div>
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

        function showError(message) {
            errorBox.textContent = message;
            errorBox.hidden = false;
        }

        function clearError() {
            errorBox.textContent = '';
            errorBox.hidden = true;
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
                var rows = document.querySelectorAll('tr[data-day]');

                rows.forEach(function (row) {
                    var index = row.getAttribute('data-index');
                    var day = row.getAttribute('data-day');

                    var enabled = document.querySelector('.availability-enabled[data-index="' + index + '"]');
                    var start = document.querySelector('.availability-start[data-index="' + index + '"]');
                    var end = document.querySelector('.availability-end[data-index="' + index + '"]');

                    if (!enabled || !start || !end || !enabled.checked) {
                        return;
                    }

                    entries.push({
                        day_of_week: day,
                        time_start: start.value,
                        time_end: end.value,
                        is_available: 1
                    });
                });

                if (entries.length === 0) {
                    showError('Please choose at least one availability day.');
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
                        availability: entries
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
<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

function sams_split_full_name(string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];

    if (count($parts) === 0) {
        return ['', ''];
    }

    if (count($parts) === 1) {
        return [$parts[0], ''];
    }

    $firstName = array_shift($parts);
    $lastName = implode(' ', $parts);

    return [$firstName, $lastName];
}

function sams_map_year_level(string $value): string
{
    return match ($value) {
        '1' => '1st',
        '2' => '2nd',
        '3' => '3rd',
        '4' => '4th',
        '5' => '4th',
        default => '1st',
    };
}

function sams_registration_data(): array
{
    return $_SESSION['sams_registration'] ?? [];
}



$errors  = [];
$success = false;

$registration = sams_registration_data();
$step1 = $registration['step1'] ?? [];
$step2 = $registration['step2'] ?? [];
$step3 = $registration['step3'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $work_location = trim($_POST['work_location'] ?? '');
    $skills        = trim($_POST['skills'] ?? '');
    $agree_terms   = isset($_POST['agree_terms']);
    $agree_privacy = isset($_POST['agree_privacy']);

    if ($work_location === '') {
        $errors['work_location'] = 'Preferred Work Location is required.';
    }
    if (!$agree_terms) {
        $errors['agree_terms'] = 'You must agree to the Terms and Conditions.';
    }
    if (!$agree_privacy) {
        $errors['agree_privacy'] = 'You must consent to the Data Privacy Act.';
    }

    if (empty($step1) || empty($step2) || empty($step3)) {
        $errors['flow'] = 'Your registration session is incomplete. Please start again from Step 1.';
    }

    if (empty($errors)) {
        $pdo = sams_pdo();

        try {
            $email = trim((string) ($step1['email'] ?? ''));
            $studentCode = trim((string) ($step1['student_id'] ?? ''));

            if (!sams_column_exists($pdo, 'students', 'student_id_number')) {
                throw new RuntimeException('Students table must have student_id_number column.');
            }

            $duplicateCheck = $pdo->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM users WHERE email = :email) AS email_count,
                        (SELECT COUNT(*) FROM students WHERE student_id_number = :student_code) AS student_count"
            );
            $duplicateCheck->execute([
                'email' => $email,
                'student_code' => $studentCode,
            ]);
            $duplicateCounts = $duplicateCheck->fetch() ?: ['email_count' => 0, 'student_count' => 0];

            if ((int) ($duplicateCounts['email_count'] ?? 0) > 0) {
                throw new RuntimeException('This email is already registered. Please use a different email or log in.');
            }

            if ((int) ($duplicateCounts['student_count'] ?? 0) > 0) {
                throw new RuntimeException('This student ID is already registered. Please use a different student ID or log in.');
            }

            $pdo->beginTransaction();

            $termIdColumn = sams_first_existing_column($pdo, 'terms', ['id', 'term_id']);
            if ($termIdColumn === null) {
                throw new RuntimeException('Terms table must have id or term_id column.');
            }

            $termStatement = $pdo->query("SELECT {$termIdColumn} FROM terms WHERE is_active = 1 ORDER BY {$termIdColumn} DESC LIMIT 1");
            $activeTermId = $termStatement->fetchColumn();

            if ($activeTermId === false) {
                throw new RuntimeException('No active term is configured yet. Please activate a term before accepting applications.');
            }

            $fullName = trim((string) ($step1['full_name'] ?? ''));
            [$firstName, $lastName] = sams_split_full_name($fullName);
            $contactNumber = trim((string) ($step1['contact_number'] ?? ''));
            $course = trim((string) ($step2['course'] ?? ''));
            $yearLevel = sams_map_year_level((string) ($step2['year_level'] ?? '1'));
            $gpa = trim((string) ($step2['gpa'] ?? ''));

            // Default password is the student ID. Student can change it later if you add that feature.
            $userPasswordHash = password_hash($studentCode, PASSWORD_DEFAULT);
            $passwordColumn = sams_first_existing_column($pdo, 'users', ['password', 'password_hash']);
            if ($passwordColumn === null) {
                throw new RuntimeException('Users table must have password or password_hash column.');
            }

            $userColumns = ['email', $passwordColumn, 'role', 'first_name', 'last_name', 'is_active'];
            $userParams = [':email', ':password_value', ':role', ':first_name', ':last_name', ':is_active'];
            $userValues = [
                'email' => $email,
                'password_value' => $userPasswordHash,
                'role' => 'student',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => 0,
            ];

            if (sams_column_exists($pdo, 'users', 'must_change_password')) {
                $userColumns[] = 'must_change_password';
                $userParams[] = ':must_change_password';
                $userValues['must_change_password'] = 1;
            }

            if (sams_column_exists($pdo, 'users', 'phone_number')) {
                $userColumns[] = 'phone_number';
                $userParams[] = ':phone_number';
                $userValues['phone_number'] = $contactNumber;
            }

            $userStatement = $pdo->prepare(
                'INSERT INTO users (' . implode(', ', $userColumns) . ')
                 VALUES (' . implode(', ', $userParams) . ')'
            );
            $userStatement->execute($userValues);

            $userId = (int) $pdo->lastInsertId();

            $studentStatement = $pdo->prepare(
                "INSERT INTO students (
                    user_id, student_id_number, program, year_level, current_gpa,
                    is_enrolled, is_good_standing, fingerprint_id
                 ) VALUES (
                    :user_id, :student_code, :program, :year_level, :current_gpa,
                    :is_enrolled, :is_good_standing, :fingerprint_id
                 )"
            );
            $studentStatement->execute([
                'user_id' => $userId,
                'student_code' => $studentCode,
                'program' => $course,
                'year_level' => $yearLevel,
                'current_gpa' => $gpa !== '' ? $gpa : null,
                'is_enrolled' => 1,
                'is_good_standing' => 1,
                'fingerprint_id' => null,
            ]);

            $studentId = (int) $pdo->lastInsertId();

            $applicationStatement = $pdo->prepare(
                'INSERT INTO applications (
                    student_id, term_id, status, preferred_office, skills, submitted_at
                 ) VALUES (
                    :student_id, :term_id, :status, :preferred_office, :skills, NOW()
                 )'
            );
            $applicationStatement->execute([
                'student_id' => $studentId,
                'term_id' => (int) $activeTermId,
                'status' => 'pending',
                'preferred_office' => $work_location,
                'skills' => $skills,
            ]);

            $applicationId = (int) $pdo->lastInsertId();

            $tempFolder = $step3['temp_folder'] ?? '';
            $storedFiles = $step3['files'] ?? [];
            $uploadBase = __DIR__ . '/uploads/documents/student_' . $studentId . '/application_' . $applicationId;

            if ($tempFolder !== '' && is_dir($tempFolder) && !is_dir($uploadBase)) {
                mkdir($uploadBase, 0777, true);
            }

            $documentMap = [
                'cog' => 'grade_slip',
                'valid_id' => 'valid_id',
                'photo' => 'other',
            ];

            if (is_array($storedFiles) && sams_column_exists($pdo, 'document_uploads', 'application_id')) {
                $documentUserColumn = sams_first_existing_column($pdo, 'document_uploads', ['user_id', 'student_id']);

                $documentColumns = [];
                $documentValues = [];

                if ($documentUserColumn !== null) {
                    $documentColumns[] = $documentUserColumn;
                    $documentValues[] = ':' . $documentUserColumn;
                }

                $requiredDocumentColumns = [
                    'application_id', 'document_type', 'original_filename',
                    'stored_filename', 'file_path', 'file_size', 'mime_type'
                ];

                foreach ($requiredDocumentColumns as $column) {
                    if (sams_column_exists($pdo, 'document_uploads', $column)) {
                        $documentColumns[] = $column;
                        $documentValues[] = ':' . $column;
                    }
                }

                if (sams_column_exists($pdo, 'document_uploads', 'uploaded_at')) {
                    $documentColumns[] = 'uploaded_at';
                    $documentValues[] = 'NOW()';
                }

                if (count($documentColumns) > 0) {
                    $documentStatement = $pdo->prepare(
                        'INSERT INTO document_uploads (' . implode(', ', $documentColumns) . ')
                         VALUES (' . implode(', ', $documentValues) . ')'
                    );

                    foreach ($storedFiles as $fileKey => $fileMeta) {
                        $tempPath = $fileMeta['stored_path'] ?? '';
                        if ($tempPath === '' || !file_exists($tempPath)) {
                            continue;
                        }

                        if (!is_dir($uploadBase)) {
                            mkdir($uploadBase, 0777, true);
                        }

                        $originalName = (string) ($fileMeta['original_name'] ?? basename($tempPath));
                        $targetPath = $uploadBase . '/' . basename((string) ($fileMeta['stored_name'] ?? basename($tempPath)));

                        if (!rename($tempPath, $targetPath)) {
                            throw new RuntimeException('Unable to finalize uploaded file: ' . $originalName);
                        }

                        $documentData = [];
                        if ($documentUserColumn === 'user_id') {
                            $documentData['user_id'] = $userId;
                        } elseif ($documentUserColumn === 'student_id') {
                            $documentData['student_id'] = $studentId;
                        }

                        $possibleDocumentData = [
                            'application_id' => $applicationId,
                            'document_type' => $documentMap[$fileKey] ?? 'other',
                            'original_filename' => $originalName,
                            'stored_filename' => basename($targetPath),
                            'file_path' => 'uploads/documents/student_' . $studentId . '/application_' . $applicationId . '/' . basename($targetPath),
                            'file_size' => (int) ($fileMeta['size'] ?? 0),
                            'mime_type' => (string) ($fileMeta['mime_type'] ?? 'application/octet-stream'),
                        ];

                        foreach ($possibleDocumentData as $column => $value) {
                            if (in_array($column, $documentColumns, true)) {
                                $documentData[$column] = $value;
                            }
                        }

                        $documentStatement->execute($documentData);
                    }
                }
            }

            $pdo->commit();

            unset($_SESSION['sams_registration']);
            $_SESSION['registration_submission'] = [
                'success' => true,
                'message' => 'Your application has been submitted successfully. Your account is pending review, and you can set your availability next.',
                'application_id' => $applicationId,
                'student_id' => $studentId,
                'term_id' => (int) $activeTermId,
                'student_name' => $fullName,
                'student_number' => $studentCode,
                'course' => $course,
                'year_level' => $yearLevel,
                'date_submitted' => date('F j, Y'),
                'status' => 'PENDING',
            ];

            header('Location: students/availability.php');
            exit;
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors['flow'] = $exception->getMessage();
        }
    }
}

if (!empty($errors)) {
    error_log('register3: errors=' . json_encode($errors));
    error_log('register3: session=' . json_encode($_SESSION['sams_registration'] ?? []));
}

$val_location = htmlspecialchars($_POST['work_location'] ?? '');
$val_skills   = htmlspecialchars($_POST['skills'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Student Assistant Application – Step 4</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <style>
        /* =============================================
           CSS VARIABLES – Design System
        ============================================= */
        :root {
            --color-primary:        #003087;
            --color-heading:        #101828;
            --color-body:           #4a5565;
            --color-label:          #364153;
            --color-dark:           #1e2939;
            --color-muted:          #6a7282;
            --color-border:         #d1d5dc;
            --color-bg-info:        #f9fafb;
            --color-bg-warn:        #fffbeb;
            --color-border-warn:    #ffb81c;
            --color-white:          #ffffff;
            --color-placeholder:    rgba(10,10,10,0.5);
            --color-error:          #dc2626;
            --color-submit-text:    #003087;

            --gradient-bg:          linear-gradient(138.29deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%);
            --gradient-primary:     linear-gradient(135deg, #003087 0%, #0047ab 100%);
            --gradient-steps:       linear-gradient(159.33deg, #003087 0%, #0047ab 100%);
            --gradient-progress:    linear-gradient(90deg, #003087 0%, #ffb81c 100%);
            --gradient-submit:      linear-gradient(90deg, #ffb81c 0%, #ffa500 100%);

            --radius-card:  16px;
            --radius-step:  14px;
            --radius-input: 14px;
            --radius-info:  14px;
            --radius-btn:   14px;
            --radius-pill:  9999px;

            --shadow-card: 0 10px 15px rgba(0,0,0,.10), 0 4px 6px rgba(0,0,0,.10);

            --font-xs:   12px;
            --font-sm:   14px;
            --font-base: 16px;
            --font-md:   18px;
            --font-lg:   24px;
            --font-xl:   36px;

            --lh-xs:   16px;
            --lh-sm:   20px;
            --lh-base: 24px;
            --lh-md:   28px;
            --lh-lg:   32px;
            --lh-xl:   40px;
        }

        /* =============================================
           RESET & BASE
        ============================================= */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gradient-bg);
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
            position: relative;
            min-height: 100vh;
            padding: 32px 0 64px;
        }

        /* =============================================
           TOP BAR
        ============================================= */
        .page__top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            min-height: 32px;
            margin-bottom: 0;
        }

        /* =============================================
           BACK LINK
        ============================================= */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-primary);
            white-space: nowrap;
        }
        .back-link__icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        /* =============================================
           HAMBURGER NAV (tablet / mobile only)
        ============================================= */
        .nav { display: none; }

        /* =============================================
           MAIN CONTAINER
        ============================================= */
        .container {
            max-width: 1024px;
            width: 100%;
            margin: 0 auto;
            padding: 60px 32px 0;
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        /* =============================================
           HERO
        ============================================= */
        .hero {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .hero__icon-wrap {
            width: 64px;
            height: 64px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .hero__icon-wrap img { width: 32px; height: 32px; }
        .hero__title {
            font-size: var(--font-xl);
            font-weight: 900;
            line-height: var(--lh-xl);
            color: var(--color-heading);
            text-align: center;
            margin-bottom: 4px;
        }
        .hero__subtitle {
            font-size: var(--font-md);
            font-weight: 500;
            line-height: var(--lh-md);
            color: var(--color-body);
            text-align: center;
        }

        /* =============================================
           PROGRESS CARD
        ============================================= */
        .progress-card {
            background: var(--color-white);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 32px 32px 24px;
        }
        .progress-card__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .progress-card__step-label {
            font-size: var(--font-sm);
            font-weight: 700;
            line-height: var(--lh-sm);
            color: var(--color-body);
        }
        .progress-card__pct-label {
            font-size: var(--font-sm);
            font-weight: 700;
            line-height: var(--lh-sm);
            color: var(--color-primary);
        }
        .progress-card__bar-track {
            height: 12px;
            background: #e5e7eb;
            border-radius: var(--radius-pill);
            overflow: hidden;
            margin-bottom: 16px;
        }
        .progress-card__bar-fill {
            height: 100%;
            width: 100%;
            border-radius: var(--radius-pill);
            background: var(--gradient-progress);
        }

        /* =============================================
           STEP TABS
        ============================================= */
        .steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px;
            border-radius: var(--radius-step);
            text-decoration: none;
        }
        .step--active   { background: var(--gradient-steps); }
        .step--inactive { background: #f3f4f6; }
        .step__icon { width: 24px; height: 24px; flex-shrink: 0; }
        .step__label {
            font-size: var(--font-xs);
            font-weight: 700;
            line-height: var(--lh-xs);
            text-align: center;
            white-space: nowrap;
        }
        .step--active   .step__label { color: var(--color-white); }
        .step--inactive .step__label { color: #99a1af; }

        /* =============================================
           FORM CARD
        ============================================= */
        .form-card {
            background: var(--color-white);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 32px;
        }

        /* =============================================
           SECTION HEADING
        ============================================= */
        .section-heading {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .section-heading__icon { width: 32px; height: 32px; flex-shrink: 0; }
        .section-heading__title {
            font-size: var(--font-lg);
            font-weight: 900;
            line-height: var(--lh-lg);
            color: var(--color-heading);
            white-space: nowrap;
        }

        /* =============================================
           FORM FIELDS
        ============================================= */
        .form-fields {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .field {}
        .field__label {
            display: block;
            font-size: var(--font-sm);
            font-weight: 700;
            line-height: var(--lh-sm);
            color: var(--color-label);
            margin-bottom: 8px;
        }
        .field__select,
        .field__textarea {
            width: 100%;
            border: 2px solid var(--color-border);
            border-radius: var(--radius-input);
            font-family: 'Inter', sans-serif;
            font-size: var(--font-base);
            font-weight: 500;
            line-height: var(--lh-base);
            color: var(--color-heading);
            background: var(--color-white);
            transition: border-color .2s, box-shadow .2s;
            outline: none;
        }
        .field__select {
            height: 51px;
            padding: 0 16px;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none'%3E%3Cpath d='M6 9l6 6 6-6' stroke='%234a5565' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            cursor: pointer;
        }
        .field__textarea {
            height: 128px;
            padding: 12px 16px;
            resize: vertical;
        }
        .field__select:focus,
        .field__textarea:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(0,48,135,.12);
        }
        .field__select--error,
        .field__textarea--error {
            border-color: var(--color-error);
        }
        .field__select option[value=""] { color: var(--color-placeholder); }

        /* Placeholder color */
        .field__textarea::placeholder {
            color: var(--color-placeholder);
            font-weight: 500;
        }

        .field__error {
            display: block;
            font-size: var(--font-xs);
            font-weight: 400;
            line-height: var(--lh-xs);
            color: var(--color-error);
            margin-top: 6px;
        }

        /* =============================================
           CHECKBOXES BLOCK
        ============================================= */
        .checkboxes {
            background: var(--color-bg-info);
            border-radius: var(--radius-info);
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .checkbox-row__input {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            margin-top: 2px;
            accent-color: var(--color-primary);
            cursor: pointer;
        }
        .checkbox-row__label {
            font-size: var(--font-sm);
            font-weight: 500;
            line-height: var(--lh-sm);
            color: var(--color-label);
            cursor: pointer;
        }
        .checkbox-row__label strong {
            font-weight: 700;
        }
        .checkbox-error {
            font-size: var(--font-xs);
            color: var(--color-error);
            margin-top: 4px;
            display: block;
        }

        /* =============================================
           AUTO-RECOMMENDATION BANNER
        ============================================= */
        .auto-rec {
            background: var(--color-bg-warn);
            border: 2px solid var(--color-border-warn);
            border-radius: var(--radius-info);
            padding: 18px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .auto-rec__icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .auto-rec__text {
            font-size: var(--font-sm);
            line-height: var(--lh-sm);
            color: var(--color-dark);
        }
        .auto-rec__text strong {
            font-weight: 700;
        }

        /* =============================================
           ACTION BUTTONS ROW
        ============================================= */
        .actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 60px;
            padding: 0 24px;
            background: var(--color-white);
            border: 2px solid var(--color-primary);
            border-radius: var(--radius-btn);
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-primary);
            text-decoration: none;
            white-space: nowrap;
            transition: background .2s;
        }
        .btn-back:hover { background: rgba(0,48,135,.05); }
        .btn-back__icon { width: 20px; height: 20px; flex-shrink: 0; }

        .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 60px;
            padding: 0 28px;
            background: var(--gradient-submit);
            border: none;
            border-radius: var(--radius-btn);
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-submit-text);
            cursor: pointer;
            white-space: nowrap;
            transition: opacity .2s;
        }
        .btn-submit:hover { opacity: .9; }
        .btn-submit__icon { width: 20px; height: 20px; flex-shrink: 0; }

        /* =============================================
           SUCCESS BANNER
        ============================================= */
        .success-banner {
            background: #dcfce7;
            border: 2px solid #22c55e;
            border-radius: var(--radius-card);
            padding: 16px 24px;
            font-size: var(--font-sm);
            font-weight: 700;
            color: #15803d;
            text-align: center;
        }

        /* =============================================
           RESPONSIVE – TABLET (≤1024px)
        ============================================= */
        @media (max-width: 1024px) {
            .page__top-bar { padding: 0 24px; }

            .nav {
                display: flex;
                align-items: center;
                position: relative;
            }
            .nav__hamburger {
                display: flex;
                flex-direction: column;
                gap: 5px;
                width: 32px;
                height: 32px;
                justify-content: center;
                align-items: center;
                padding: 0;
            }
            .nav__hamburger-bar {
                display: block;
                width: 22px;
                height: 2px;
                background: var(--color-primary);
                border-radius: 2px;
                transition: transform .3s, opacity .3s;
            }
            .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(1) { transform: translateY(7px) rotate(45deg); }
            .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(2) { opacity: 0; }
            .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

            .nav__menu {
                display: none;
                position: absolute;
                top: 40px;
                right: 0;
                background: var(--color-white);
                border-radius: var(--radius-card);
                box-shadow: var(--shadow-card);
                padding: 12px 0;
                min-width: 200px;
                z-index: 100;
            }
            .nav__menu--open { display: block; }
            .nav__item {
                display: block;
                padding: 12px 24px;
                font-size: var(--font-sm);
                font-weight: 700;
                color: var(--color-label);
                text-decoration: none;
                transition: background .2s;
            }
            .nav__item:hover { background: #f3f4f6; }
            .nav__item--active { color: var(--color-primary); }

            .container { padding: 24px 24px 0; }

            .steps { gap: 8px; }
            .step { padding: 12px 8px; }
            .step__label { font-size: 10px; }

            .hero__title { font-size: 28px; line-height: 36px; }
        }

        /* =============================================
           RESPONSIVE – MOBILE (≤768px)
        ============================================= */
        @media (max-width: 768px) {
            .page__top-bar { padding: 0 16px; }
            .container { padding: 20px 16px 0; gap: 20px; }

            .hero__title { font-size: 22px; line-height: 30px; }
            .hero__subtitle { font-size: var(--font-base); line-height: var(--lh-base); }
            .hero__icon-wrap { width: 52px; height: 52px; border-radius: 12px; }

            .progress-card { padding: 20px 16px 16px; }
            .steps { grid-template-columns: repeat(2, 1fr); }

            .form-card { padding: 20px 16px; }
            .section-heading__title { font-size: 18px; }

            .actions { gap: 12px; }
            .btn-back,
            .btn-submit {
                flex: 1;
                justify-content: center;
                height: 52px;
                font-size: var(--font-sm);
                padding: 0 16px;
            }
        }
    </style>
</head>
<body>

<main class="page">

    <!-- ── TOP BAR ── -->
    <div class="page__top-bar">
        <a href="index.php" class="back-link" aria-label="Back to Home">
            <svg class="back-link__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M15.8333 10H4.16667M4.16667 10L10 15.8333M4.16667 10L10 4.16667" stroke="#003087" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Back to Home
        </a>

        <nav class="nav" aria-label="Main navigation">
            <button class="nav__hamburger" aria-expanded="false" aria-controls="nav-menu" aria-label="Toggle navigation">
                <span class="nav__hamburger-bar"></span>
                <span class="nav__hamburger-bar"></span>
                <span class="nav__hamburger-bar"></span>
            </button>
            <ul id="nav-menu" class="nav__menu" role="list">
                <li><a href="index.php"     class="nav__item">Home</a></li>
                <li><a href="register.php"  class="nav__item">Personal Info</a></li>
                <li><a href="register1.php" class="nav__item">Academic Info</a></li>
                <li><a href="register2.php" class="nav__item">Requirements</a></li>
                <li><a href="register3.php" class="nav__item nav__item--active" aria-current="page">Assessment</a></li>
            </ul>
        </nav>
    </div>

    <div class="container">

        <?php if ($success): ?>
        <div class="success-banner" role="alert">
            ✓ Application submitted successfully! We will review your application and get back to you.
        </div>
        <?php endif; ?>

                <?php if (!empty($errors['flow'])): ?>
                    <div style="background:#fff0f0;border:2px solid #fca5a5;color:#881818;border-radius:12px;padding:12px 16px;margin-bottom:16px;" role="alert">
                        <?= htmlspecialchars($errors['flow']) ?>
                    </div>
                <?php endif; ?>

        <!-- ── HERO ── -->
        <header class="hero">
            <div class="hero__icon-wrap" aria-hidden="true">
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M16 4L2 11.2727L16 18.5455L30 11.2727L16 4Z" stroke="white" stroke-width="2" stroke-linejoin="round"/>
                    <path d="M7.27271 15.2727V22.5454C7.27271 22.5454 10.9091 26.1818 16 26.1818C21.0909 26.1818 24.7272 22.5454 24.7272 22.5454V15.2727" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M30 11.2727V18.5454" stroke="white" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="hero__title">Student Assistant Application</h1>
            <p class="hero__subtitle">Complete the 4-step process to apply</p>
        </header>

        <!-- ── PROGRESS CARD ── -->
        <section class="progress-card" aria-label="Application progress">
            <div class="progress-card__header">
                <span class="progress-card__step-label">Step 4 of 4</span>
                <span class="progress-card__pct-label">100% Complete</span>
            </div>
            <div class="progress-card__bar-track" role="progressbar" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100" aria-label="100% complete">
                <div class="progress-card__bar-fill"></div>
            </div>

            <div class="steps" role="list">
                <!-- Step 1 – Personal Info (completed) -->
                <a href="register.php" class="step step--active" role="listitem">
                    <svg class="step__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M20.59 22C20.59 18.13 16.74 15 12 15C7.26 15 3.41 18.13 3.41 22" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="step__label">Personal Info</span>
                </a>

                <!-- Step 2 – Academic Info (completed) -->
                <a href="register1.php" class="step step--active" role="listitem">
                    <svg class="step__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M4 19.5V4.5C4 3.4 4.9 2.5 6 2.5H18C19.1 2.5 20 3.4 20 4.5V19.5L12 15.5L4 19.5Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="step__label">Academic Info</span>
                </a>

                <!-- Step 3 – Requirements (completed) -->
                <a href="register2.php" class="step step--active" role="listitem">
                    <svg class="step__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M14 2V8H20" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 13H8" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 17H8" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M10 9H8" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="step__label">Requirements</span>
                </a>

                <!-- Step 4 – Assessment (current / active) -->
                <div class="step step--active" role="listitem" aria-current="step">
                    <svg class="step__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="2" stroke="white" stroke-width="2"/>
                        <path d="M9 9H15" stroke="white" stroke-width="2" stroke-linecap="round"/>
                        <path d="M9 12H15" stroke="white" stroke-width="2" stroke-linecap="round"/>
                        <path d="M9 15H12" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span class="step__label">Assessment</span>
                </div>
            </div>
        </section>

        <!-- ── FORM CARD ── -->
        <section class="form-card" aria-labelledby="form-heading">
            <div class="section-heading">
                <!-- Clipboard-check icon matching Figma imgIcon3 -->
                <svg class="section-heading__icon" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="6" y="5" width="20" height="22" rx="3" stroke="#003087" stroke-width="2"/>
                    <path d="M12 3h8a1 1 0 0 1 1 1v2H11V4a1 1 0 0 1 1-1Z" stroke="#003087" stroke-width="2"/>
                    <path d="M11 16l3 3 6-6" stroke="#003087" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <h2 class="section-heading__title" id="form-heading">Assessment &amp; Preferences</h2>
            </div>

            <form method="POST" action="" novalidate>
                <div class="form-fields">

                    <!-- Preferred Work Location -->
                    <div class="field">
                        <label class="field__label" for="work_location">Preferred Office Assignment *</label>
                        <select
    class="field__select<?= !empty($errors['work_location']) ? ' field__select--error' : '' ?>"
    id="work_location"
    name="work_location"
    aria-required="true"
>

<option value="">Select office...</option>

<?php foreach (sams_office_options() as $officeOption): ?>
<option value="<?= htmlspecialchars($officeOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($officeOption, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>

</select>
                        <?php if (!empty($errors['work_location'])): ?>
                            <span class="field__error" id="work-location-error" role="alert"><?= htmlspecialchars($errors['work_location']) ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Preferred Work Schedule removed per request -->

                    <!-- Special Skills or Talents -->
                    <div class="field">
                        <label class="field__label" for="skills">Special Skills or Talents</label>
                        <textarea
                            class="field__textarea"
                            id="skills"
                            name="skills"
                            placeholder="e.g., Event planning, graphic design, public speaking..."
                            aria-label="Special skills or talents"
                        ><?= $val_skills ?></textarea>
                    </div>

                    <!-- Checkboxes -->
                    <div class="checkboxes">
                        <div>
                            <div class="checkbox-row">
                                <input
                                    class="checkbox-row__input"
                                    type="checkbox"
                                    id="agree_terms"
                                    name="agree_terms"
                                    value="1"
                                    <?= (isset($_POST['agree_terms'])) ? 'checked' : '' ?>
                                    aria-required="true"
                                    aria-describedby="<?= !empty($errors['agree_terms']) ? 'terms-error' : '' ?>"
                                />
                                <label class="checkbox-row__label" for="agree_terms">
                                    I agree to the <strong>Terms and Conditions</strong> of the Student Assistant Program and understand my responsibilities as a student assistant.
                                </label>
                            </div>
                            <?php if (!empty($errors['agree_terms'])): ?>
                                <span class="checkbox-error" id="terms-error" role="alert"><?= htmlspecialchars($errors['agree_terms']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div class="checkbox-row">
                                <input
                                    class="checkbox-row__input"
                                    type="checkbox"
                                    id="agree_privacy"
                                    name="agree_privacy"
                                    value="1"
                                    <?= (isset($_POST['agree_privacy'])) ? 'checked' : '' ?>
                                    aria-required="true"
                                    aria-describedby="<?= !empty($errors['agree_privacy']) ? 'privacy-error' : '' ?>"
                                />
                                <label class="checkbox-row__label" for="agree_privacy">
                                    I consent to the collection and processing of my personal data in accordance with the <strong>Data Privacy Act</strong> for SDAO purposes.
                                </label>
                            </div>
                            <?php if (!empty($errors['agree_privacy'])): ?>
                                <span class="checkbox-error" id="privacy-error" role="alert"><?= htmlspecialchars($errors['agree_privacy']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Auto-recommendation banner -->
                    <div class="auto-rec" role="note" aria-label="Auto-recommendation notice">
                        <!-- Star/sparkle icon matching Figma imgIcon4 -->
                        <svg class="auto-rec__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M10 2L11.8 7.8L17.6 7.8L12.9 11.4L14.7 17.2L10 13.6L5.3 17.2L7.1 11.4L2.4 7.8L8.2 7.8L10 2Z" stroke="#ffb81c" stroke-width="1.5" stroke-linejoin="round" fill="#ffb81c"/>
                        </svg>
                        <p class="auto-rec__text">
                            <strong>Auto-recommendation:</strong> Based on your responses, you'll be recommended for roles that match your skills and availability!
                        </p>
                    </div>

                </div><!-- /form-fields -->

                <!-- ── ACTIONS ── -->
                <div class="actions" style="margin-top: 32px;">
                    <a href="register2.php" class="btn-back">
                        <svg class="btn-back__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M15.8333 10H4.16667M4.16667 10L10 15.8333M4.16667 10L10 4.16667" stroke="#003087" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Back
                    </a>

                    <button type="submit" class="btn-submit">
                        <!-- Checkmark-circle icon matching Figma imgIcon2 -->
                        <svg class="btn-submit__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="10" cy="10" r="8" stroke="#003087" stroke-width="1.67"/>
                            <path d="M6.5 10.5L8.5 12.5L13.5 7.5" stroke="#003087" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Submit Application
                    </button>
                </div>

            </form>
        </section>

    </div><!-- /container -->

</main>

<script>
(function () {
    'use strict';

    /* ── Hamburger menu toggle ── */
    var hamburger = document.querySelector('.nav__hamburger');
    var navMenu   = document.getElementById('nav-menu');

    if (hamburger && navMenu) {
        hamburger.addEventListener('click', function () {
            var expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', String(!expanded));
            navMenu.classList.toggle('nav__menu--open', !expanded);
        });

        document.addEventListener('click', function (e) {
            if (!hamburger.contains(e.target) && !navMenu.contains(e.target)) {
                hamburger.setAttribute('aria-expanded', 'false');
                navMenu.classList.remove('nav__menu--open');
            }
        });
    }

})();
</script>

</body>
</html>
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}

$admin_name = (string) ($currentUser['name'] ?? trim((string) ($currentUser['first_name'] ?? '') . ' ' . (string) ($currentUser['last_name'] ?? '')) ?: 'SAMS Admin');
$currentDateLabel = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFC Clocking Kiosk | NU SAMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <style>
        /* ============================================================
           DESIGN TOKENS & SYSTEM VARIABLES
           ============================================================ */
        :root {
            --clr-navy:             #003087;
            --clr-navy-light:       #0047ab;
            --clr-gold:             #ffb81c;
            --clr-gold-light:       #ffa500;
            --clr-dark:             #101828;
            --clr-body:             #364153;
            --clr-muted:            #6a7282;
            --clr-white:            #ffffff;
            --clr-bg:               #f8fafc;
            --clr-border:           #e2e8f0;

            /* Status colors */
            --clr-green:            #00c950;
            --clr-green-bg:         #dcfce7;
            --clr-blue:             #155dfc;
            --clr-blue-bg:          #dbeafe;
            --clr-red:              #fb2c36;
            --clr-red-bg:           #fee2e2;

            /* Gradients */
            --grad-navy:            linear-gradient(135deg, #003087 0%, #0047ab 100%);
            --grad-kiosk:           linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --grad-gold:            linear-gradient(135deg, #ffb81c 0%, #ffa500 100%);
            --grad-card:            linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);

            /* Shadows & Border Radii */
            --shadow-kiosk:         0 20px 40px rgba(0, 0, 0, 0.3);
            --shadow-glow:          0 0 30px rgba(255, 184, 28, 0.4);
            --radius-md:            16px;
            --radius-lg:            24px;
            --radius-pill:          9999px;
        }

        /* ============================================================
           BASE STYLES
           ============================================================ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--grad-kiosk);
            color: var(--clr-white);
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* ============================================================
           HEADER BAR
           ============================================================ */
        .kiosk-header {
            height: 80px;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-inline: 40px;
            flex-shrink: 0;
        }
        .kiosk-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .kiosk-logo {
            width: 42px;
            height: 42px;
            background: var(--clr-white);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 20px;
            color: var(--clr-navy);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }
        .kiosk-title {
            display: flex;
            flex-direction: column;
        }
        .kiosk-title h1 {
            font-size: 20px;
            font-weight: 900;
            line-height: 1.2;
            color: var(--clr-white);
        }
        .kiosk-title p {
            font-size: 12px;
            font-weight: 500;
            color: var(--clr-gold);
        }
        .kiosk-date {
            font-size: 16px;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.8);
        }
        .kiosk-back {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            background: rgba(255, 255, 255, 0.08);
            padding: 10px 20px;
            border-radius: var(--radius-md);
            transition: all 0.2s;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .kiosk-back:hover {
            background: rgba(255, 255, 255, 0.15);
            color: var(--clr-white);
        }

        /* ============================================================
           MAIN LAYOUT CONTROLLER
           ============================================================ */
        .kiosk-body {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            position: relative;
        }

        /* Hidden Input Box for RFID Scan */
        #nfc-scanner-input {
            position: absolute;
            opacity: 0;
            top: -100px;
            left: -100px;
            width: 1px;
            height: 1px;
        }

        /* ============================================================
           SCAN TARGET DISPLAY
           ============================================================ */
        .scan-zone {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 32px;
            transition: all 0.4s ease;
        }

        .scan-ring-outer {
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 184, 28, 0.03);
            border: 2px dashed rgba(255, 184, 28, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            animation: pulse-border 2.5s infinite ease-in-out;
        }
        
        .scan-ring-inner {
            width: 170px;
            height: 170px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0, 48, 135, 0.4) 0%, rgba(15, 23, 42, 0.8) 100%);
            border: 3px solid var(--clr-gold);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-glow);
            transition: all 0.3s;
        }

        .scan-icon {
            width: 72px;
            height: 72px;
            fill: var(--clr-white);
            animation: bounce-icon 3s infinite ease-in-out;
        }

        .scan-prompt h2 {
            font-size: 32px;
            font-weight: 900;
            letter-spacing: -0.02em;
            margin-bottom: 8px;
            background: linear-gradient(90deg, #ffffff 0%, #ffd573 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .scan-prompt p {
            font-size: 18px;
            color: rgba(255, 255, 255, 0.6);
            max-width: 420px;
            margin: 0 auto;
        }

        /* ============================================================
           TRANSACTION RESULT DISPLAY
           ============================================================ */
        .result-card {
            display: none;
            width: min(540px, 100%);
            background: var(--grad-card);
            border-radius: var(--radius-lg);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
            padding: 40px;
            color: var(--clr-dark);
            text-align: center;
            border: 4px solid var(--clr-gold);
            animation: slide-up-fade 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            position: relative;
            overflow: hidden;
        }

        .result-glow-stripe {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 8px;
        }
        .result-glow-stripe--in  { background: var(--clr-green); }
        .result-glow-stripe--out { background: var(--clr-blue); }

        .result-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: var(--grad-navy);
            color: var(--clr-white);
            font-size: 32px;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 20px rgba(0, 48, 135, 0.2);
        }

        .result-name {
            font-size: 26px;
            font-weight: 900;
            color: var(--clr-dark);
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .result-id {
            font-size: 14px;
            font-weight: 700;
            color: var(--clr-muted);
            margin-bottom: 24px;
        }

        .result-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .result-field {
            background: var(--clr-bg);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            padding: 14px;
            text-align: left;
        }

        .result-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--clr-muted);
            margin-bottom: 6px;
            display: block;
        }

        .result-value {
            font-size: 16px;
            font-weight: 800;
            color: var(--clr-dark);
        }

        .result-status-pill {
            display: inline-flex;
            align-items: center;
            height: 32px;
            padding-inline: 16px;
            border-radius: var(--radius-pill);
            font-size: 13px;
            font-weight: 900;
            text-transform: uppercase;
        }
        .result-status-pill--in  { background: var(--clr-green-bg); color: var(--clr-green-dark); }
        .result-status-pill--out { background: var(--clr-blue-bg); color: var(--clr-blue); }

        .result-msg {
            font-size: 16px;
            font-weight: 700;
            color: var(--clr-body);
            line-height: 1.4;
            margin-top: 16px;
        }

        /* Error Overlay Display */
        .error-card {
            display: none;
            width: min(480px, 100%);
            background: var(--clr-white);
            border-radius: var(--radius-lg);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
            padding: 32px;
            color: var(--clr-dark);
            text-align: center;
            border: 4px solid var(--clr-red);
            animation: slide-up-fade 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .error-icon-wrap {
            width: 64px;
            height: 64px;
            background: var(--clr-red-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .error-icon-wrap svg {
            width: 32px;
            height: 32px;
            color: var(--clr-red);
        }
        .error-title {
            font-size: 22px;
            font-weight: 900;
            color: var(--clr-dark);
            margin-bottom: 8px;
        }
        .error-desc {
            font-size: 15px;
            color: var(--clr-body);
            line-height: 1.5;
        }

        /* ============================================================
           ANIMATION KEYFRAMES
           ============================================================ */
        @keyframes pulse-border {
            0% { transform: scale(1); border-color: rgba(255, 184, 28, 0.3); background: rgba(255, 184, 28, 0.03); }
            50% { transform: scale(1.04); border-color: rgba(255, 184, 28, 0.7); background: rgba(255, 184, 28, 0.08); }
            100% { transform: scale(1); border-color: rgba(255, 184, 28, 0.3); background: rgba(255, 184, 28, 0.03); }
        }
        @keyframes bounce-icon {
            0% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
            100% { transform: translateY(0); }
        }
        @keyframes slide-up-fade {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Focus Status Dot */
        .focus-indicator {
            position: absolute;
            bottom: 24px;
            right: 40px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            color: rgba(255,255,255,0.4);
            background: rgba(0,0,0,0.2);
            padding: 6px 12px;
            border-radius: var(--radius-pill);
        }
        .focus-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--clr-red);
            transition: background 0.3s;
        }
        .focus-dot--active {
            background: var(--clr-green);
            box-shadow: 0 0 8px var(--clr-green);
        }
    </style>
</head>
<body>

    <!-- Header bar -->
    <header class="kiosk-header">
        <div class="kiosk-brand">
            <div class="kiosk-logo" aria-hidden="true">NU</div>
            <div class="kiosk-title">
                <h1>SAMS Kiosk</h1>
                <p>NFC/RFID Attendance Station</p>
            </div>
        </div>
        <div class="kiosk-date"><?php echo htmlspecialchars($currentDateLabel, ENT_QUOTES, 'UTF-8'); ?></div>
        <a href="attendance.php" class="kiosk-back">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px;"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
            Back to Dashboard
        </a>
    </header>

    <!-- Kiosk Content Area -->
    <main class="kiosk-body">

        <!-- Keyboard input reader target (hidden focus) -->
        <input type="text" id="nfc-scanner-input" autocomplete="off" placeholder="Scan..." />

        <!-- 1. Default State: Ready to Scan -->
        <div class="scan-zone" id="scan-zone-view">
            <div class="scan-ring-outer">
                <div class="scan-ring-inner">
                    <!-- NFC Wireless Wave Graphic -->
                    <svg class="scan-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 18h.01M17 13.5a7.07 7.07 0 00-10 0M19.5 11a10.6 10.6 0 00-15 0M22 8.5a14.14 14.14 0 00-20 0" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            <div class="scan-prompt">
                <h2>Tap School ID Card</h2>
                <p>Place your ID card on the NFC reader to Clock In or Clock Out.</p>
            </div>
        </div>

        <!-- 2. Transaction Success State -->
        <div class="result-card" id="result-card-view">
            <div class="result-glow-stripe" id="result-stripe"></div>
            <div class="result-avatar" id="result-initials">SA</div>
            <h2 class="result-name" id="result-name">Student Assistant</h2>
            <p class="result-id" id="result-id">2021-12345</p>

            <div class="result-grid">
                <div class="result-field">
                    <span class="result-label">Action</span>
                    <span class="result-status-pill" id="result-action">Clocked In</span>
                </div>
                <div class="result-field">
                    <span class="result-label">Office</span>
                    <span class="result-value" id="result-office">SDAO</span>
                </div>
                <div class="result-field">
                    <span class="result-label">Time Log</span>
                    <span class="result-value" id="result-time">08:00 AM</span>
                </div>
                <div class="result-field">
                    <span class="result-label">Status</span>
                    <span class="result-value" id="result-status">On Time</span>
                </div>
            </div>

            <p class="result-msg" id="result-msg">Checked IN successfully.</p>
        </div>

        <!-- 3. Error State -->
        <div class="error-card" id="error-card-view">
            <div class="error-icon-wrap">
                <!-- Exclamation Alert Graphic -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <h2 class="error-title">Unable to Log</h2>
            <p class="error-desc" id="error-desc-msg">NFC Card not registered or invalid request.</p>
        </div>

        <!-- Reader Focus Status -->
        <div class="focus-indicator" id="focus-panel">
            <span class="focus-dot" id="focus-dot"></span>
            <span id="focus-text">Reader Offline</span>
        </div>

    </main>

    <script>
        (function () {
            'use strict';

            var scannerInput = document.getElementById('nfc-scanner-input');
            var scanZoneView = document.getElementById('scan-zone-view');
            var resultCardView = document.getElementById('result-card-view');
            var errorCardView = document.getElementById('error-card-view');
            
            var focusDot = document.getElementById('focus-dot');
            var focusText = document.getElementById('focus-text');
            var viewTimeout = null;

            // Audio Context synthesis for chimes
            var audioCtx = null;
            function initAudio() {
                if (!audioCtx) {
                    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
            }

            function playSuccessChime() {
                try {
                    initAudio();
                    if (!audioCtx) return;
                    
                    var osc = audioCtx.createOscillator();
                    var gainNode = audioCtx.createGain();
                    osc.connect(gainNode);
                    gainNode.connect(audioCtx.destination);
                    
                    // Pleasant E5 -> A5 chime
                    var now = audioCtx.currentTime;
                    osc.type = 'triangle';
                    osc.frequency.setValueAtTime(659.25, now); // E5
                    gainNode.gain.setValueAtTime(0.12, now);
                    gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.12);
                    osc.start(now);
                    osc.stop(now + 0.12);
                    
                    setTimeout(function() {
                        var osc2 = audioCtx.createOscillator();
                        var gain2 = audioCtx.createGain();
                        osc2.connect(gain2);
                        gain2.connect(audioCtx.destination);
                        osc2.type = 'triangle';
                        osc2.frequency.setValueAtTime(880.00, audioCtx.currentTime); // A5
                        gain2.gain.setValueAtTime(0.12, audioCtx.currentTime);
                        gain2.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.25);
                        osc2.start(audioCtx.currentTime);
                        osc2.stop(audioCtx.currentTime + 0.25);
                    }, 80);
                } catch(e) {}
            }

            function playErrorChime() {
                try {
                    initAudio();
                    if (!audioCtx) return;
                    
                    var osc = audioCtx.createOscillator();
                    var gainNode = audioCtx.createGain();
                    osc.connect(gainNode);
                    gainNode.connect(audioCtx.destination);
                    
                    // Lower warning buzzer tone
                    var now = audioCtx.currentTime;
                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(140.00, now);
                    gainNode.gain.setValueAtTime(0.15, now);
                    gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.35);
                    osc.start(now);
                    osc.stop(now + 0.35);
                } catch(e) {}
            }

            // Keep input focused
            function maintainFocus() {
                if (scannerInput) {
                    scannerInput.focus();
                }
            }

            // Handle focus state visual indicator
            function updateFocusState() {
                if (document.activeElement === scannerInput) {
                    focusDot.className = 'focus-dot focus-dot--active';
                    focusText.textContent = 'Reader Connected';
                } else {
                    focusDot.className = 'focus-dot';
                    focusText.textContent = 'Reader Offline - Click screen to reconnect';
                }
            }

            // Click anywhere to reconnect / focus
            document.addEventListener('click', function () {
                maintainFocus();
                updateFocusState();
            });

            // Monitor focus events
            scannerInput.addEventListener('focus', updateFocusState);
            scannerInput.addEventListener('blur', function () {
                updateFocusState();
                // Regain focus after a short delay
                setTimeout(maintainFocus, 150);
            });

            // Capture RFID keyboard emulator scans
            scannerInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var rawUid = scannerInput.value.trim();
                    scannerInput.value = '';
                    if (rawUid !== '') {
                        processCardScan(rawUid);
                    }
                }
            });

            // Process scan via AJAX
            function processCardScan(nfcUid) {
                // Clear any running return-to-default timeouts
                if (viewTimeout) {
                    clearTimeout(viewTimeout);
                    viewTimeout = null;
                }

                var formData = new FormData();
                formData.append('nfc_uid', nfcUid);

                fetch('nfc_clock_process.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP status ' + res.status);
                    return res.json();
                })
                .then(function (data) {
                    if (data.success) {
                        playSuccessChime();
                        showSuccessCard(data);
                    } else {
                        playErrorChime();
                        showErrorCard(data.message || 'Error occurred during processing.');
                    }
                })
                .catch(function (err) {
                    playErrorChime();
                    showErrorCard('A network or server error occurred. Please try again.');
                    console.error('Kiosk scan error:', err);
                });
            }

            function showSuccessCard(d) {
                // Hide default and error views
                scanZoneView.style.display = 'none';
                errorCardView.style.display = 'none';

                // Map data to DOM
                document.getElementById('result-name').textContent = d.student_name || 'Student';
                document.getElementById('result-id').textContent = d.student_code || '-';
                document.getElementById('result-office').textContent = d.office || 'Unassigned';
                document.getElementById('result-time').textContent = d.time || '-';
                document.getElementById('result-status').textContent = d.status || '-';
                document.getElementById('result-msg').textContent = d.message || '';

                var actionBadge = document.getElementById('result-action');
                var stripe = document.getElementById('result-stripe');
                var initials = document.getElementById('result-initials');

                // Determine initials
                var nameParts = (d.student_name || '').split(' ');
                var initText = 'SA';
                if (nameParts.length >= 2) {
                    initText = (nameParts[0][0] || '') + (nameParts[nameParts.length - 1][0] || '');
                } else if (nameParts.length === 1 && nameParts[0]) {
                    initText = nameParts[0].substring(0, 2);
                }
                initials.textContent = initText.toUpperCase();

                // Style based on In/Out
                if (d.action === 'in') {
                    actionBadge.textContent = 'Clocked In';
                    actionBadge.className = 'result-status-pill result-status-pill--in';
                    stripe.className = 'result-glow-stripe result-glow-stripe--in';
                } else {
                    actionBadge.textContent = 'Clocked Out';
                    actionBadge.className = 'result-status-pill result-status-pill--out';
                    stripe.className = 'result-glow-stripe result-glow-stripe--out';
                }

                // Render result card
                resultCardView.style.display = 'block';

                // Automatically return to scanner mode after 6 seconds
                viewTimeout = setTimeout(resetKioskView, 6000);
            }

            function showErrorCard(errorMessage) {
                // Hide default and success views
                scanZoneView.style.display = 'none';
                resultCardView.style.display = 'none';

                // Map message
                document.getElementById('error-desc-msg').textContent = errorMessage;

                // Show error card
                errorCardView.style.display = 'block';

                // Return to scanner mode after 6 seconds
                viewTimeout = setTimeout(resetKioskView, 6000);
            }

            function resetKioskView() {
                resultCardView.style.display = 'none';
                errorCardView.style.display = 'none';
                scanZoneView.style.display = 'flex';
                maintainFocus();
            }

            // Initial Focus
            maintainFocus();
            updateFocusState();

        })();
    </script>
</body>
</html>

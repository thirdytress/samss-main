<!DOCTYPE html>
<html>
<head>
    <title>Fingerprint Enrollment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; background: #f7fafd; margin: 0; padding: 0; }
        .container { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px #0001; padding: 32px 32px 24px 32px; }
        h1 { font-size: 2rem; margin-bottom: 0.5em; }
        .desc { color: #666; margin-bottom: 1.5em; }
        .alert { background: #eaf4ff; color: #2563eb; border-radius: 6px; padding: 10px 16px; margin-bottom: 1.5em; font-size: 1rem; display: flex; align-items: center; }
        .alert i { margin-right: 8px; }
        .fingers { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 1.5em; }
        .finger-btn { flex: 1 1 40%; min-width: 140px; background: #f3f6fa; border: 2px solid #e0e7ef; border-radius: 10px; padding: 18px 10px; text-align: center; cursor: pointer; transition: border 0.2s, background 0.2s; font-size: 1.1rem; }
        .finger-btn:hover, .finger-btn.active { border: 2px solid #2563eb; background: #eaf4ff; }
        .finger-btn .fa-solid { font-size: 2rem; margin-bottom: 6px; color: #2563eb; }
        .status { margin: 1em 0 0.5em 0; font-weight: bold; min-height: 1.5em; }
        .success { color: #2b8a3e; }
        .error { color: #d9534f; }
        .back-link { display: block; margin-top: 2em; text-align: center; color: #2563eb; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
    <script src="../assets/js/fingerprint-scanner.js"></script>
    <script src="../assets/js/fingerprint-bridge-client.js"></script>
</head>
<body>
    <div class="container">
        <h1><i class="fa-solid fa-lock"></i> Fingerprint Enrollment</h1>
        <div class="desc">Enroll your fingerprints for secure attendance tracking</div>
        <div class="alert"><i class="fa-solid fa-info-circle"></i> Please enroll at least 2 fingerprints to enable fingerprint-based attendance</div>
        <div id="bridge-status" style="margin-bottom: 1em; color: #666;">Bridge: <span id="bridge-state">Connecting…</span></div>
        <div><b>🖐️ Enroll New Fingerprints</b></div>
        <div style="color:#666; font-size:0.98em; margin-bottom:0.7em;">Click on a finger position below to enroll it. You'll be prompted to place your finger on the scanner.</div>
        <div class="fingers" id="fingers-list"></div>
        <div id="enroll-status" class="status"></div>
        <a href="dashboard.php" class="back-link">&mdash; Back to Profile</a>
    </div>
    <script>
    // List of fingers (can be fetched from API if needed)
    const FINGERS = [
        { key: 'right_thumb', label: 'Right Thumb', icon: 'fa-thumbs-up' },
        { key: 'right_index', label: 'Right Index', icon: 'fa-hand-point-up' },
        { key: 'right_middle', label: 'Right Middle', icon: 'fa-hand-point-up' },
        { key: 'left_thumb', label: 'Left Thumb', icon: 'fa-thumbs-up' },
        { key: 'left_index', label: 'Left Index', icon: 'fa-hand-point-up' },
        { key: 'left_middle', label: 'Left Middle', icon: 'fa-hand-point-up' }
    ];
    const fingersList = document.getElementById('fingers-list');
    const enrollStatus = document.getElementById('enroll-status');
    let activeFinger = null;
    FINGERS.forEach(finger => {
        const btn = document.createElement('div');
        btn.className = 'finger-btn';
        btn.innerHTML = `<div><i class="fa-solid ${finger.icon}"></i></div><div><b>${finger.label}</b></div><div style="font-size:0.95em;color:#888;">Click to enroll</div>`;
        btn.onclick = async function() {
            if (activeFinger) return; // Prevent double scan
            activeFinger = finger.key;
            document.querySelectorAll('.finger-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            enrollStatus.textContent = `Scanning ${finger.label}... Place your finger on the scanner.`;
            enrollStatus.className = 'status';
            try {
                if (!window.FingerprintScanner || !window.FingerprintScanner.enroll) {
                    enrollStatus.textContent = 'Fingerprint scanner not available.';
                    enrollStatus.className = 'status error';
                    btn.classList.remove('active');
                    activeFinger = null;
                    return;
                }
                const result = await window.FingerprintScanner.enroll();
                if (!result.success) {
                    // Provide a more helpful message if the bridge is returning mock data
                    const msg = result.message || 'Unknown error';
                    enrollStatus.textContent = 'Scanner error: ' + msg + (msg && msg.toLowerCase().includes('mock') ? ' — please install/run the capture helper and ensure the SDK is present.' : '');
                    enrollStatus.className = 'status error';
                    btn.classList.remove('active');
                    activeFinger = null;
                    return;
                }
                enrollStatus.textContent = 'Fingerprint captured. Saving...';
                const resp = await fetch('../api/fingerprint/enroll.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ template: result.template, finger: finger.key })
                });
                const data = await resp.json();
                if (data.status === 'ok') {
                    enrollStatus.textContent = `Enrolled ${finger.label} successfully!`;
                    enrollStatus.className = 'status success';
                } else {
                    enrollStatus.textContent = data.message || 'Error.';
                    enrollStatus.className = 'status error';
                }
            } catch (e) {
                enrollStatus.textContent = 'Error: ' + e.message;
                enrollStatus.className = 'status error';
            }
            btn.classList.remove('active');
            activeFinger = null;
        };
        fingersList.appendChild(btn);
    });
    // Bridge status
    (function() {
        const bridgeState = document.getElementById('bridge-state');
        if (!window.FingerprintBridgeClient) {
            bridgeState.textContent = 'Not available (client script missing)';
            bridgeState.style.color = '#d9534f';
            return;
        }
        bridgeState.textContent = 'Connecting…';
        FingerprintBridgeClient.connect()
            .then(() => {
                bridgeState.textContent = 'Connected';
                bridgeState.style.color = '#2b8a3e';
            })
            .catch((err) => {
                bridgeState.textContent = 'Not connected';
                bridgeState.style.color = '#d9534f';
                console.warn('Bridge connect failed', err);
            });
    })();
    </script>
</body>
</html>

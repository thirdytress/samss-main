// Minimal Node.js WebSocket bridge for fingerprint scanner
const WebSocket = require('ws');
const { spawn } = require('child_process');
const path = require('path');

const wss = new WebSocket.Server({ port: 5555 });

function sendMock(ws) {
	ws.send(JSON.stringify({
		success: true,
		template: Buffer.from('mock_fingerprint_template').toString('base64'),
		message: 'Mock fingerprint from bridge.',
		sdk_present: false,
		mock: true
	}));
}

function runCaptureHelper(callback) {
	// Run `dotnet run --project capture-helper` from this directory
	const projectPath = path.join(__dirname, 'capture-helper');
	const proc = spawn('dotnet', ['run', '--project', projectPath, '--no-build'], { cwd: __dirname });

	let out = '';
	let err = '';
	proc.stdout.on('data', (d) => out += d.toString());
	proc.stderr.on('data', (d) => err += d.toString());

	proc.on('close', (code) => {
		if (code === 0 && out) {
			try {
				const parsed = JSON.parse(out.trim());
				callback(null, parsed);
				return;
			} catch (e) {
				callback(new Error('Invalid JSON from capture helper: ' + e.message + ' -- ' + out));
				return;
			}
		}
		callback(new Error('Capture helper failed: ' + err));
	});
}

wss.on('connection', function connection(ws) {
	console.log('[Bridge] Client connected');
	ws.on('message', function incoming(message) {
		console.log('[Bridge] Received:', message.toString());
		let data = {};
		try {
			data = JSON.parse(message.toString());
		} catch (e) {
			// ignore, treat as simple capture request
		}

		if (data.action === 'capture') {
			// Try to run native capture helper, else fallback to mock
			runCaptureHelper((err, result) => {
				if (err) {
					console.warn('[Bridge] Capture helper error, falling back to mock:', err.message);
					sendMock(ws);
					return;
				}
				ws.send(JSON.stringify(result));
			});
			return;
		}

		// Default reply: mock
		sendMock(ws);
	});
	ws.on('close', function() {
		console.log('[Bridge] Client disconnected');
	});
});

console.log('[Bridge] WebSocket server running on ws://localhost:5555');

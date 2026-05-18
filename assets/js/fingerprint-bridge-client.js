// Minimal WebSocket client for fingerprint bridge
window.FingerprintBridgeClient = (function() {
	let ws = null;
	let isConnected = false;
	let connectPromise = null;

	function connect() {
		if (ws && isConnected) return Promise.resolve(ws);
		if (connectPromise) return connectPromise;
		connectPromise = new Promise((resolve, reject) => {
			ws = new WebSocket('ws://localhost:5555');
			ws.onopen = function() {
				isConnected = true;
				console.log('[Bridge] Connected');
				resolve(ws);
			};
			ws.onerror = function(e) {
				isConnected = false;
				console.error('[Bridge] Connection error', e);
				reject(e);
			};
			ws.onclose = function() {
				isConnected = false;
				console.warn('[Bridge] Disconnected');
			};
		});
		return connectPromise;
	}

	function send(data) {
		return connect().then(ws => {
			return new Promise((resolve, reject) => {
				ws.onmessage = function(msg) {
					try {
						resolve(JSON.parse(msg.data));
					} catch (e) {
						resolve(msg.data);
					}
				};
				ws.send(JSON.stringify(data));
			});
		});
	}

	return {
		connect,
		send,
		isConnected: () => isConnected
	};
})();
console.log('Fingerprint bridge client script loaded.');

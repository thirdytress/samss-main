// Mock fingerprint scanner logic
window.FingerprintScanner = {
	enroll: function() {
		// Prefer the bridge when available
		if (window.FingerprintBridgeClient && FingerprintBridgeClient.isConnected()) {
			return FingerprintBridgeClient.send({ action: 'capture' })
				.then(function(resp) {
					// Reject mock/fallback captures to avoid accidental enrollments when helper/SDK isn't present
					if (resp && (resp.mock === true || resp.sdk_present === false)) {
						return { success: false, message: 'Bridge returned a mock capture. Ensure the capture helper and SDK are installed.' };
					}
					// Expect resp.template to be base64
					return {
						success: !!resp.success,
						template: resp.template || null,
						message: resp.message || 'Captured from bridge.'
					};
				})
				.catch(function(err) {
					return { success: false, message: 'Bridge error: ' + err };
				});
		}

		// Fallback: simulate a fingerprint template as a base64 string
		return Promise.resolve({
			success: true,
			template: btoa('mock_fingerprint_template'),
			message: 'Mock fingerprint captured.'
		});
	}
};
console.log('Fingerprint scanner script loaded.');

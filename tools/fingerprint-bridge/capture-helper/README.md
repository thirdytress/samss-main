Integration notes for DigitalPersona SDK

This capture helper is intentionally a minimal mock. It currently returns a base64-encoded mock template and will report whether the DigitalPersona SDK (DPFP.dll) is present on the system.

Goal
- Replace the mock output with real fingerprint capture using the DigitalPersona U.are.U SDK for .NET.

Steps to integrate the SDK (safe, non-breaking):

1) Install the DigitalPersona SDK on the bridge machine (Windows). This typically places `DPFP.dll` under `C:\Program Files\DigitalPersona\Bin`.

2) Test that `dotnet run --project capture-helper` prints JSON. The helper currently prints a JSON object with `sdk_present` indicating whether `DPFP.dll` was detected.

3) Integrate the SDK into `Program.cs`:
   - In Visual Studio, add a reference to `DPFP.dll` (or add the SDK nuget if available).
   - Replace the mock section in `Program.cs` with real capture code. Example outline below.

Example capture code (replace the mock block):

```csharp
// Example using DPFP .NET API
// Note: you must add a reference to DPFP.dll and `using DPFP; using DPFP.Capture;` at the top.

var capturedTemplateBase64 = null;
var capture = new DPFP.Capture.Capture();
var tcs = new TaskCompletionSource<string>();

capture.EventHandler = new SampleCaptureEventHandler((templateBytes) => {
    // Convert template bytes to base64 and complete
    tcs.SetResult(Convert.ToBase64String(templateBytes));
});

capture.StartCapture();

// Wait for a single capture or timeout
var task = tcs.Task;
if (task.Wait(TimeSpan.FromSeconds(20))) {
    capturedTemplateBase64 = task.Result;
}
capture.StopCapture();

if (!string.IsNullOrEmpty(capturedTemplateBase64)) {
    var result = new { success = true, template = capturedTemplateBase64, message = "Captured from SDK" };
    Console.WriteLine(JsonSerializer.Serialize(result));
} else {
    var result = new { success = false, message = "Capture timed out or failed" };
    Console.WriteLine(JsonSerializer.Serialize(result));
}
```

You will need to implement `SampleCaptureEventHandler` to convert the DPFP template to raw bytes. A typical DPFP workflow:
- Create `DPFP.Capture.Capture` instance
- Handle `OnComplete` or `OnComplete` event to receive a `Sample` object
- Use `DPFP.Processing.Enrollment` or `DPFP.Template` to extract a template byte[]

4) Rebuild and run the bridge server. The Node bridge will call the helper and return the JSON output to the browser.

Notes
- Keep the helper returning JSON on stdout, one JSON object per run — the Node bridge parses stdout as JSON.
- Keep the fallback mock in place until you verify the SDK capture works.
- If you want, I can implement a full SDK example once you confirm the SDK is installed and tell me which DPFP assemblies are available (DLL names and version).

Safety: The Node bridge falls back to the mock template if the helper fails, so the web app remains functional while you integrate the SDK.

using System;
using System.Text;
using System.Text.Json;
using System.IO;
using DPUruNet;

// Only check for DPUruNet.dll in the current directory (official SDK only)
bool sdkFound = File.Exists(Path.Combine(Directory.GetCurrentDirectory(), "DPUruNet.dll"));
string detectedPath = sdkFound ? Path.Combine(Directory.GetCurrentDirectory(), "DPUruNet.dll") : null;

if (sdkFound)
{
    try
    {
        ReaderCollection readers = ReaderCollection.GetReaders();
        if (readers.Count == 0)
        {
            var result = new
            {
                success = false,
                message = "No fingerprint readers found.",
                sdk_present = true,
                sdk_detected_path = detectedPath
            };
            var json = JsonSerializer.Serialize(result);
            Console.WriteLine(json);
            return;
        }

        Reader reader = readers[0];
        Constants.ResultCode rc = reader.Open(Constants.CapturePriority.DP_PRIORITY_COOPERATIVE);
        if (rc != Constants.ResultCode.DP_SUCCESS)
        {
            var result = new
            {
                success = false,
                message = "Failed to open reader: " + rc.ToString(),
                sdk_present = true,
                sdk_detected_path = detectedPath
            };
            var json = JsonSerializer.Serialize(result);
            Console.WriteLine(json);
            return;
        }

        Fmd fmd = null;
        int timeout = 10000; // 10 seconds
        var captureResult = reader.GetFmd(Fmd.Format.DP_VERIFICATION, timeout);
        if (captureResult.ResultCode == Constants.ResultCode.DP_SUCCESS && captureResult.Data != null)
        {
            fmd = captureResult.Data;
            var templateBytes = fmd.Bytes;
            var result = new
            {
                success = true,
                template = Convert.ToBase64String(templateBytes),
                message = "Fingerprint captured successfully.",
                sdk_present = true,
                sdk_detected_path = detectedPath
            };
            var json = JsonSerializer.Serialize(result);
            Console.WriteLine(json);
            reader.Dispose();
            return;
        }
        else
        {
            var result = new
            {
                success = false,
                message = "Failed to capture fingerprint. " + captureResult.ResultCode.ToString(),
                sdk_present = true,
                sdk_detected_path = detectedPath
            };
            var json = JsonSerializer.Serialize(result);
            Console.WriteLine(json);
            reader.Dispose();
            return;
        }
    }
    catch (Exception ex)
    {
        var result = new
        {
            success = false,
            message = "Exception: " + ex.Message,
            sdk_present = true,
            sdk_detected_path = detectedPath
        };
        var json = JsonSerializer.Serialize(result);
        Console.WriteLine(json);
        return;
    }
}
else
{
    // Fallback: mock
    var templateBytes = Encoding.UTF8.GetBytes("mock_fingerprint_template_from_csharp");
    var result = new
    {
        success = true,
        template = Convert.ToBase64String(templateBytes),
        message = "Mock fingerprint from C# helper",
        sdk_present = false,
        sdk_detected_path = detectedPath
    };
    var json = JsonSerializer.Serialize(result);
    Console.WriteLine(json);
}
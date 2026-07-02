using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;
using libzkfpcsharp;
using Sample;

namespace ZktecoLocalAgent.Sdk;

public sealed class ZkFingerprintSdk : IZkFingerprintSdk
{
    private const int CaptureTemplateBufferSize = 2048;
    private readonly ILogger<ZkFingerprintSdk> logger;
    private readonly Dictionary<int, LocalFingerprintTemplate> loadedTemplates = [];
    private readonly object sync = new();

    private IntPtr deviceHandle = IntPtr.Zero;
    private IntPtr dbHandle = IntPtr.Zero;
    private byte[]? fingerprintBuffer;
    private byte[]? lastFingerprintBuffer;
    private int fingerprintWidth;
    private int fingerprintHeight;
    private Thread? captureThread;
    private volatile bool stopCapture = true;

    public ZkFingerprintSdk(ILogger<ZkFingerprintSdk> logger)
    {
        this.logger = logger;
    }

    public event EventHandler<FingerprintCapturedEventArgs>? FingerprintCaptured;
    public bool IsInitialized { get; private set; }
    public bool ScannerAvailable { get; private set; }
    public string? MissingDependency { get; private set; }
    public string? LastError { get; private set; }

    public void Initialize()
    {
        try
        {
            if (!File.Exists(Path.Combine(AppContext.BaseDirectory, "libzkfpcsharp.dll")))
            {
                MissingDependency = "libzkfpcsharp.dll";
                LastError = "ZKTeco SDK DLL is missing from the agent folder.";
                logger.LogError("{Message}", LastError);
                return;
            }

            var ret = zkfp2.Init();
            if (ret != zkfperrdef.ZKFP_ERR_OK)
            {
                LastError = $"ZKTeco SDK initialization failed, code {ret}.";
                logger.LogError("{Message}", LastError);
                return;
            }

            var deviceCount = zkfp2.GetDeviceCount();
            if (deviceCount <= 0)
            {
                zkfp2.Terminate();
                LastError = "No ZKTeco fingerprint scanner is connected.";
                logger.LogError("{Message}", LastError);
                return;
            }

            deviceHandle = zkfp2.OpenDevice(0);
            if (deviceHandle == IntPtr.Zero)
            {
                LastError = "ZKTeco scanner OpenDevice failed.";
                logger.LogError("{Message}", LastError);
                return;
            }

            dbHandle = zkfp2.DBInit();
            if (dbHandle == IntPtr.Zero)
            {
                LastError = "ZKTeco matcher DBInit failed.";
                logger.LogError("{Message}", LastError);
                return;
            }

            var paramValue = new byte[4];
            var size = 4;
            zkfp2.GetParameters(deviceHandle, 1, paramValue, ref size);
            zkfp2.ByteArray2Int(paramValue, ref fingerprintWidth);

            size = 4;
            zkfp2.GetParameters(deviceHandle, 2, paramValue, ref size);
            zkfp2.ByteArray2Int(paramValue, ref fingerprintHeight);

            fingerprintBuffer = new byte[fingerprintWidth * fingerprintHeight];
            stopCapture = false;
            captureThread = new Thread(CaptureLoop) { IsBackground = true, Name = "ZKTeco capture" };
            captureThread.Start();

            IsInitialized = true;
            ScannerAvailable = true;
            LastError = null;
            logger.LogInformation("ZKTeco SDK initialized. Scanner image size {Width}x{Height}.", fingerprintWidth, fingerprintHeight);
        }
        catch (DllNotFoundException ex)
        {
            MissingDependency = ex.Message;
            LastError = "A ZKTeco SDK DLL or native dependency is missing.";
            logger.LogError(ex, "{Message}", LastError);
        }
        catch (Exception ex)
        {
            LastError = ex.Message;
            logger.LogError(ex, "ZKTeco scanner initialization failed.");
        }
    }

    public void ReloadMatcher(IReadOnlyList<LocalFingerprintTemplate> templates)
    {
        if (dbHandle == IntPtr.Zero)
        {
            return;
        }

        lock (sync)
        {
            loadedTemplates.Clear();
            zkfp2.DBClear(dbHandle);

            foreach (var template in templates)
            {
                var ret = zkfp2.DBAdd(dbHandle, template.ServerTemplateId, template.TemplateBytes);
                if (ret == zkfp.ZKFP_ERR_OK)
                {
                    loadedTemplates[template.ServerTemplateId] = template;
                }
                else
                {
                    logger.LogWarning("Failed to load template {TemplateId} into SDK matcher. Code {Code}.", template.ServerTemplateId, ret);
                }
            }
        }

        logger.LogInformation("Loaded {TemplateCount} fingerprint template(s) into SDK matcher.", loadedTemplates.Count);
    }

    public TemplateMatch? Identify(byte[] capturedTemplate)
    {
        if (dbHandle == IntPtr.Zero)
        {
            return null;
        }

        lock (sync)
        {
            var fid = 0;
            var score = 0;
            var ret = zkfp2.DBIdentify(dbHandle, capturedTemplate, ref fid, ref score);
            if (ret == zkfp.ZKFP_ERR_OK && loadedTemplates.TryGetValue(fid, out var identified))
            {
                return new TemplateMatch(identified, score);
            }

            TemplateMatch? bestMatch = null;
            foreach (var template in loadedTemplates.Values)
            {
                var matchScore = zkfp2.DBMatch(dbHandle, capturedTemplate, template.TemplateBytes);
                if (bestMatch is null || matchScore > bestMatch.Score)
                {
                    bestMatch = new TemplateMatch(template, matchScore);
                }
            }

            return bestMatch is { Score: > 0 } ? bestMatch : null;
        }
    }

    public int Match(byte[] leftTemplate, byte[] rightTemplate)
    {
        return dbHandle == IntPtr.Zero ? 0 : zkfp2.DBMatch(dbHandle, leftTemplate, rightTemplate);
    }

    public byte[] MergeEnrollmentTemplates(byte[] first, byte[] second, byte[] third)
    {
        if (dbHandle == IntPtr.Zero)
        {
            throw new InvalidOperationException("ZKTeco matcher is not initialized.");
        }

        var output = new byte[CaptureTemplateBufferSize];
        var outputSize = CaptureTemplateBufferSize;
        var ret = zkfp2.DBMerge(dbHandle, first, second, third, output, ref outputSize);
        if (ret != zkfp.ZKFP_ERR_OK)
        {
            throw new InvalidOperationException($"ZKTeco enrollment merge failed, code {ret}.");
        }

        return output.Take(outputSize).ToArray();
    }

    public string? LastFingerprintImageBase64()
    {
        if (lastFingerprintBuffer is null || fingerprintWidth <= 0 || fingerprintHeight <= 0)
        {
            return null;
        }

        var bitmapStream = new MemoryStream();
        BitmapFormat.GetBitmap(lastFingerprintBuffer, fingerprintWidth, fingerprintHeight, ref bitmapStream);
        bitmapStream.Position = 0;
        using var bitmap = new Bitmap(bitmapStream);
        using (bitmapStream)
        {
            using var pngStream = new MemoryStream();
            bitmap.Save(pngStream, ImageFormat.Png);
            return Convert.ToBase64String(pngStream.ToArray());
        }
    }

    public void Dispose()
    {
        stopCapture = true;
        captureThread?.Join(1000);

        if (deviceHandle != IntPtr.Zero)
        {
            zkfp2.CloseDevice(deviceHandle);
            deviceHandle = IntPtr.Zero;
        }

        if (dbHandle != IntPtr.Zero)
        {
            zkfp2.DBFree(dbHandle);
            dbHandle = IntPtr.Zero;
        }

        if (IsInitialized)
        {
            zkfp2.Terminate();
        }
    }

    private void CaptureLoop()
    {
        while (!stopCapture)
        {
            try
            {
                if (deviceHandle == IntPtr.Zero || fingerprintBuffer is null)
                {
                    Thread.Sleep(250);
                    continue;
                }

                var capturedTemplate = new byte[CaptureTemplateBufferSize];
                var capturedTemplateSize = CaptureTemplateBufferSize;
                var ret = zkfp2.AcquireFingerprint(deviceHandle, fingerprintBuffer, capturedTemplate, ref capturedTemplateSize);
                if (ret == zkfp.ZKFP_ERR_OK)
                {
                    lastFingerprintBuffer = fingerprintBuffer.ToArray();
                    FingerprintCaptured?.Invoke(
                        this,
                        new FingerprintCapturedEventArgs(capturedTemplate.Take(capturedTemplateSize).ToArray(), capturedTemplateSize)
                    );
                }
            }
            catch (Exception ex)
            {
                LastError = ex.Message;
                logger.LogError(ex, "Fingerprint capture failed.");
            }

            Thread.Sleep(80);
        }
    }
}

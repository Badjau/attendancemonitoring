using System.Drawing;
using System.Drawing.Imaging;
using libzkfpcsharp;
using Microsoft.Extensions.Options;
using Sample;
using ZktecoLocalAgent.Services;

namespace ZktecoLocalAgent.Sdk;

public sealed class ZkFingerprintSdk : IZkFingerprintSdk
{
    private const int CaptureTemplateBufferSize = 2048;
    private static readonly TimeSpan ReconnectBaseDelay = TimeSpan.FromSeconds(2);
    private static readonly TimeSpan ReconnectMaxDelay = TimeSpan.FromSeconds(30);

    private readonly ILogger<ZkFingerprintSdk> logger;
    private readonly AgentRestartCoordinator restartCoordinator;
    private readonly ScannerRecoveryState recovery;
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
    private bool scannerConnected;
    private DateTimeOffset lastCaptureWarningAt = DateTimeOffset.MinValue;
    private DateTimeOffset? lastCaptureAttemptAt;
    private DateTimeOffset? lastCaptureSuccessAt;
    private int? lastCaptureReturnCode;
    private int captureSuccessCount;
    private int captureNoCaptureCount;
    private int captureErrorCount;
    private string? lastCaptureError;
    private IReadOnlyList<LocalFingerprintTemplate> matcherTemplates = [];

    public ZkFingerprintSdk(
        ILogger<ZkFingerprintSdk> logger,
        IOptions<AgentOptions> options,
        AgentRestartCoordinator restartCoordinator)
    {
        this.logger = logger;
        this.restartCoordinator = restartCoordinator;

        var agentOptions = options.Value;
        recovery = new ScannerRecoveryState(
            ReconnectBaseDelay,
            ReconnectMaxDelay,
            TimeSpan.FromSeconds(Math.Max(1, agentOptions.ScannerProbeIntervalSeconds)),
            TimeSpan.FromSeconds(Math.Max(10, agentOptions.RestartAfterDisconnectedSeconds)),
            agentOptions.RestartAfterFailedReconnects);
    }

    public event EventHandler<FingerprintCapturedEventArgs>? FingerprintCaptured;
    public event EventHandler<ScannerStateChangedEventArgs>? ScannerStateChanged;
    public bool IsInitialized { get; private set; }
    public bool ScannerAvailable { get; private set; }
    public string? MissingDependency { get; private set; }
    public string? LastError { get; private set; }
    public FingerprintCaptureDiagnostics CaptureDiagnostics
    {
        get
        {
            lock (sync)
            {
                return new FingerprintCaptureDiagnostics(
                    lastCaptureAttemptAt,
                    lastCaptureSuccessAt,
                    lastCaptureReturnCode,
                    captureSuccessCount,
                    captureNoCaptureCount,
                    captureErrorCount,
                    lastCaptureError
                );
            }
        }
    }

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

            lock (sync)
            {
                if (captureThread is not null && captureThread.IsAlive)
                {
                    return;
                }

                captureThread = null;

                stopCapture = false;
                captureThread = new Thread(CaptureLoop)
                {
                    IsBackground = true,
                    Name = "ZKTeco capture",
                };
                captureThread.Start();
            }

            if (!TryConnectScanner())
            {
                LastError ??= "Waiting for fingerprint scanner connection.";
            }
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

    public void RefreshStatus()
    {
        ScannerStateChangedEventArgs? scannerStateChanged = null;

        lock (sync)
        {
            if (
                scannerConnected &&
                deviceHandle != IntPtr.Zero &&
                dbHandle != IntPtr.Zero &&
                fingerprintBuffer is not null &&
                !ProbeScannerLocked(out var probeMessage)
            )
            {
                scannerStateChanged = ScheduleReconnectLocked(probeMessage);
                logger.LogWarning("{Message}", probeMessage);
            }
        }

        if (scannerStateChanged is not null)
        {
            ScannerStateChanged?.Invoke(this, scannerStateChanged);
        }
    }

    public void ReloadMatcher(IReadOnlyList<LocalFingerprintTemplate> templates)
    {
        lock (sync)
        {
            matcherTemplates = templates.ToArray();

            if (dbHandle != IntPtr.Zero)
            {
                ApplyMatcherTemplatesLocked();
            }
        }

        logger.LogInformation("Cached {TemplateCount} fingerprint template(s) for the SDK matcher.", matcherTemplates.Count);
    }

    public TemplateMatch? Identify(byte[] capturedTemplate)
    {
        lock (sync)
        {
            if (dbHandle == IntPtr.Zero)
            {
                return null;
            }

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
        lock (sync)
        {
            return dbHandle == IntPtr.Zero ? 0 : zkfp2.DBMatch(dbHandle, leftTemplate, rightTemplate);
        }
    }

    public byte[] MergeEnrollmentTemplates(byte[] first, byte[] second, byte[] third)
    {
        lock (sync)
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
    }

    public string? LastFingerprintImageBase64()
    {
        lock (sync)
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
    }

    public void Dispose()
    {
        stopCapture = true;
        captureThread?.Join(2000);

        lock (sync)
        {
            ReleaseScannerResourcesLocked();
            captureThread = null;
        }
    }

    private void CaptureLoop()
    {
        while (!stopCapture)
        {
            try
            {
                if (!TryConnectScanner())
                {
                    SleepUntilNextReconnect();
                    continue;
                }

                IntPtr currentDeviceHandle;
                byte[]? currentFingerprintBuffer;

                lock (sync)
                {
                    currentDeviceHandle = deviceHandle;
                    currentFingerprintBuffer = fingerprintBuffer;
                }

                if (currentDeviceHandle == IntPtr.Zero || currentFingerprintBuffer is null)
                {
                    Thread.Sleep(250);
                    continue;
                }

                var capturedTemplate = new byte[CaptureTemplateBufferSize];
                var capturedTemplateSize = CaptureTemplateBufferSize;
                var ret = zkfp2.AcquireFingerprint(currentDeviceHandle, currentFingerprintBuffer, capturedTemplate, ref capturedTemplateSize);
                RecordCaptureAttempt(ret);

                if (ret == zkfp.ZKFP_ERR_OK)
                {
                    lock (sync)
                    {
                        lastFingerprintBuffer = currentFingerprintBuffer.ToArray();
                        lastCaptureSuccessAt = DateTimeOffset.UtcNow;
                        captureSuccessCount++;
                        lastCaptureError = null;
                    }

                    FingerprintCaptured?.Invoke(
                        this,
                        new FingerprintCapturedEventArgs(capturedTemplate.Take(capturedTemplateSize).ToArray(), capturedTemplateSize)
                    );
                }
                else if (IsScannerDisconnected())
                {
                    HandleScannerDisconnect($"Fingerprint scanner disconnected, code {ret}.");
                }
                else
                {
                    LogCaptureWarning(ret);
                }
            }
            catch (Exception ex)
            {
                RecordCaptureException(ex);

                if (IsScannerDisconnected())
                {
                    HandleScannerDisconnect("Fingerprint scanner disconnected.", ex);
                }
                else
                {
                    logger.LogError(ex, "Fingerprint capture failed.");
                }
            }

            Thread.Sleep(80);
        }
    }

    private void RecordCaptureAttempt(int returnCode)
    {
        lock (sync)
        {
            lastCaptureAttemptAt = DateTimeOffset.UtcNow;
            lastCaptureReturnCode = returnCode;

            if (returnCode != zkfp.ZKFP_ERR_OK)
            {
                captureNoCaptureCount++;
            }
        }
    }

    private void RecordCaptureException(Exception exception)
    {
        lock (sync)
        {
            lastCaptureAttemptAt = DateTimeOffset.UtcNow;
            captureErrorCount++;
            lastCaptureError = exception.Message;
        }
    }

    private bool TryConnectScanner()
    {
        ScannerStateChangedEventArgs? scannerStateChanged = null;

        lock (sync)
        {
            if (stopCapture)
            {
                return false;
            }

            if (scannerConnected && deviceHandle != IntPtr.Zero && dbHandle != IntPtr.Zero && fingerprintBuffer is not null)
            {
                if (recovery.IsProbeDue(DateTimeOffset.UtcNow) && !ProbeScannerLocked(out var probeMessage))
                {
                    scannerStateChanged = ScheduleReconnectLocked(probeMessage);
                    logger.LogWarning("{Message}", probeMessage);
                }
                else
                {
                    return true;
                }
            }

            if (!recovery.IsReconnectDue(DateTimeOffset.UtcNow))
            {
                return false;
            }

            ReleaseScannerResourcesLocked();

            try
            {
                var ret = zkfp2.Init();
                if (ret != zkfperrdef.ZKFP_ERR_OK)
                {
                    ScheduleReconnectLocked($"ZKTeco SDK initialization failed, code {ret}.");
                    return false;
                }

                IsInitialized = true;

                var deviceCount = zkfp2.GetDeviceCount();
                if (deviceCount <= 0)
                {
                    ScheduleReconnectLocked("No ZKTeco fingerprint scanner is connected.");
                    return false;
                }

                deviceHandle = zkfp2.OpenDevice(0);
                if (deviceHandle == IntPtr.Zero)
                {
                    ScheduleReconnectLocked("ZKTeco scanner OpenDevice failed.");
                    return false;
                }

                dbHandle = zkfp2.DBInit();
                if (dbHandle == IntPtr.Zero)
                {
                    ScheduleReconnectLocked("ZKTeco matcher DBInit failed.");
                    return false;
                }

                var paramValue = new byte[4];
                var size = 4;
                zkfp2.GetParameters(deviceHandle, 1, paramValue, ref size);
                zkfp2.ByteArray2Int(paramValue, ref fingerprintWidth);

                size = 4;
                zkfp2.GetParameters(deviceHandle, 2, paramValue, ref size);
                zkfp2.ByteArray2Int(paramValue, ref fingerprintHeight);

                fingerprintBuffer = new byte[Math.Max(1, fingerprintWidth * fingerprintHeight)];
                lastFingerprintBuffer = null;
                ApplyMatcherTemplatesLocked();

                IsInitialized = true;
                ScannerAvailable = true;
                scannerConnected = true;
                LastError = null;
                recovery.MarkConnected(DateTimeOffset.UtcNow);
                restartCoordinator.ClearRecoveryRestartMarker();

                logger.LogInformation("ZKTeco scanner connected. Image size {Width}x{Height}.", fingerprintWidth, fingerprintHeight);
                scannerStateChanged = new ScannerStateChangedEventArgs(true, null);
            }
            catch (DllNotFoundException ex)
            {
                MissingDependency = ex.Message;
                IsInitialized = false;
                ScannerAvailable = false;
                scannerConnected = false;
                LastError = "A ZKTeco SDK DLL or native dependency is missing.";
                logger.LogError(ex, "{Message}", LastError);
                scannerStateChanged = new ScannerStateChangedEventArgs(false, LastError);
                stopCapture = true;
            }
            catch (Exception ex)
            {
                ScheduleReconnectLocked(ex.Message);
                logger.LogError(ex, "ZKTeco scanner connection attempt failed.");
            }
        }

        if (scannerStateChanged is not null)
        {
            ScannerStateChanged?.Invoke(this, scannerStateChanged);
        }

        return ScannerAvailable;
    }

    private void HandleScannerDisconnect(string message, Exception? exception = null)
    {
        ScannerStateChangedEventArgs? scannerStateChanged;

        lock (sync)
        {
            if (stopCapture)
            {
                return;
            }

            logger.LogWarning(exception, "{Message}", message);
            scannerStateChanged = ScheduleReconnectLocked(message);
        }

        if (scannerStateChanged is not null)
        {
            ScannerStateChanged?.Invoke(this, scannerStateChanged);
        }
    }

    private ScannerStateChangedEventArgs? ScheduleReconnectLocked(string message)
    {
        var previousConnected = scannerConnected;
        ReleaseScannerResourcesLocked();
        IsInitialized = false;
        ScannerAvailable = false;
        LastError = message;
        scannerConnected = false;

        if (recovery.MarkDisconnected(DateTimeOffset.UtcNow))
        {
            logger.LogWarning(
                "Requesting agent restart after sustained ZKTeco scanner disconnect. Last error: {Message}",
                message);
            restartCoordinator.RequestRestart(message);
        }

        return previousConnected ? new ScannerStateChangedEventArgs(false, message) : null;
    }

    private void ReleaseScannerResourcesLocked()
    {
        loadedTemplates.Clear();

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

        IsInitialized = false;
        ScannerAvailable = false;
        fingerprintBuffer = null;
        fingerprintWidth = 0;
        fingerprintHeight = 0;
    }

    private void ApplyMatcherTemplatesLocked()
    {
        loadedTemplates.Clear();

        if (dbHandle == IntPtr.Zero)
        {
            return;
        }

        zkfp2.DBClear(dbHandle);

        foreach (var template in matcherTemplates)
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

        logger.LogInformation("Loaded {TemplateCount} fingerprint template(s) into SDK matcher.", loadedTemplates.Count);
    }

    private bool IsScannerDisconnected()
    {
        try
        {
            return zkfp2.GetDeviceCount() <= 0;
        }
        catch
        {
            return true;
        }
    }

    private bool ProbeScannerLocked(out string message)
    {
        message = "Fingerprint scanner disconnected during health probe.";

        try
        {
            if (zkfp2.GetDeviceCount() <= 0)
            {
                message = "No ZKTeco fingerprint scanner is connected.";
                return false;
            }

            if (deviceHandle == IntPtr.Zero)
            {
                message = "ZKTeco scanner handle is not open.";
                return false;
            }

            var paramValue = new byte[4];
            var size = 4;
            var ret = zkfp2.GetParameters(deviceHandle, 1, paramValue, ref size);
            if (ret != zkfp.ZKFP_ERR_OK)
            {
                message = $"ZKTeco scanner probe failed, code {ret}.";
                return false;
            }

            recovery.MarkProbeSucceeded(DateTimeOffset.UtcNow);
            return true;
        }
        catch (Exception ex)
        {
            message = $"ZKTeco scanner probe failed: {ex.Message}";
            return false;
        }
    }

    private void SleepUntilNextReconnect()
    {
        TimeSpan delay;

        lock (sync)
        {
            delay = recovery.DelayUntilReconnect(DateTimeOffset.UtcNow);
        }

        if (delay > TimeSpan.Zero)
        {
            Thread.Sleep(delay > ReconnectMaxDelay ? ReconnectMaxDelay : delay);
        }
    }

    private void LogCaptureWarning(int code)
    {
        var now = DateTimeOffset.UtcNow;
        if (now - lastCaptureWarningAt < TimeSpan.FromSeconds(5))
        {
            return;
        }

        lastCaptureWarningAt = now;
        logger.LogDebug("ZKTeco AcquireFingerprint returned non-capture code {Code}.", code);
    }
}

namespace ZktecoLocalAgent.Sdk;

public interface IZkFingerprintSdk : IDisposable
{
    bool IsInitialized { get; }
    bool ScannerAvailable { get; }
    string? MissingDependency { get; }
    string? LastError { get; }
    FingerprintCaptureDiagnostics CaptureDiagnostics { get; }
    event EventHandler<FingerprintCapturedEventArgs>? FingerprintCaptured;
    event EventHandler<ScannerStateChangedEventArgs>? ScannerStateChanged;
    void Initialize();
    void RefreshStatus();
    void ReloadMatcher(IReadOnlyList<LocalFingerprintTemplate> templates);
    TemplateMatch? Identify(byte[] capturedTemplate);
    int Match(byte[] leftTemplate, byte[] rightTemplate);
    byte[] MergeEnrollmentTemplates(byte[] first, byte[] second, byte[] third);
    string? LastFingerprintImageBase64();
}

public sealed class FingerprintCapturedEventArgs : EventArgs
{
    public FingerprintCapturedEventArgs(byte[] template, int templateSize)
    {
        Template = template;
        TemplateSize = templateSize;
    }

    public byte[] Template { get; }
    public int TemplateSize { get; }
}

public sealed class ScannerStateChangedEventArgs : EventArgs
{
    public ScannerStateChangedEventArgs(bool isConnected, string? message)
    {
        IsConnected = isConnected;
        Message = message;
    }

    public bool IsConnected { get; }
    public string? Message { get; }
}

public sealed record FingerprintCaptureDiagnostics(
    DateTimeOffset? LastAttemptAt,
    DateTimeOffset? LastSuccessAt,
    int? LastReturnCode,
    int SuccessCount,
    int NoCaptureCount,
    int ErrorCount,
    string? LastError
);

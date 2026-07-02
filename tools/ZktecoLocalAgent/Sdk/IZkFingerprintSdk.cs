namespace ZktecoLocalAgent.Sdk;

public interface IZkFingerprintSdk : IDisposable
{
    bool IsInitialized { get; }
    bool ScannerAvailable { get; }
    string? MissingDependency { get; }
    string? LastError { get; }
    event EventHandler<FingerprintCapturedEventArgs>? FingerprintCaptured;
    void Initialize();
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

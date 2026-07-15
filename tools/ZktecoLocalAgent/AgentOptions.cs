namespace ZktecoLocalAgent;

public sealed class AgentOptions
{
    public string ApiBaseUrl { get; set; } = "http://attendancemonitoring.test/api/zkteco";
    public string[] ApiFallbackBaseUrls { get; set; } =
    [
        "http://attendancemonitoring.test/api/zkteco",
        "http://127.0.0.1/api/zkteco",
        "http://localhost/api/zkteco",
        "http://localhost:8000/api/zkteco",
        "http://127.0.0.1:8000/api/zkteco",
    ];
    public string ScannerToken { get; set; } = "";
    public string LocalListenUrl { get; set; } = "http://127.0.0.1:8765";
    public string DeviceSerial { get; set; } = "ZKTECO-LOCAL";
    public int SyncIntervalSeconds { get; set; } = 60;
    public int ScannerProbeIntervalSeconds { get; set; } = 3;
    public int RestartAfterDisconnectedSeconds { get; set; } = 120;
    public int RestartAfterFailedReconnects { get; set; } = 12;
    public int LogRetentionDays { get; set; } = 14;
    public bool AllowInvalidServerCertificate { get; set; } = true;
}

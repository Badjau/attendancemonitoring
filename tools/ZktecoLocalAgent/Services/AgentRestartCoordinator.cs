namespace ZktecoLocalAgent.Services;

public sealed class AgentRestartCoordinator
{
    private readonly object sync = new();
    private readonly string restartMarkerPath;
    private bool restartRequested;

    public AgentRestartCoordinator()
    {
        var directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "ZktecoLocalAgent");
        Directory.CreateDirectory(directory);
        restartMarkerPath = Path.Combine(directory, "scanner-recovery-restart.pending");
    }

    public event EventHandler<AgentRestartRequestedEventArgs>? RestartRequested;

    public bool IsRestartRequested
    {
        get
        {
            lock (sync)
            {
                return restartRequested;
            }
        }
    }

    public bool RequestRestart(string reason)
    {
        lock (sync)
        {
            if (restartRequested)
            {
                return false;
            }

            if (File.Exists(restartMarkerPath))
            {
                return false;
            }

            restartRequested = true;
            File.WriteAllText(restartMarkerPath, $"{DateTimeOffset.UtcNow:O}\n{reason}");
        }

        RestartRequested?.Invoke(this, new AgentRestartRequestedEventArgs(reason));
        return true;
    }

    public void ClearRecoveryRestartMarker()
    {
        lock (sync)
        {
            restartRequested = false;

            if (File.Exists(restartMarkerPath))
            {
                File.Delete(restartMarkerPath);
            }
        }
    }
}

public sealed class AgentRestartRequestedEventArgs : EventArgs
{
    public AgentRestartRequestedEventArgs(string reason)
    {
        Reason = reason;
    }

    public string Reason { get; }
}

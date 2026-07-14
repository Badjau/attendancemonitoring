namespace ZktecoLocalAgent.Sdk;

public sealed class ScannerRecoveryState
{
    private readonly TimeSpan reconnectBaseDelay;
    private readonly TimeSpan reconnectMaxDelay;
    private readonly TimeSpan probeInterval;
    private readonly TimeSpan restartAfterDisconnected;
    private readonly int restartAfterFailedReconnects;

    private int reconnectAttemptCount;
    private DateTimeOffset? disconnectedSince;
    private bool restartRequestedForDisconnect;

    public ScannerRecoveryState(
        TimeSpan reconnectBaseDelay,
        TimeSpan reconnectMaxDelay,
        TimeSpan probeInterval,
        TimeSpan restartAfterDisconnected,
        int restartAfterFailedReconnects)
    {
        this.reconnectBaseDelay = reconnectBaseDelay;
        this.reconnectMaxDelay = reconnectMaxDelay;
        this.probeInterval = probeInterval;
        this.restartAfterDisconnected = restartAfterDisconnected;
        this.restartAfterFailedReconnects = Math.Max(1, restartAfterFailedReconnects);
        NextReconnectAttempt = DateTimeOffset.MinValue;
        NextProbeAt = DateTimeOffset.MinValue;
    }

    public int ReconnectAttemptCount => reconnectAttemptCount;
    public DateTimeOffset NextReconnectAttempt { get; private set; }
    public DateTimeOffset NextProbeAt { get; private set; }

    public bool IsReconnectDue(DateTimeOffset now) => now >= NextReconnectAttempt;

    public bool IsProbeDue(DateTimeOffset now) => now >= NextProbeAt;

    public TimeSpan DelayUntilReconnect(DateTimeOffset now)
    {
        var delay = NextReconnectAttempt > now ? NextReconnectAttempt - now : reconnectBaseDelay;
        return delay > reconnectMaxDelay ? reconnectMaxDelay : delay;
    }

    public void MarkConnected(DateTimeOffset now)
    {
        reconnectAttemptCount = 0;
        disconnectedSince = null;
        restartRequestedForDisconnect = false;
        NextReconnectAttempt = DateTimeOffset.MinValue;
        NextProbeAt = now + probeInterval;
    }

    public void MarkProbeSucceeded(DateTimeOffset now)
    {
        reconnectAttemptCount = 0;
        NextProbeAt = now + probeInterval;
    }

    public bool MarkDisconnected(DateTimeOffset now)
    {
        disconnectedSince ??= now;
        reconnectAttemptCount++;
        NextReconnectAttempt = now + GetReconnectDelay(reconnectAttemptCount);
        NextProbeAt = DateTimeOffset.MinValue;

        return ShouldRequestRestart(now);
    }

    private bool ShouldRequestRestart(DateTimeOffset now)
    {
        if (restartRequestedForDisconnect)
        {
            return false;
        }

        var disconnectedTooLong = disconnectedSince is not null && now - disconnectedSince.Value >= restartAfterDisconnected;
        var reconnectsExceeded = reconnectAttemptCount >= restartAfterFailedReconnects;

        if (!disconnectedTooLong && !reconnectsExceeded)
        {
            return false;
        }

        restartRequestedForDisconnect = true;
        return true;
    }

    private TimeSpan GetReconnectDelay(int attempt)
    {
        if (attempt <= 0)
        {
            return reconnectBaseDelay;
        }

        var multiplier = Math.Min(5, attempt - 1);
        var delay = TimeSpan.FromSeconds(reconnectBaseDelay.TotalSeconds * Math.Pow(2, multiplier));
        return delay > reconnectMaxDelay ? reconnectMaxDelay : delay;
    }
}

using ZktecoLocalAgent.Sdk;

namespace ZktecoLocalAgent.Tests;

public sealed class ScannerRecoveryStateTests
{
    private static readonly DateTimeOffset Now = new(2026, 7, 8, 8, 0, 0, TimeSpan.Zero);

    [Fact]
    public void Connected_scanner_is_ready_until_probe_window_expires()
    {
        var state = NewState();

        state.MarkConnected(Now);

        Assert.Equal(0, state.ReconnectAttemptCount);
        Assert.True(state.IsReconnectDue(Now));
        Assert.False(state.IsProbeDue(Now.AddSeconds(2)));
        Assert.True(state.IsProbeDue(Now.AddSeconds(3)));
    }

    [Fact]
    public void Probe_success_resets_reconnect_backoff()
    {
        var state = NewState();

        state.MarkConnected(Now);
        state.MarkDisconnected(Now.AddSeconds(3));
        state.MarkConnected(Now.AddSeconds(5));
        state.MarkProbeSucceeded(Now.AddSeconds(8));

        Assert.Equal(0, state.ReconnectAttemptCount);
        Assert.False(state.IsProbeDue(Now.AddSeconds(10)));
        Assert.True(state.IsProbeDue(Now.AddSeconds(11)));
    }

    [Fact]
    public void Disconnect_schedules_exponential_reconnect()
    {
        var state = NewState();

        Assert.False(state.MarkDisconnected(Now));
        Assert.False(state.IsReconnectDue(Now.AddMilliseconds(1999)));
        Assert.True(state.IsReconnectDue(Now.AddSeconds(2)));

        Assert.False(state.MarkDisconnected(Now.AddSeconds(2)));
        Assert.False(state.IsReconnectDue(Now.AddSeconds(5)));
        Assert.True(state.IsReconnectDue(Now.AddSeconds(6)));
    }

    [Fact]
    public void Replug_resets_reconnect_backoff_for_fast_recovery()
    {
        var state = NewState();

        state.MarkDisconnected(Now);
        state.MarkDisconnected(Now.AddSeconds(2));
        state.MarkConnected(Now.AddSeconds(6));
        var shouldRestart = state.MarkDisconnected(Now.AddSeconds(9));

        Assert.False(shouldRestart);
        Assert.Equal(1, state.ReconnectAttemptCount);
        Assert.True(state.IsReconnectDue(Now.AddSeconds(11)));
    }

    [Fact]
    public void Prolonged_disconnect_requests_restart_once()
    {
        var state = NewState();

        Assert.False(state.MarkDisconnected(Now));
        Assert.True(state.MarkDisconnected(Now.AddSeconds(30)));
        Assert.False(state.MarkDisconnected(Now.AddSeconds(32)));
    }

    [Fact]
    public void Repeated_failed_reconnects_request_restart_once()
    {
        var state = NewState(restartAfterFailedReconnects: 3);

        Assert.False(state.MarkDisconnected(Now));
        Assert.False(state.MarkDisconnected(Now.AddSeconds(2)));
        Assert.True(state.MarkDisconnected(Now.AddSeconds(6)));
        Assert.False(state.MarkDisconnected(Now.AddSeconds(14)));
    }

    private static ScannerRecoveryState NewState(int restartAfterFailedReconnects = 12)
    {
        return new ScannerRecoveryState(
            TimeSpan.FromSeconds(2),
            TimeSpan.FromSeconds(30),
            TimeSpan.FromSeconds(3),
            TimeSpan.FromSeconds(30),
            restartAfterFailedReconnects);
    }
}

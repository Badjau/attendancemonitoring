using Microsoft.Extensions.Options;
using ZktecoLocalAgent.Data;
using ZktecoLocalAgent.Sdk;

namespace ZktecoLocalAgent.Services;

public sealed class TemplateSyncService : BackgroundService
{
    private readonly TemplateCache cache;
    private readonly LaravelApiClient laravel;
    private readonly IZkFingerprintSdk sdk;
    private readonly CommandCoordinator commands;
    private readonly AgentOptions options;
    private readonly ILogger<TemplateSyncService> logger;
    private string? revision;

    public TemplateSyncService(
        TemplateCache cache,
        LaravelApiClient laravel,
        IZkFingerprintSdk sdk,
        CommandCoordinator commands,
        IOptions<AgentOptions> options,
        ILogger<TemplateSyncService> logger)
    {
        this.cache = cache;
        this.laravel = laravel;
        this.sdk = sdk;
        this.commands = commands;
        this.options = options.Value;
        this.logger = logger;
    }

    public string? CurrentRevision => revision;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        await cache.InitializeAsync(stoppingToken);
        sdk.Initialize();

        var cached = await cache.LoadTemplatesAsync(stoppingToken);
        sdk.ReloadMatcher(cached);
        revision = await cache.CurrentRevisionAsync(stoppingToken);

        while (!stoppingToken.IsCancellationRequested)
        {
            await SyncIfNeededAsync(stoppingToken);
            await Task.Delay(TimeSpan.FromSeconds(Math.Max(10, options.SyncIntervalSeconds)), stoppingToken);
        }
    }

    private async Task SyncIfNeededAsync(CancellationToken cancellationToken)
    {
        try
        {
            await commands.PublishAsync(new CommandEvent(null, AgentStates.Syncing, "Checking fingerprint template manifest."), cancellationToken);
            var manifest = await laravel.ManifestAsync(cancellationToken);
            var scannerReady = sdk.IsInitialized && sdk.ScannerAvailable;

            if (manifest.Revision == revision)
            {
                await commands.PublishAsync(
                    new CommandEvent(
                        null,
                        scannerReady ? AgentStates.Ready : AgentStates.Error,
                        scannerReady
                            ? "Fingerprint agent ready."
                            : sdk.LastError ?? "Fingerprint templates are synced, but the scanner is not connected."
                    ),
                    cancellationToken
                );
                return;
            }

            logger.LogInformation("Fingerprint manifest changed from {OldRevision} to {NewRevision}. Rebuilding local cache.", revision, manifest.Revision);
            var templates = await laravel.AllTemplatesAsync(cancellationToken);
            await cache.RebuildTemplatesAsync(templates, manifest.Revision, cancellationToken);
            sdk.ReloadMatcher(await cache.LoadTemplatesAsync(cancellationToken));
            revision = manifest.Revision;
            await commands.PublishAsync(
                new CommandEvent(
                    null,
                    scannerReady ? AgentStates.Ready : AgentStates.Error,
                    scannerReady
                        ? $"Synced {templates.Count} fingerprint template(s)."
                        : sdk.LastError ?? $"Synced {templates.Count} fingerprint template(s), but the scanner is not connected."
                ),
                cancellationToken
            );
        }
        catch (Exception ex)
        {
            var message = SummarizeException(ex);
            await cache.SetSyncErrorAsync(message, cancellationToken);
            logger.LogError(ex, "Fingerprint template sync failed.");
            await commands.PublishAsync(new CommandEvent(null, AgentStates.Error, $"Fingerprint sync failed: {message}", ErrorCode: ex.GetType().Name), cancellationToken);
        }
    }

    private static string SummarizeException(Exception exception)
    {
        var messages = new List<string>();
        for (var current = exception; current is not null; current = current.InnerException)
        {
            if (!string.IsNullOrWhiteSpace(current.Message))
            {
                messages.Add(current.Message);
            }
        }

        return string.Join(" | ", messages.Distinct());
    }
}

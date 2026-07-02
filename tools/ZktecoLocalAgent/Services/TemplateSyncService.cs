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

            if (manifest.Revision == revision)
            {
                await commands.PublishAsync(new CommandEvent(null, AgentStates.Ready, "Fingerprint agent ready."), cancellationToken);
                return;
            }

            logger.LogInformation("Fingerprint manifest changed from {OldRevision} to {NewRevision}. Rebuilding local cache.", revision, manifest.Revision);
            var templates = await laravel.AllTemplatesAsync(cancellationToken);
            await cache.RebuildTemplatesAsync(templates, manifest.Revision, cancellationToken);
            sdk.ReloadMatcher(await cache.LoadTemplatesAsync(cancellationToken));
            revision = manifest.Revision;
            await commands.PublishAsync(new CommandEvent(null, AgentStates.Ready, $"Synced {templates.Count} fingerprint template(s)."), cancellationToken);
        }
        catch (Exception ex)
        {
            await cache.SetSyncErrorAsync(ex.Message, cancellationToken);
            logger.LogError(ex, "Fingerprint template sync failed.");
            await commands.PublishAsync(new CommandEvent(null, AgentStates.Error, $"Fingerprint sync failed: {ex.Message}", ErrorCode: ex.GetType().Name), cancellationToken);
        }
    }
}

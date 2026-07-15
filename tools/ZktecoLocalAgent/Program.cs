using System.Diagnostics;
using System.Drawing;
using System.Text.Json;
using System.Windows.Forms;
using Microsoft.Extensions.Options;
using Serilog;
using ZktecoLocalAgent;
using ZktecoLocalAgent.Data;
using ZktecoLocalAgent.Sdk;
using ZktecoLocalAgent.Services;

ApplicationConfiguration.Initialize();

var configProbe = new ConfigurationBuilder()
    .AddJsonFile("appsettings.json", optional: true)
    .AddEnvironmentVariables("ZKTECO_AGENT_")
    .Build();

var probeOptions = configProbe.GetSection("ZktecoAgent").Get<AgentOptions>() ?? new AgentOptions();
var logDirectory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ZktecoLocalAgent", "logs");
Directory.CreateDirectory(logDirectory);

Log.Logger = new LoggerConfiguration()
    .MinimumLevel.Information()
    .Enrich.FromLogContext()
    .WriteTo.File(
        Path.Combine(logDirectory, "agent-.log"),
        rollingInterval: RollingInterval.Day,
        retainedFileCountLimit: Math.Max(1, probeOptions.LogRetentionDays))
    .CreateLogger();

var builder = WebApplication.CreateBuilder(new WebApplicationOptions
{
    Args = args,
    ContentRootPath = AppContext.BaseDirectory,
});

builder.Host.UseSerilog();
builder.Configuration.AddJsonFile("appsettings.json", optional: true, reloadOnChange: true);
builder.Configuration.AddEnvironmentVariables("ZKTECO_AGENT_");
builder.Services.Configure<AgentOptions>(builder.Configuration.GetSection("ZktecoAgent"));
builder.Services.AddSingleton<TemplateCache>();
builder.Services.AddSingleton<AgentRestartCoordinator>();
builder.Services.AddSingleton<IZkFingerprintSdk, ZkFingerprintSdk>();
builder.Services.AddSingleton<CommandCoordinator>();
builder.Services.AddSingleton<TemplateSyncService>();
builder.Services.AddHostedService(provider => provider.GetRequiredService<TemplateSyncService>());
builder.Services.AddHttpClient<LaravelApiClient>()
    .ConfigurePrimaryHttpMessageHandler(provider =>
    {
        var options = provider.GetRequiredService<IOptions<AgentOptions>>().Value;

        var handler = new SocketsHttpHandler
        {
            ConnectTimeout = TimeSpan.FromSeconds(5),
            PooledConnectionLifetime = TimeSpan.FromSeconds(30),
        };

        if (options.AllowInvalidServerCertificate)
        {
            handler.SslOptions.RemoteCertificateValidationCallback = (_, _, _, _) => true;
        }

        return handler;
    });

var app = builder.Build();
var options = app.Services.GetRequiredService<IOptions<AgentOptions>>().Value;
var restartCoordinator = app.Services.GetRequiredService<AgentRestartCoordinator>();
app.Urls.Clear();
app.Urls.Add(options.LocalListenUrl);

app.Use(async (context, next) =>
{
    context.Response.Headers["Access-Control-Allow-Origin"] = "*";
    context.Response.Headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS";
    context.Response.Headers["Access-Control-Allow-Headers"] = "Content-Type, Accept, X-CSRF-TOKEN, X-Requested-With, Access-Control-Request-Private-Network";
    context.Response.Headers["Access-Control-Allow-Private-Network"] = "true";

    if (context.Request.Method == "OPTIONS")
    {
        context.Response.StatusCode = StatusCodes.Status204NoContent;
        return;
    }

    await next();
});

app.MapGet("/health", (CommandCoordinator commands, TemplateSyncService sync) => Results.Ok(commands.Health(sync.CurrentRevision)));
app.MapGet("/status", (CommandCoordinator commands) => Results.Ok(commands.Current));
app.MapGet("/diagnostics", (CommandCoordinator commands, TemplateSyncService sync, IZkFingerprintSdk sdk) => Results.Ok(new
{
    status = commands.Current,
    health = commands.Health(sync.CurrentRevision),
    capture = sdk.CaptureDiagnostics,
}));
app.MapGet("/events", async (HttpContext context, CommandCoordinator commands) =>
{
    var commandId = context.Request.Query["command_id"].ToString();
    context.Response.Headers.CacheControl = "no-cache";
    context.Response.Headers.Connection = "keep-alive";
    context.Response.ContentType = "text/event-stream";

    await foreach (var commandEvent in commands.SubscribeAsync(string.IsNullOrWhiteSpace(commandId) ? null : commandId, context.RequestAborted))
    {
        await context.Response.WriteAsync($"event: {commandEvent.State}\n", context.RequestAborted);
        await context.Response.WriteAsync($"data: {JsonSerializer.Serialize(commandEvent)}\n\n", context.RequestAborted);
        await context.Response.Body.FlushAsync(context.RequestAborted);
    }
});

app.MapPost("/commands/enroll", (StartEnrollRequest request, CommandCoordinator commands, CancellationToken cancellationToken) =>
    commands.StartEnrollmentAsync(request, cancellationToken));

app.MapPost("/commands/attendance", (AttendanceCommandRequest request, CommandCoordinator commands, CancellationToken cancellationToken) =>
    commands.StartAttendanceAsync(request, cancellationToken));

app.MapPost("/commands/unlock", (UnlockCommandRequest request, CommandCoordinator commands, CancellationToken cancellationToken) =>
    commands.StartUnlockAsync(request, cancellationToken));

app.MapPost("/commands/{commandId}/commit-enrollment", (string commandId, CommandCoordinator commands, CancellationToken cancellationToken) =>
    commands.CommitEnrollmentAsync(commandId, cancellationToken));

app.MapPost("/commands/{commandId}/finalize-attendance", (string commandId, AttendanceCommandRequest request, CommandCoordinator commands, CancellationToken cancellationToken) =>
    commands.FinalizeAttendanceAsync(commandId, request, cancellationToken));

app.MapPost("/commands/{commandId}/cancel", (string commandId, CommandCoordinator commands, CancellationToken cancellationToken) =>
    commands.CancelAsync(commandId, cancellationToken));

// Migration aliases. The browser uses /commands/*; these keep old protocol-launch payloads useful during rollout.
app.MapPost("/enroll", (StartEnrollRequest request, CommandCoordinator commands, CancellationToken cancellationToken) =>
    commands.StartEnrollmentAsync(request, cancellationToken));
app.MapPost("/attendance", (AttendanceCommandRequest request, CommandCoordinator commands, CancellationToken cancellationToken) =>
    commands.StartAttendanceAsync(request, cancellationToken));
app.MapPost("/unlock", (UnlockCommandRequest request, CommandCoordinator commands, CancellationToken cancellationToken) =>
    commands.StartUnlockAsync(request, cancellationToken));
app.MapPost("/commit-enrollment", (CommandIdRequest request, CommandCoordinator commands, CancellationToken cancellationToken) =>
    string.IsNullOrWhiteSpace(request.CommandId)
        ? Task.FromResult<IResult>(Results.UnprocessableEntity(new { message = "command_id is required." }))
        : commands.CommitEnrollmentAsync(request.CommandId!, cancellationToken));
app.MapPost("/finalize-attendance", (AttendanceCommandRequest request, CommandCoordinator commands, CancellationToken cancellationToken) =>
    string.IsNullOrWhiteSpace(request.CommandId)
        ? Task.FromResult<IResult>(Results.UnprocessableEntity(new { message = "command_id is required." }))
        : commands.FinalizeAttendanceAsync(request.CommandId!, request, cancellationToken));

using var tray = new AgentTray(app, options, logDirectory, restartCoordinator);
await app.StartAsync();
tray.Run();
await app.StopAsync();

internal sealed class AgentTray : IDisposable
{
    private readonly WebApplication app;
    private readonly AgentOptions options;
    private readonly string logDirectory;
    private readonly AgentRestartCoordinator restartCoordinator;
    private readonly NotifyIcon notifyIcon;

    public AgentTray(WebApplication app, AgentOptions options, string logDirectory, AgentRestartCoordinator restartCoordinator)
    {
        this.app = app;
        this.options = options;
        this.logDirectory = logDirectory;
        this.restartCoordinator = restartCoordinator;
        this.restartCoordinator.RestartRequested += OnRestartRequested;
        notifyIcon = new NotifyIcon
        {
            Icon = SystemIcons.Application,
            Text = "Fingerprint Agent",
            Visible = true,
            ContextMenuStrip = Menu(),
        };
    }

    public void Run()
    {
        Application.Run();
    }

    public void Dispose()
    {
        restartCoordinator.RestartRequested -= OnRestartRequested;
        notifyIcon.Visible = false;
        notifyIcon.Dispose();
    }

    private ContextMenuStrip Menu()
    {
        var menu = new ContextMenuStrip();
        menu.Items.Add("Status", null, (_, _) => Process.Start(new ProcessStartInfo($"{options.LocalListenUrl.TrimEnd('/')}/health") { UseShellExecute = true }));
        menu.Items.Add("Restart", null, async (_, _) => await RestartAsync());
        menu.Items.Add("Open log folder", null, (_, _) => Process.Start(new ProcessStartInfo(logDirectory) { UseShellExecute = true }));
        menu.Items.Add("Exit", null, async (_, _) =>
        {
            await app.StopAsync();
            Application.Exit();
        });

        return menu;
    }

    private void OnRestartRequested(object? sender, AgentRestartRequestedEventArgs e)
    {
        _ = Task.Run(RestartAsync);
    }

    private async Task RestartAsync()
    {
        await app.StopAsync();
        Application.Exit();
        Process.Start(new ProcessStartInfo(Application.ExecutablePath) { UseShellExecute = true });
    }
}

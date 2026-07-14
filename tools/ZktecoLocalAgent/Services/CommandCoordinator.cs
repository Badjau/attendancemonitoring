using System.Text.Json;
using System.Threading.Channels;
using Microsoft.Extensions.Options;
using ZktecoLocalAgent.Data;
using ZktecoLocalAgent.Sdk;

namespace ZktecoLocalAgent.Services;

public sealed class CommandCoordinator : IDisposable
{
    private const int RegisterFingerCount = 3;
    private readonly IZkFingerprintSdk sdk;
    private readonly LaravelApiClient laravel;
    private readonly TemplateCache cache;
    private readonly AgentOptions options;
    private readonly ILogger<CommandCoordinator> logger;
    private readonly object gate = new();
    private readonly List<Channel<CommandEvent>> subscribers = [];
    private readonly List<byte[]> enrollmentTemplates = [];
    private readonly List<string> enrollmentPreviewImages = [];
    private readonly Dictionary<string, CommandEvent> latestEvents = [];

    private ActiveCommand? activeCommand;
    private CommandEvent currentEvent = new(null, AgentStates.Idle, "Fingerprint agent starting.");
    private TemplateMatch? pendingAttendanceMatch;
    private byte[]? pendingEnrollmentTemplate;
    private bool isHandlingCapture;
    private DateTimeOffset lastEnrollmentAcceptedAt = DateTimeOffset.MinValue;

    public CommandCoordinator(
        IZkFingerprintSdk sdk,
        LaravelApiClient laravel,
        TemplateCache cache,
        IOptions<AgentOptions> options,
        ILogger<CommandCoordinator> logger)
    {
        this.sdk = sdk;
        this.laravel = laravel;
        this.cache = cache;
        this.options = options.Value;
        this.logger = logger;
        this.sdk.FingerprintCaptured += OnFingerprintCaptured;
        this.sdk.ScannerStateChanged += OnScannerStateChanged;
    }

    public CommandEvent Current => currentEvent;

    public HealthPayload Health(string? revision)
    {
        sdk.RefreshStatus();
        var ok = sdk.IsInitialized && sdk.ScannerAvailable;
        return new HealthPayload(
            ok,
            currentEvent.State,
            sdk.ScannerAvailable,
            sdk.IsInitialized,
            sdk.MissingDependency,
            sdk.LastError ?? currentEvent.Message,
            revision
        );
    }

    public async Task<IResult> StartEnrollmentAsync(StartEnrollRequest request, CancellationToken cancellationToken)
    {
        if (request.EmployeeDatabaseId <= 0)
        {
            return Results.UnprocessableEntity(new { message = "Employee id is required." });
        }

        if (!await EnsureScannerReadyAsync(cancellationToken))
        {
            return ScannerUnavailable();
        }

        var commandId = string.IsNullOrWhiteSpace(request.CommandId) ? NewCommandId("enroll") : request.CommandId!;
        var command = ActiveCommand.CreateEnrollment(commandId, request);

        if (!TryStart(command, out var conflict))
        {
            return conflict;
        }

        enrollmentTemplates.Clear();
        enrollmentPreviewImages.Clear();
        pendingEnrollmentTemplate = null;
        lastEnrollmentAcceptedAt = DateTimeOffset.MinValue;
        await PublishAsync(Event(commandId, AgentStates.WaitingForScan, "Scan the same finger 3 times.", request), cancellationToken);
        return Results.Ok(new { command_id = commandId, message = "Fingerprint agent is ready. Scan the same finger 3 times." });
    }

    public async Task<IResult> StartAttendanceAsync(AttendanceCommandRequest request, CancellationToken cancellationToken)
    {
        if (!await EnsureScannerReadyAsync(cancellationToken))
        {
            return ScannerUnavailable();
        }

        var commandId = string.IsNullOrWhiteSpace(request.CommandId) ? NewCommandId("attendance") : request.CommandId!;
        var command = ActiveCommand.CreateAttendance(commandId, request);

        if (!TryStart(command, out var conflict))
        {
            return conflict;
        }

        pendingAttendanceMatch = null;
        await PublishAsync(Event(commandId, AgentStates.WaitingForScan, "Waiting for registered fingerprint scan."), cancellationToken);
        return Results.Ok(new { command_id = commandId, message = "Fingerprint agent is ready. Scan a registered finger." });
    }

    public async Task<IResult> StartUnlockAsync(UnlockCommandRequest request, CancellationToken cancellationToken)
    {
        if (!await EnsureScannerReadyAsync(cancellationToken))
        {
            return ScannerUnavailable();
        }

        var commandId = string.IsNullOrWhiteSpace(request.CommandId) ? NewCommandId("unlock") : request.CommandId!;
        var command = ActiveCommand.CreateUnlock(commandId, request);

        if (!TryStart(command, out var conflict))
        {
            return conflict;
        }

        await PublishAsync(Event(commandId, AgentStates.WaitingForScan, "Waiting for unlock fingerprint scan."), cancellationToken);
        return Results.Ok(new { command_id = commandId, message = "Fingerprint agent is ready. Scan an authorized finger." });
    }

    public async Task<IResult> CommitEnrollmentAsync(string commandId, CancellationToken cancellationToken)
    {
        ActiveCommand? command;
        byte[]? template;

        lock (gate)
        {
            command = activeCommand;
            template = pendingEnrollmentTemplate;
        }

        if (command is null || command.Kind != CommandKind.Enrollment || command.CommandId != commandId || template is null)
        {
            return Results.Conflict(new { message = "Fingerprint command does not match a pending enrollment." });
        }

        var request = command.Enrollment!;
        await PublishAsync(Event(commandId, AgentStates.Recording, "Saving fingerprint enrollment...", request), cancellationToken);

        try
        {
            await laravel.EnrollAsync(new
            {
                employee_id = request.EmployeeDatabaseId,
                finger_index = request.FingerIndex <= 0 ? 1 : request.FingerIndex,
                template_base64 = Convert.ToBase64String(template),
                template_size = template.Length,
                device_serial = options.DeviceSerial,
                fingerprint_image_base64 = sdk.LastFingerprintImageBase64(),
            }, cancellationToken);
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Unable to save fingerprint enrollment for command {CommandId}.", commandId);
            await PublishAsync(Event(commandId, AgentStates.Captured, $"Unable to save fingerprint: {ex.Message}", request, sdk.LastFingerprintImageBase64()), CancellationToken.None);

            return Results.Json(new
            {
                message = $"Unable to save fingerprint: {ex.Message}",
            }, statusCode: StatusCodes.Status502BadGateway);
        }

        try
        {
            await RefreshMatcherTemplatesAsync(cancellationToken);
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Fingerprint enrollment saved but matcher refresh failed for command {CommandId}.", commandId);
            ClearActive();
            await PublishAsync(new CommandEvent(commandId, AgentStates.Error, $"Fingerprint was saved, but the scanner cache could not refresh: {ex.Message}", ErrorCode: ex.GetType().Name), CancellationToken.None);

            return Results.Json(new
            {
                message = $"Fingerprint was saved, but the scanner cache could not refresh: {ex.Message}",
            }, statusCode: StatusCodes.Status502BadGateway);
        }

        ClearActive();
        await PublishAsync(Event(commandId, AgentStates.Success, "Fingerprint successfully registered and ready for verification.", request), cancellationToken);
        return Results.Ok(new { message = "Fingerprint successfully registered and ready for verification." });
    }

    public async Task<IResult> FinalizeAttendanceAsync(string commandId, AttendanceCommandRequest request, CancellationToken cancellationToken)
    {
        ActiveCommand? command;
        TemplateMatch? match;

        lock (gate)
        {
            command = activeCommand;
            match = pendingAttendanceMatch;
        }

        if (command is null || command.Kind != CommandKind.Attendance || command.CommandId != commandId || match is null)
        {
            return Results.Conflict(new { message = "Fingerprint command does not match a pending attendance scan." });
        }

        if (string.IsNullOrWhiteSpace(request.AttendanceImage))
        {
            return Results.UnprocessableEntity(new { message = "Attendance photo is required." });
        }

        await PublishAsync(Event(commandId, AgentStates.Recording, "Recording fingerprint attendance...", match.Template, command.Attendance?.AttendanceType, match.Score), cancellationToken);
        var attendance = await laravel.RecordAttendanceAsync(new
        {
            employee_id = match.Template.EmployeeDatabaseId,
            template_id = match.Template.ServerTemplateId,
            score = match.Score,
            device_serial = options.DeviceSerial,
            attendance_type = command.Attendance?.AttendanceType,
            occurred_at = command.Attendance?.OccurredAt,
            offline_id = command.Attendance?.OfflineId,
            attendance_image = request.AttendanceImage,
            location = request.Location ?? command.Attendance?.Location,
            location_source = request.LocationSource ?? command.Attendance?.LocationSource,
            latitude = request.Latitude ?? command.Attendance?.Latitude,
            longitude = request.Longitude ?? command.Attendance?.Longitude,
        }, cancellationToken);

        ClearActive();
        await PublishAsync(Event(commandId, AgentStates.Success, $"Attendance recorded for {attendance?.Employee?.Name ?? match.Template.EmployeeName ?? match.Template.EmployeeCode}.", match.Template, attendance?.AttendanceType, match.Score), cancellationToken);
        return Results.Ok(new { message = "Attendance recorded successfully." });
    }

    public async Task<IResult> CancelAsync(string commandId, CancellationToken cancellationToken)
    {
        ActiveCommand? command;
        lock (gate)
        {
            command = activeCommand;
            if (command is null || command.CommandId != commandId)
            {
                return Results.NotFound(new { message = "Command not found." });
            }
        }

        ClearActive();
        await PublishAsync(new CommandEvent(commandId, AgentStates.Idle, "Command cancelled."), cancellationToken);
        return Results.Ok(new { message = "Command cancelled." });
    }

    public async IAsyncEnumerable<CommandEvent> SubscribeAsync(string? commandId, [System.Runtime.CompilerServices.EnumeratorCancellation] CancellationToken cancellationToken)
    {
        var channel = Channel.CreateUnbounded<CommandEvent>();
        lock (subscribers)
        {
            subscribers.Add(channel);
        }

        try
        {
            if (commandId is not null && latestEvents.TryGetValue(commandId, out var latestEvent))
            {
                yield return latestEvent;
            }
            else if (commandId is null || currentEvent.CommandId == commandId)
            {
                yield return currentEvent;
            }

            while (await channel.Reader.WaitToReadAsync(cancellationToken))
            {
                while (channel.Reader.TryRead(out var commandEvent))
                {
                    if (commandId is null || commandEvent.CommandId == commandId)
                    {
                        yield return commandEvent;
                    }
                }
            }
        }
        finally
        {
            lock (subscribers)
            {
                subscribers.Remove(channel);
            }
        }
    }

    public async Task PublishAsync(CommandEvent commandEvent, CancellationToken cancellationToken)
    {
        var publishedEvent = commandEvent with { CreatedAt = DateTimeOffset.UtcNow };

        lock (gate)
        {
            if (publishedEvent.CommandId is not null)
            {
                latestEvents[publishedEvent.CommandId] = publishedEvent;
                currentEvent = publishedEvent;
            }
            else if (activeCommand is null)
            {
                currentEvent = publishedEvent;
            }
        }

        var json = JsonSerializer.Serialize(publishedEvent);
        await cache.AppendEventAsync(publishedEvent, json, cancellationToken);

        lock (subscribers)
        {
            foreach (var subscriber in subscribers.ToArray())
            {
                subscriber.Writer.TryWrite(publishedEvent);
            }
        }

        logger.LogInformation("Command {CommandId} state {State}: {Message}", publishedEvent.CommandId, publishedEvent.State, publishedEvent.Message);
    }

    public void Dispose()
    {
        sdk.FingerprintCaptured -= OnFingerprintCaptured;
        sdk.ScannerStateChanged -= OnScannerStateChanged;
    }

    private void OnScannerStateChanged(object? sender, ScannerStateChangedEventArgs args)
    {
        _ = Task.Run(async () =>
        {
            if (args.IsConnected)
            {
                await PublishAsync(new CommandEvent(null, AgentStates.Ready, "Fingerprint scanner reconnected and ready."), CancellationToken.None);
                return;
            }

            ClearActive();
            await PublishAsync(new CommandEvent(null, AgentStates.Error, args.Message ?? "Fingerprint scanner disconnected.", ErrorCode: "ScannerDisconnected"), CancellationToken.None);
        });
    }

    private void OnFingerprintCaptured(object? sender, FingerprintCapturedEventArgs args)
    {
        _ = Task.Run(async () =>
        {
            ActiveCommand? command;
            lock (gate)
            {
                if (isHandlingCapture)
                {
                    return;
                }

                command = activeCommand;

                if (command is not null)
                {
                    isHandlingCapture = true;
                }
            }

            if (command is null)
            {
                return;
            }

            try
            {
                switch (command.Kind)
                {
                    case CommandKind.Enrollment:
                        await HandleEnrollmentCaptureAsync(command, args.Template);
                        break;
                    case CommandKind.Attendance:
                        await HandleAttendanceCaptureAsync(command, args.Template);
                        break;
                    case CommandKind.Unlock:
                        await HandleUnlockCaptureAsync(command, args.Template);
                        break;
                }
            }
            catch (Exception ex)
            {
                ClearActive();
                await PublishAsync(new CommandEvent(command.CommandId, AgentStates.Error, ex.Message, ErrorCode: ex.GetType().Name), CancellationToken.None);
            }
            finally
            {
                lock (gate)
                {
                    isHandlingCapture = false;
                }
            }
        });
    }

    private async Task HandleEnrollmentCaptureAsync(ActiveCommand command, byte[] capturedTemplate)
    {
        var now = DateTimeOffset.UtcNow;
        if (now - lastEnrollmentAcceptedAt < TimeSpan.FromMilliseconds(900))
        {
            return;
        }

        var fingerprintImage = sdk.LastFingerprintImageBase64();
        if (!string.IsNullOrWhiteSpace(fingerprintImage))
        {
            enrollmentPreviewImages.Add(fingerprintImage);
        }

        await PublishAsync(Event(command.CommandId, AgentStates.WaitingForScan, "Fingerprint image received.", command.Enrollment!, fingerprintImage), CancellationToken.None);

        if (enrollmentTemplates.Count > 0 && sdk.Match(capturedTemplate, enrollmentTemplates[^1]) <= 0)
        {
            await PublishAsync(Event(command.CommandId, AgentStates.WaitingForScan, "Please scan the same finger for enrollment.", command.Enrollment!, fingerprintImage), CancellationToken.None);
            return;
        }

        enrollmentTemplates.Add(capturedTemplate);
        lastEnrollmentAcceptedAt = now;

        if (enrollmentTemplates.Count < RegisterFingerCount)
        {
            await PublishAsync(Event(command.CommandId, AgentStates.WaitingForScan, $"Scan accepted. {RegisterFingerCount - enrollmentTemplates.Count} scan(s) remaining.", command.Enrollment!, fingerprintImage), CancellationToken.None);
            return;
        }

        pendingEnrollmentTemplate = sdk.MergeEnrollmentTemplates(enrollmentTemplates[0], enrollmentTemplates[1], enrollmentTemplates[2]);
        enrollmentTemplates.Clear();
        await PublishAsync(Event(command.CommandId, AgentStates.Captured, "Fingerprint captured. Review and submit to save.", command.Enrollment!, fingerprintImage), CancellationToken.None);
    }

    private async Task HandleAttendanceCaptureAsync(ActiveCommand command, byte[] capturedTemplate)
    {
        var match = sdk.Identify(capturedTemplate);
        if (match is null)
        {
            await PublishAsync(new CommandEvent(command.CommandId, AgentStates.WaitingForScan, "Fingerprint not recognized. Scan again."), CancellationToken.None);
            return;
        }

        pendingAttendanceMatch = match;
        await PublishAsync(Event(command.CommandId, AgentStates.Matched, "Fingerprint matched. Capturing attendance photo...", match.Template, command.Attendance?.AttendanceType, match.Score), CancellationToken.None);
        await PublishAsync(Event(command.CommandId, AgentStates.AwaitingBrowserPhoto, "Fingerprint matched. Waiting for facial verification.", match.Template, command.Attendance?.AttendanceType, match.Score), CancellationToken.None);
    }

    private async Task HandleUnlockCaptureAsync(ActiveCommand command, byte[] capturedTemplate)
    {
        var match = sdk.Identify(capturedTemplate);
        if (match is null)
        {
            await PublishAsync(new CommandEvent(command.CommandId, AgentStates.WaitingForScan, "Fingerprint not recognized. Scan again."), CancellationToken.None);
            return;
        }

        ClearActive();
        await PublishAsync(Event(command.CommandId, AgentStates.Matched, "Fingerprint matched. Unlocking timeclock...", match.Template, null, match.Score), CancellationToken.None);
    }

    private bool TryStart(ActiveCommand command, out IResult conflict)
    {
        lock (gate)
        {
            if (activeCommand is not null)
            {
                conflict = Results.Conflict(new
                {
                    message = "Another fingerprint command is already running.",
                    command_id = activeCommand.CommandId,
                    state = currentEvent.State,
                });
                return false;
            }

            activeCommand = command;
            conflict = Results.NoContent();
            return true;
        }
    }

    private async Task<bool> EnsureScannerReadyAsync(CancellationToken cancellationToken)
    {
        if (sdk.IsInitialized && sdk.ScannerAvailable)
        {
            return true;
        }

        await PublishAsync(
            new CommandEvent(null, AgentStates.Error, ScannerUnavailableMessage(), ErrorCode: "ScannerUnavailable"),
            cancellationToken
        );

        return false;
    }

    private IResult ScannerUnavailable()
    {
        return Results.Json(new
        {
            message = ScannerUnavailableMessage(),
            scanner_available = sdk.ScannerAvailable,
            sdk_available = sdk.IsInitialized,
            missing_dependency = sdk.MissingDependency,
        }, statusCode: StatusCodes.Status503ServiceUnavailable);
    }

    private string ScannerUnavailableMessage()
    {
        return sdk.LastError
            ?? (sdk.MissingDependency is null
                ? "Fingerprint scanner is not ready. The agent will keep retrying automatically while the scanner is disconnected."
                : $"Fingerprint scanner dependency is missing: {sdk.MissingDependency}.");
    }

    private void ClearActive()
    {
        lock (gate)
        {
            activeCommand = null;
            pendingAttendanceMatch = null;
            pendingEnrollmentTemplate = null;
            enrollmentTemplates.Clear();
            enrollmentPreviewImages.Clear();
            lastEnrollmentAcceptedAt = DateTimeOffset.MinValue;
        }
    }

    private static string NewCommandId(string prefix) => $"{prefix}-{Guid.NewGuid():N}";

    private async Task RefreshMatcherTemplatesAsync(CancellationToken cancellationToken)
    {
        var manifest = await laravel.ManifestAsync(cancellationToken);
        var templates = await laravel.AllTemplatesAsync(cancellationToken);

        await cache.RebuildTemplatesAsync(templates, manifest.Revision, cancellationToken);
        sdk.ReloadMatcher(await cache.LoadTemplatesAsync(cancellationToken));
    }

    private CommandEvent Event(string commandId, string state, string message, StartEnrollRequest employee, string? fingerprintImage = null)
    {
        string[] previewImages;

        lock (gate)
        {
            previewImages = enrollmentPreviewImages.ToArray();
        }

        return new CommandEvent(
            commandId,
            state,
            message,
            employee.EmployeeDatabaseId,
            employee.EmployeeCode,
            employee.Name,
            employee.FirstName,
            employee.Branch,
            employee.IsBirthday,
            FingerprintImageBase64: fingerprintImage,
            EnrollmentScanImages: previewImages
        );
    }

    private static CommandEvent Event(string commandId, string state, string message, LocalFingerprintTemplate template, string? attendanceType, int score)
    {
        return new CommandEvent(
            commandId,
            state,
            message,
            template.EmployeeDatabaseId,
            template.EmployeeCode,
            template.EmployeeName,
            template.EmployeeFirstName,
            template.EmployeeBranch,
            template.IsBirthday,
            attendanceType,
            template.ServerTemplateId,
            score
        );
    }

    private static CommandEvent Event(string commandId, string state, string message)
    {
        return new CommandEvent(commandId, state, message);
    }
}

internal enum CommandKind
{
    Enrollment,
    Attendance,
    Unlock,
}

internal sealed class ActiveCommand
{
    private ActiveCommand(string commandId, CommandKind kind)
    {
        CommandId = commandId;
        Kind = kind;
    }

    public string CommandId { get; }
    public CommandKind Kind { get; }
    public StartEnrollRequest? Enrollment { get; private init; }
    public AttendanceCommandRequest? Attendance { get; private init; }
    public UnlockCommandRequest? Unlock { get; private init; }

    public static ActiveCommand CreateEnrollment(string commandId, StartEnrollRequest request)
    {
        return new ActiveCommand(commandId, CommandKind.Enrollment) { Enrollment = request };
    }

    public static ActiveCommand CreateAttendance(string commandId, AttendanceCommandRequest request)
    {
        return new ActiveCommand(commandId, CommandKind.Attendance) { Attendance = request };
    }

    public static ActiveCommand CreateUnlock(string commandId, UnlockCommandRequest request)
    {
        return new ActiveCommand(commandId, CommandKind.Unlock) { Unlock = request };
    }
}

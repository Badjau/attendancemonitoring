using System.Text.Json.Serialization;

namespace ZktecoLocalAgent;

public static class AgentStates
{
    public const string Idle = "idle";
    public const string Syncing = "syncing";
    public const string Ready = "ready";
    public const string WaitingForScan = "waiting_for_scan";
    public const string Captured = "captured";
    public const string Matched = "matched";
    public const string AwaitingBrowserPhoto = "awaiting_browser_photo";
    public const string Recording = "recording";
    public const string Success = "success";
    public const string Error = "error";
}

public sealed record EmployeeDto(
    [property: JsonPropertyName("id")] int Id,
    [property: JsonPropertyName("employee_id")] string EmployeeCode,
    [property: JsonPropertyName("name")] string? Name,
    [property: JsonPropertyName("first_name")] string? FirstName,
    [property: JsonPropertyName("last_name")] string? LastName,
    [property: JsonPropertyName("position")] string? Position,
    [property: JsonPropertyName("branch")] string? Branch,
    [property: JsonPropertyName("is_birthday")] bool IsBirthday
);

public sealed record FingerprintTemplateDto(
    [property: JsonPropertyName("id")] int Id,
    [property: JsonPropertyName("employee_id")] int EmployeeDatabaseId,
    [property: JsonPropertyName("employee_code")] string EmployeeCode,
    [property: JsonPropertyName("employee")] EmployeeDto? Employee,
    [property: JsonPropertyName("finger_index")] int FingerIndex,
    [property: JsonPropertyName("template_base64")] string TemplateBase64,
    [property: JsonPropertyName("template_hash")] string? TemplateHash,
    [property: JsonPropertyName("enrolled_at")] string? EnrolledAt,
    [property: JsonPropertyName("updated_at")] string? UpdatedAt
);

public sealed record ManifestDto(
    [property: JsonPropertyName("revision")] string Revision,
    [property: JsonPropertyName("count")] int Count,
    [property: JsonPropertyName("last_updated_at")] string? LastUpdatedAt
);

public sealed record PaginatedResponse<T>(
    [property: JsonPropertyName("data")] IReadOnlyList<T> Data,
    [property: JsonPropertyName("pagination")] PaginationDto Pagination
);

public sealed record PaginationDto(
    [property: JsonPropertyName("current_page")] int CurrentPage,
    [property: JsonPropertyName("total")] int Total,
    [property: JsonPropertyName("last_page")] int LastPage,
    [property: JsonPropertyName("per_page")] int PerPage
);

public sealed record ApiItemResponse<T>(
    [property: JsonPropertyName("message")] string? Message,
    [property: JsonPropertyName("data")] T? Data
);

public sealed record StartEnrollRequest(
    [property: JsonPropertyName("command_id")] string? CommandId,
    [property: JsonPropertyName("id")] int EmployeeDatabaseId,
    [property: JsonPropertyName("employee_id")] string? EmployeeCode,
    [property: JsonPropertyName("name")] string? Name,
    [property: JsonPropertyName("first_name")] string? FirstName,
    [property: JsonPropertyName("last_name")] string? LastName,
    [property: JsonPropertyName("position")] string? Position,
    [property: JsonPropertyName("branch")] string? Branch,
    [property: JsonPropertyName("is_birthday")] bool IsBirthday,
    [property: JsonPropertyName("finger_index")] int FingerIndex
);

public sealed record AttendanceCommandRequest(
    [property: JsonPropertyName("command_id")] string? CommandId,
    [property: JsonPropertyName("attendance_type")] string? AttendanceType,
    [property: JsonPropertyName("occurred_at")] string? OccurredAt,
    [property: JsonPropertyName("offline_id")] string? OfflineId,
    [property: JsonPropertyName("attendance_image")] string? AttendanceImage,
    [property: JsonPropertyName("location")] string? Location,
    [property: JsonPropertyName("location_source")] string? LocationSource,
    [property: JsonPropertyName("latitude")] double? Latitude,
    [property: JsonPropertyName("longitude")] double? Longitude
);

public sealed record UnlockCommandRequest([property: JsonPropertyName("command_id")] string? CommandId);
public sealed record CommandIdRequest([property: JsonPropertyName("command_id")] string? CommandId);

public sealed record CommandEvent(
    [property: JsonPropertyName("command_id")] string? CommandId,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("message")] string Message,
    [property: JsonPropertyName("employee_database_id")] int? EmployeeDatabaseId = null,
    [property: JsonPropertyName("employee_id")] string? EmployeeCode = null,
    [property: JsonPropertyName("employee_name")] string? EmployeeName = null,
    [property: JsonPropertyName("employee_first_name")] string? EmployeeFirstName = null,
    [property: JsonPropertyName("employee_branch")] string? EmployeeBranch = null,
    [property: JsonPropertyName("is_birthday")] bool IsBirthday = false,
    [property: JsonPropertyName("attendance_type")] string? AttendanceType = null,
    [property: JsonPropertyName("template_id")] int? TemplateId = null,
    [property: JsonPropertyName("score")] int? Score = null,
    [property: JsonPropertyName("fingerprint_image_base64")] string? FingerprintImageBase64 = null,
    [property: JsonPropertyName("enrollment_scan_images")] IReadOnlyList<string>? EnrollmentScanImages = null,
    [property: JsonPropertyName("error_code")] string? ErrorCode = null,
    [property: JsonPropertyName("created_at")] DateTimeOffset? CreatedAt = null
);

public sealed record HealthPayload(
    [property: JsonPropertyName("ok")] bool Ok,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("scanner_available")] bool ScannerAvailable,
    [property: JsonPropertyName("sdk_available")] bool SdkAvailable,
    [property: JsonPropertyName("missing_dependency")] string? MissingDependency,
    [property: JsonPropertyName("message")] string Message,
    [property: JsonPropertyName("revision")] string? Revision
);

public sealed record TemplateMatch(LocalFingerprintTemplate Template, int Score);

public sealed class LocalFingerprintTemplate
{
    public int ServerTemplateId { get; init; }
    public int EmployeeDatabaseId { get; init; }
    public string EmployeeCode { get; init; } = "";
    public int FingerIndex { get; init; }
    public string TemplateBase64 { get; init; } = "";
    public string TemplateHash { get; init; } = "";
    public string? EnrolledAt { get; init; }
    public string? UpdatedAt { get; init; }
    public string? EmployeeName { get; init; }
    public string? EmployeeFirstName { get; init; }
    public string? EmployeeBranch { get; init; }
    public bool IsBirthday { get; init; }
    public byte[] TemplateBytes { get; init; } = [];
}

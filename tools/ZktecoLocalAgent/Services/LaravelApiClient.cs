using System.Net.Http.Headers;
using System.Net.Http.Json;
using Microsoft.Extensions.Options;

namespace ZktecoLocalAgent.Services;

public sealed class LaravelApiClient
{
    private readonly HttpClient http;
    private readonly AgentOptions options;

    public LaravelApiClient(HttpClient http, IOptions<AgentOptions> options)
    {
        this.http = http;
        this.options = options.Value;
        this.http.BaseAddress = new Uri(this.options.ApiBaseUrl.TrimEnd('/') + "/");
        this.http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));

        if (!string.IsNullOrWhiteSpace(this.options.ScannerToken))
        {
            this.http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", this.options.ScannerToken);
        }
    }

    public async Task<ManifestDto> ManifestAsync(CancellationToken cancellationToken)
    {
        return await GetAsync<ManifestDto>("fingerprints/manifest", cancellationToken);
    }

    public async Task<IReadOnlyList<FingerprintTemplateDto>> AllTemplatesAsync(CancellationToken cancellationToken)
    {
        var page = 1;
        var templates = new List<FingerprintTemplateDto>();

        while (true)
        {
            var response = await GetAsync<PaginatedResponse<FingerprintTemplateDto>>($"fingerprints?page={page}", cancellationToken);
            templates.AddRange(response.Data);

            if (response.Pagination.CurrentPage >= response.Pagination.LastPage)
            {
                return templates;
            }

            page++;
        }
    }

    public async Task EnrollAsync(object payload, CancellationToken cancellationToken)
    {
        await PostAsync<object>("fingerprints/enroll", payload, cancellationToken);
    }

    public async Task<AttendanceDto?> RecordAttendanceAsync(object payload, CancellationToken cancellationToken)
    {
        var response = await PostAsync<AttendanceDto>("attendance", payload, cancellationToken);
        return response.Data;
    }

    private async Task<T> GetAsync<T>(string path, CancellationToken cancellationToken)
    {
        var response = await http.GetAsync(path, cancellationToken);
        return await ReadAsync<T>(response, cancellationToken);
    }

    private async Task<ApiItemResponse<T>> PostAsync<T>(string path, object payload, CancellationToken cancellationToken)
    {
        var response = await http.PostAsJsonAsync(path, payload, cancellationToken);
        return await ReadAsync<ApiItemResponse<T>>(response, cancellationToken);
    }

    private static async Task<T> ReadAsync<T>(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException(body);
        }

        return System.Text.Json.JsonSerializer.Deserialize<T>(
            body,
            new System.Text.Json.JsonSerializerOptions { PropertyNameCaseInsensitive = true }
        ) ?? throw new InvalidOperationException("Laravel API returned an empty response.");
    }
}

public sealed record AttendanceDto(
    [property: System.Text.Json.Serialization.JsonPropertyName("id")] int Id,
    [property: System.Text.Json.Serialization.JsonPropertyName("attendance_type")] string? AttendanceType,
    [property: System.Text.Json.Serialization.JsonPropertyName("employee")] EmployeeDto? Employee
);

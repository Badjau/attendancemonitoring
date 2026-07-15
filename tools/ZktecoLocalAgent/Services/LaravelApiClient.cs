using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using Microsoft.Extensions.Options;

namespace ZktecoLocalAgent.Services;

public sealed class LaravelApiClient
{
    private readonly HttpClient http;
    private readonly IOptionsMonitor<AgentOptions> options;
    private readonly object sync = new();
    private Uri? lastSuccessfulBaseUri;

    public LaravelApiClient(HttpClient http, IOptionsMonitor<AgentOptions> options)
    {
        this.http = http;
        this.options = options;
        this.http.Timeout = TimeSpan.FromSeconds(30);
        this.http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
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
        var response = await SendWithFailoverAsync(
            path,
            baseUri => new HttpRequestMessage(HttpMethod.Get, new Uri(baseUri, path)),
            cancellationToken
        );

        return await ReadAsync<T>(response, cancellationToken);
    }

    private async Task<ApiItemResponse<T>> PostAsync<T>(string path, object payload, CancellationToken cancellationToken)
    {
        var response = await SendWithFailoverAsync(
            path,
            baseUri =>
            {
                var request = new HttpRequestMessage(HttpMethod.Post, new Uri(baseUri, path));
                request.Content = JsonContent.Create(payload);
                return request;
            },
            cancellationToken
        );

        return await ReadAsync<ApiItemResponse<T>>(response, cancellationToken);
    }

    private async Task<HttpResponseMessage> SendWithFailoverAsync(
        string path,
        Func<Uri, HttpRequestMessage> requestFactory,
        CancellationToken cancellationToken)
    {
        var failures = new List<Exception>();

        foreach (var baseUri in CandidateBaseUris())
        {
            using var request = requestFactory(baseUri);
            ApplyAuthorization(request);

            try
            {
                var response = await http.SendAsync(request, cancellationToken);

                if (response.IsSuccessStatusCode)
                {
                    RememberSuccessfulBaseUri(baseUri);
                    return response;
                }

                if (!ShouldTryNextBaseUri(response))
                {
                    return response;
                }

                var body = await response.Content.ReadAsStringAsync(cancellationToken);
                failures.Add(new InvalidOperationException(
                    $"Laravel API request to {response.RequestMessage?.RequestUri} failed with {(int)response.StatusCode} {response.ReasonPhrase}: {SummarizeErrorBody(body)}"
                ));
                response.Dispose();
            }
            catch (HttpRequestException ex) when (!cancellationToken.IsCancellationRequested)
            {
                failures.Add(new InvalidOperationException($"{new Uri(baseUri, path)}: {ex.Message}", ex));
            }
            catch (TaskCanceledException ex) when (!cancellationToken.IsCancellationRequested)
            {
                failures.Add(new TimeoutException($"{new Uri(baseUri, path)} timed out after {http.Timeout.TotalSeconds:N0} seconds.", ex));
            }
        }

        throw new AggregateException("Unable to reach the Laravel fingerprint API using any configured URL.", failures);
    }

    private IReadOnlyList<Uri> CandidateBaseUris()
    {
        var configured = options.CurrentValue;
        var candidates = new List<string>();

        lock (sync)
        {
            if (lastSuccessfulBaseUri is not null)
            {
                candidates.Add(lastSuccessfulBaseUri.ToString());
            }
        }

        candidates.Add(configured.ApiBaseUrl);
        candidates.AddRange(configured.ApiFallbackBaseUrls ?? []);

        return candidates
            .Where(candidate => !string.IsNullOrWhiteSpace(candidate))
            .Select(candidate => candidate.Trim().TrimEnd('/') + "/")
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Select(candidate => Uri.TryCreate(candidate, UriKind.Absolute, out var uri) ? uri : null)
            .OfType<Uri>()
            .ToArray();
    }

    private void ApplyAuthorization(HttpRequestMessage request)
    {
        var token = options.CurrentValue.ScannerToken;

        if (!string.IsNullOrWhiteSpace(token))
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        }
    }

    private void RememberSuccessfulBaseUri(Uri baseUri)
    {
        lock (sync)
        {
            lastSuccessfulBaseUri = baseUri;
        }
    }

    private static bool ShouldTryNextBaseUri(HttpResponseMessage response)
    {
        return response.StatusCode is
            System.Net.HttpStatusCode.NotFound or
            System.Net.HttpStatusCode.BadGateway or
            System.Net.HttpStatusCode.ServiceUnavailable or
            System.Net.HttpStatusCode.GatewayTimeout;
    }

    private static async Task<T> ReadAsync<T>(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException(
                $"Laravel API request to {response.RequestMessage?.RequestUri} failed with {(int)response.StatusCode} {response.ReasonPhrase}: {SummarizeErrorBody(body)}"
            );
        }

        return JsonSerializer.Deserialize<T>(
            body,
            new JsonSerializerOptions { PropertyNameCaseInsensitive = true }
        ) ?? throw new InvalidOperationException("Laravel API returned an empty response.");
    }

    private static string SummarizeErrorBody(string body)
    {
        var trimmed = body.Trim();

        if (trimmed.StartsWith("<", StringComparison.Ordinal))
        {
            return "HTML error page returned. Check that ApiBaseUrl points to the Laravel /api/zkteco endpoint for the running app.";
        }

        return string.IsNullOrWhiteSpace(trimmed)
            ? "Empty response."
            : trimmed;
    }
}

public sealed record AttendanceDto(
    [property: System.Text.Json.Serialization.JsonPropertyName("id")] int Id,
    [property: System.Text.Json.Serialization.JsonPropertyName("attendance_type")] string? AttendanceType,
    [property: System.Text.Json.Serialization.JsonPropertyName("employee")] EmployeeDto? Employee
);

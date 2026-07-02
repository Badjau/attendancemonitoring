using System.Security.Cryptography;
using Microsoft.Data.Sqlite;

namespace ZktecoLocalAgent.Data;

public sealed class TemplateCache
{
    private readonly string connectionString;

    public TemplateCache(IHostEnvironment environment)
    {
        var dataDirectory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "ZktecoLocalAgent");

        Directory.CreateDirectory(dataDirectory);
        connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = Path.Combine(dataDirectory, "fingerprints.sqlite"),
        }.ToString();
    }

    public async Task InitializeAsync(CancellationToken cancellationToken)
    {
        await using var connection = new SqliteConnection(connectionString);
        await connection.OpenAsync(cancellationToken);

        var command = connection.CreateCommand();
        command.CommandText = """
            create table if not exists fingerprint_templates (
                server_template_id integer primary key,
                employee_db_id integer not null,
                employee_code text not null,
                finger_index integer not null,
                template_base64 text not null,
                template_hash text not null,
                enrolled_at text null,
                updated_at text null,
                employee_name text null,
                employee_first_name text null,
                employee_branch text null,
                is_birthday integer not null default 0
            );

            create table if not exists sync_state (
                id integer primary key check (id = 1),
                current_revision text null,
                last_sync_at text null,
                last_error text null
            );

            insert or ignore into sync_state (id, current_revision, last_sync_at, last_error)
            values (1, null, null, null);

            create table if not exists command_events (
                id integer primary key autoincrement,
                command_id text null,
                state text not null,
                message text not null,
                payload_json text not null,
                created_at text not null
            );
            """;
        await command.ExecuteNonQueryAsync(cancellationToken);
    }

    public async Task<string?> CurrentRevisionAsync(CancellationToken cancellationToken)
    {
        await using var connection = new SqliteConnection(connectionString);
        await connection.OpenAsync(cancellationToken);
        var command = connection.CreateCommand();
        command.CommandText = "select current_revision from sync_state where id = 1";

        return await command.ExecuteScalarAsync(cancellationToken) as string;
    }

    public async Task SetSyncSuccessAsync(string revision, CancellationToken cancellationToken)
    {
        await using var connection = new SqliteConnection(connectionString);
        await connection.OpenAsync(cancellationToken);
        var command = connection.CreateCommand();
        command.CommandText = """
            update sync_state
            set current_revision = $revision,
                last_sync_at = $syncedAt,
                last_error = null
            where id = 1
            """;
        command.Parameters.AddWithValue("$revision", revision);
        command.Parameters.AddWithValue("$syncedAt", DateTimeOffset.UtcNow.ToString("O"));
        await command.ExecuteNonQueryAsync(cancellationToken);
    }

    public async Task SetSyncErrorAsync(string error, CancellationToken cancellationToken)
    {
        await using var connection = new SqliteConnection(connectionString);
        await connection.OpenAsync(cancellationToken);
        var command = connection.CreateCommand();
        command.CommandText = "update sync_state set last_error = $error where id = 1";
        command.Parameters.AddWithValue("$error", error);
        await command.ExecuteNonQueryAsync(cancellationToken);
    }

    public async Task RebuildTemplatesAsync(
        IReadOnlyList<FingerprintTemplateDto> templates,
        string revision,
        CancellationToken cancellationToken)
    {
        await using var connection = new SqliteConnection(connectionString);
        await connection.OpenAsync(cancellationToken);
        await using var transaction = (SqliteTransaction)await connection.BeginTransactionAsync(cancellationToken);

        var delete = connection.CreateCommand();
        delete.Transaction = transaction;
        delete.CommandText = "delete from fingerprint_templates";
        await delete.ExecuteNonQueryAsync(cancellationToken);

        foreach (var template in templates)
        {
            var hash = template.TemplateHash;
            if (string.IsNullOrWhiteSpace(hash))
            {
                hash = Convert.ToHexString(SHA256.HashData(Convert.FromBase64String(template.TemplateBase64))).ToLowerInvariant();
            }

            var employeeCode = string.IsNullOrWhiteSpace(template.EmployeeCode)
                ? template.Employee?.EmployeeCode ?? ""
                : template.EmployeeCode;

            var insert = connection.CreateCommand();
            insert.Transaction = transaction;
            insert.CommandText = """
                insert into fingerprint_templates (
                    server_template_id, employee_db_id, employee_code, finger_index,
                    template_base64, template_hash, enrolled_at, updated_at,
                    employee_name, employee_first_name, employee_branch, is_birthday
                ) values (
                    $server_template_id, $employee_db_id, $employee_code, $finger_index,
                    $template_base64, $template_hash, $enrolled_at, $updated_at,
                    $employee_name, $employee_first_name, $employee_branch, $is_birthday
                )
                """;
            insert.Parameters.AddWithValue("$server_template_id", template.Id);
            insert.Parameters.AddWithValue("$employee_db_id", template.EmployeeDatabaseId);
            insert.Parameters.AddWithValue("$employee_code", employeeCode);
            insert.Parameters.AddWithValue("$finger_index", template.FingerIndex);
            insert.Parameters.AddWithValue("$template_base64", template.TemplateBase64);
            insert.Parameters.AddWithValue("$template_hash", hash);
            insert.Parameters.AddWithValue("$enrolled_at", (object?)template.EnrolledAt ?? DBNull.Value);
            insert.Parameters.AddWithValue("$updated_at", (object?)template.UpdatedAt ?? DBNull.Value);
            insert.Parameters.AddWithValue("$employee_name", (object?)template.Employee?.Name ?? DBNull.Value);
            insert.Parameters.AddWithValue("$employee_first_name", (object?)template.Employee?.FirstName ?? DBNull.Value);
            insert.Parameters.AddWithValue("$employee_branch", (object?)template.Employee?.Branch ?? DBNull.Value);
            insert.Parameters.AddWithValue("$is_birthday", template.Employee?.IsBirthday == true ? 1 : 0);
            await insert.ExecuteNonQueryAsync(cancellationToken);
        }

        var state = connection.CreateCommand();
        state.Transaction = transaction;
        state.CommandText = """
            update sync_state
            set current_revision = $revision,
                last_sync_at = $syncedAt,
                last_error = null
            where id = 1
            """;
        state.Parameters.AddWithValue("$revision", revision);
        state.Parameters.AddWithValue("$syncedAt", DateTimeOffset.UtcNow.ToString("O"));
        await state.ExecuteNonQueryAsync(cancellationToken);

        await transaction.CommitAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<LocalFingerprintTemplate>> LoadTemplatesAsync(CancellationToken cancellationToken)
    {
        var templates = new List<LocalFingerprintTemplate>();
        await using var connection = new SqliteConnection(connectionString);
        await connection.OpenAsync(cancellationToken);

        var command = connection.CreateCommand();
        command.CommandText = """
            select server_template_id, employee_db_id, employee_code, finger_index, template_base64,
                   template_hash, enrolled_at, updated_at, employee_name, employee_first_name,
                   employee_branch, is_birthday
            from fingerprint_templates
            order by server_template_id
            """;

        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            var templateBase64 = reader.GetString(4);
            byte[] bytes;
            try
            {
                bytes = Convert.FromBase64String(templateBase64);
            }
            catch
            {
                continue;
            }

            templates.Add(new LocalFingerprintTemplate
            {
                ServerTemplateId = reader.GetInt32(0),
                EmployeeDatabaseId = reader.GetInt32(1),
                EmployeeCode = reader.GetString(2),
                FingerIndex = reader.GetInt32(3),
                TemplateBase64 = templateBase64,
                TemplateHash = reader.GetString(5),
                EnrolledAt = reader.IsDBNull(6) ? null : reader.GetString(6),
                UpdatedAt = reader.IsDBNull(7) ? null : reader.GetString(7),
                EmployeeName = reader.IsDBNull(8) ? null : reader.GetString(8),
                EmployeeFirstName = reader.IsDBNull(9) ? null : reader.GetString(9),
                EmployeeBranch = reader.IsDBNull(10) ? null : reader.GetString(10),
                IsBirthday = reader.GetInt32(11) == 1,
                TemplateBytes = bytes,
            });
        }

        return templates;
    }

    public async Task AppendEventAsync(CommandEvent commandEvent, string payloadJson, CancellationToken cancellationToken)
    {
        await using var connection = new SqliteConnection(connectionString);
        await connection.OpenAsync(cancellationToken);

        var insert = connection.CreateCommand();
        insert.CommandText = """
            insert into command_events (command_id, state, message, payload_json, created_at)
            values ($command_id, $state, $message, $payload_json, $created_at)
            """;
        insert.Parameters.AddWithValue("$command_id", (object?)commandEvent.CommandId ?? DBNull.Value);
        insert.Parameters.AddWithValue("$state", commandEvent.State);
        insert.Parameters.AddWithValue("$message", commandEvent.Message);
        insert.Parameters.AddWithValue("$payload_json", payloadJson);
        insert.Parameters.AddWithValue("$created_at", DateTimeOffset.UtcNow.ToString("O"));
        await insert.ExecuteNonQueryAsync(cancellationToken);

        var prune = connection.CreateCommand();
        prune.CommandText = """
            delete from command_events
            where id not in (select id from command_events order by id desc limit 500)
            """;
        await prune.ExecuteNonQueryAsync(cancellationToken);
    }
}

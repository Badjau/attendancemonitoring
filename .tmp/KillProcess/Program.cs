using System.Diagnostics;
foreach (var arg in args)
{
    if (!int.TryParse(arg, out var pid)) continue;
    try
    {
        var process = Process.GetProcessById(pid);
        process.Kill(entireProcessTree: true);
        process.WaitForExit(5000);
        Console.WriteLine($"stopped {pid}");
    }
    catch (Exception ex)
    {
        Console.WriteLine($"{pid}: {ex.GetType().Name}: {ex.Message}");
    }
}

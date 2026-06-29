using System;
using System.Configuration;
using System.Net.Http;
using System.Text;
using System.Windows.Forms;

namespace ZKTecoBridge
{
    internal static class Program
    {
        [STAThread]
        private static void Main(string[] args)
        {
            if (ForwardLaunchCommandToRunningBridge(args))
            {
                return;
            }

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            Application.Run(new MainForm(args));
        }

        private static bool ForwardLaunchCommandToRunningBridge(string[] args)
        {
            if (args == null || args.Length == 0 || string.IsNullOrWhiteSpace(args[0]))
            {
                return false;
            }

            Uri launchUri;
            if (!Uri.TryCreate(args[0], UriKind.Absolute, out launchUri) || launchUri.Scheme != "zkteco-bridge")
            {
                return false;
            }

            string endpoint = launchUri.Host;
            if (endpoint != "enroll" && endpoint != "attendance" && endpoint != "unlock")
            {
                return false;
            }

            string payload = ExtractPayload(launchUri);
            if (string.IsNullOrWhiteSpace(payload))
            {
                return false;
            }

            string bridgeBaseUrl = LocalBridgeBaseUrl();

            try
            {
                using (var client = new HttpClient { Timeout = TimeSpan.FromSeconds(3) })
                using (var content = new StringContent(payload, Encoding.UTF8, "application/json"))
                {
                    var response = client.PostAsync(bridgeBaseUrl + endpoint, content).GetAwaiter().GetResult();
                    return response.IsSuccessStatusCode;
                }
            }
            catch
            {
                return false;
            }
        }

        private static string ExtractPayload(Uri launchUri)
        {
            string query = launchUri.Query.TrimStart('?');
            foreach (string pair in query.Split('&'))
            {
                string[] parts = pair.Split(new[] { '=' }, 2);
                if (parts.Length == 2 && parts[0] == "payload")
                {
                    return Uri.UnescapeDataString(parts[1]);
                }
            }

            return null;
        }

        private static string LocalBridgeBaseUrl()
        {
            string configuredUrl = ConfigurationManager.AppSettings["LocalBridgeUrl"] ?? "http://127.0.0.1:8765/";
            Uri uri;

            if (!Uri.TryCreate(configuredUrl, UriKind.Absolute, out uri))
            {
                return "http://127.0.0.1:8765/";
            }

            string host = uri.Host;
            if (host == "0.0.0.0" || host == "*" || host == "+")
            {
                host = "127.0.0.1";
            }

            return uri.Scheme + "://" + host + ":" + uri.Port + "/";
        }
    }
}

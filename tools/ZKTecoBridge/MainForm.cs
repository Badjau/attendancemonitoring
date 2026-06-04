using System;
using System.Collections.Generic;
using System.Configuration;
using System.Drawing;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using System.Web.Script.Serialization;
using System.Windows.Forms;
using libzkfpcsharp;
using Sample;

namespace ZKTecoBridge
{
    public class MainForm : Form
    {
        private const int MessageCapturedOk = 0x0400 + 6;
        private const int RegisterFingerCount = 3;

        private readonly HttpClient httpClient = new HttpClient();
        private readonly JavaScriptSerializer json = new JavaScriptSerializer { MaxJsonLength = int.MaxValue };
        private readonly Dictionary<int, FingerprintTemplateDto> loadedTemplates = new Dictionary<int, FingerprintTemplateDto>();
        private readonly byte[][] registerTemplates = new byte[RegisterFingerCount][];
        private readonly byte[] registeredTemplate = new byte[2048];
        private readonly byte[] capturedTemplate = new byte[2048];

        private IntPtr deviceHandle = IntPtr.Zero;
        private IntPtr dbHandle = IntPtr.Zero;
        private byte[] fingerprintBuffer;
        private int captureTemplateSize = 2048;
        private int registeredTemplateSize;
        private int registerCount;
        private int fingerprintWidth;
        private int fingerprintHeight;
        private bool shouldStopCapture = true;
        private bool isEnrolling;
        private Thread captureThread;
        private Thread localServerThread;
        private bool shouldStopLocalServer;
        private readonly EmployeeDto launchEnrollmentEmployee;
        private readonly ZktecoAttendanceCommand launchAttendanceCommand;
        private EmployeeDto pendingEnrollmentEmployee;
        private PendingEnrollmentPayload pendingEnrollmentPayload;
        private ZktecoAttendanceCommand pendingAttendanceCommand;
        private FingerprintTemplateDto pendingMatchedTemplate;
        private int pendingMatchedScore;
        private BridgeStatus bridgeStatus = new BridgeStatus { state = "idle", message = "Bridge ready." };
        private bool hideWindowAfterStartup;

        private PictureBox fingerprintImage;
        private TextBox searchText;
        private ComboBox employeeCombo;
        private Label employeeName;
        private Label statusLabel;
        private TextBox logText;
        private Button searchButton;
        private Button enrollButton;
        private Button scanAgainButton;
        private Button submitButton;
        private Button syncButton;
        private Button stopButton;

        [DllImport("user32.dll", EntryPoint = "SendMessageA")]
        public static extern int SendMessage(IntPtr hwnd, int wMsg, IntPtr wParam, IntPtr lParam);

        public MainForm(string[] args)
        {
            Text = "Fingerprint Bridge";
            Width = 940;
            Height = 640;
            MinimumSize = new Size(940, 640);
            StartPosition = FormStartPosition.CenterScreen;
            launchEnrollmentEmployee = ParseLaunchEnrollmentEmployee(args);
            launchAttendanceCommand = ParseLaunchAttendanceCommand(args);
            hideWindowAfterStartup = launchAttendanceCommand != null && launchEnrollmentEmployee == null;

            if (hideWindowAfterStartup)
            {
                ShowInTaskbar = false;
                WindowState = FormWindowState.Minimized;
            }

            BuildUi();
            ConfigureApiClient();
        }

        protected override async void OnLoad(EventArgs e)
        {
            base.OnLoad(e);
            StartLocalCommandServer();

            for (int i = 0; i < registerTemplates.Length; i++)
            {
                registerTemplates[i] = new byte[2048];
            }

            await RunAsync(InitializeScanner);
            await SyncTemplatesAsync();

            if (launchEnrollmentEmployee != null)
            {
                BeginEnrollmentFor(launchEnrollmentEmployee);
            }

            if (launchAttendanceCommand != null)
            {
                BeginAttendanceScan(launchAttendanceCommand);
            }

            if (hideWindowAfterStartup)
            {
                Hide();
            }
        }

        protected override void OnFormClosing(FormClosingEventArgs e)
        {
            StopLocalCommandServer();
            StopScanner();
            httpClient.Dispose();
            base.OnFormClosing(e);
        }

        protected override void DefWndProc(ref Message m)
        {
            if (m.Msg == MessageCapturedOk)
            {
                HandleCapturedFingerprint();
                return;
            }

            base.DefWndProc(ref m);
        }

        private void BuildUi()
        {
            var main = new TableLayoutPanel
            {
                Dock = DockStyle.Fill,
                ColumnCount = 2,
                RowCount = 1,
                Padding = new Padding(18),
            };
            main.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 360));
            main.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));

            var left = new TableLayoutPanel { Dock = DockStyle.Fill, RowCount = 3 };
            left.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            left.RowStyles.Add(new RowStyle(SizeType.Absolute, 320));
            left.RowStyles.Add(new RowStyle(SizeType.AutoSize));

            left.Controls.Add(new Label
            {
                Text = "Place your finger on the scanner",
                AutoSize = true,
                Font = new Font(Font.FontFamily, 11, FontStyle.Bold),
                Margin = new Padding(0, 0, 0, 10),
            }, 0, 0);

            fingerprintImage = new PictureBox
            {
                Dock = DockStyle.Fill,
                BackColor = Color.White,
                BorderStyle = BorderStyle.FixedSingle,
                SizeMode = PictureBoxSizeMode.Zoom,
            };
            left.Controls.Add(fingerprintImage, 0, 1);

            statusLabel = new Label
            {
                Text = "Starting scanner...",
                AutoSize = true,
                Margin = new Padding(0, 10, 0, 0),
            };
            left.Controls.Add(statusLabel, 0, 2);

            var right = new TableLayoutPanel { Dock = DockStyle.Fill, RowCount = 6, Padding = new Padding(24, 0, 0, 0) };
            right.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            right.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            right.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            right.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            right.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            right.RowStyles.Add(new RowStyle(SizeType.Percent, 100));

            right.Controls.Add(new Label
            {
                Text = "Search Employee",
                AutoSize = true,
                Font = new Font(Font.FontFamily, 11, FontStyle.Bold),
                Margin = new Padding(0, 0, 0, 10),
            }, 0, 0);

            var searchPanel = new FlowLayoutPanel { Dock = DockStyle.Top, AutoSize = true };
            searchText = new TextBox { Width = 260 };
            searchButton = new Button { Text = "Search", Width = 90 };
            searchButton.Click += async (sender, args) => await SearchEmployeesAsync();
            searchPanel.Controls.Add(searchText);
            searchPanel.Controls.Add(searchButton);
            right.Controls.Add(searchPanel, 0, 1);

            employeeCombo = new ComboBox { Dock = DockStyle.Top, DropDownStyle = ComboBoxStyle.DropDownList, Margin = new Padding(0, 12, 0, 0) };
            employeeCombo.SelectedIndexChanged += (sender, args) =>
            {
                var employee = employeeCombo.SelectedItem as EmployeeDto;
                employeeName.Text = employee == null ? "" : employee.name;
            };
            right.Controls.Add(employeeCombo, 0, 2);

            employeeName = new Label { AutoSize = true, Margin = new Padding(0, 8, 0, 12) };
            right.Controls.Add(employeeName, 0, 3);

            var buttons = new FlowLayoutPanel { Dock = DockStyle.Top, AutoSize = true };
            syncButton = new Button { Text = "Sync", Width = 90 };
            enrollButton = new Button { Text = "Enroll", Width = 90 };
            scanAgainButton = new Button { Text = "Scan Again", Width = 100, Enabled = false };
            submitButton = new Button { Text = "Submit", Width = 90, Enabled = false };
            stopButton = new Button { Text = "Stop", Width = 90 };
            syncButton.Click += async (sender, args) => await SyncTemplatesAsync();
            enrollButton.Click += (sender, args) => BeginEnrollment();
            scanAgainButton.Click += (sender, args) => BeginEnrollment();
            submitButton.Click += async (sender, args) => await ConfirmPendingEnrollmentAsync();
            stopButton.Click += (sender, args) => StopScanner();
            buttons.Controls.Add(syncButton);
            buttons.Controls.Add(enrollButton);
            buttons.Controls.Add(scanAgainButton);
            buttons.Controls.Add(submitButton);
            buttons.Controls.Add(stopButton);
            right.Controls.Add(buttons, 0, 4);

            logText = new TextBox
            {
                Dock = DockStyle.Bottom,
                Multiline = true,
                Height = 190,
                ScrollBars = ScrollBars.Vertical,
                ReadOnly = true,
            };
            right.Controls.Add(logText, 0, 5);

            main.Controls.Add(left, 0, 0);
            main.Controls.Add(right, 1, 0);
            Controls.Add(main);
        }

        private void ConfigureApiClient()
        {
            ServicePointManager.ServerCertificateValidationCallback += (sender, certificate, chain, sslPolicyErrors) => true;

            var baseUrl = ConfigurationManager.AppSettings["ApiBaseUrl"];
            var token = ConfigurationManager.AppSettings["ScannerToken"];

            if (string.IsNullOrWhiteSpace(baseUrl))
            {
                baseUrl = "http://timeclock-system.test/api/zkteco";
            }

            httpClient.BaseAddress = new Uri(baseUrl.TrimEnd('/') + "/");

            if (!string.IsNullOrWhiteSpace(token))
            {
                httpClient.DefaultRequestHeaders.Authorization =
                    new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", token);
            }
        }

        private void InitializeScanner()
        {
            int ret = zkfp2.Init();
            if (ret != zkfperrdef.ZKFP_ERR_OK)
            {
                throw new InvalidOperationException("Initialize failed, ret=" + ret);
            }

            int deviceCount = zkfp2.GetDeviceCount();
            if (deviceCount <= 0)
            {
                zkfp2.Terminate();
                throw new InvalidOperationException("No fingerprint scanner connected.");
            }

            deviceHandle = zkfp2.OpenDevice(0);
            if (deviceHandle == IntPtr.Zero)
            {
                throw new InvalidOperationException("OpenDevice failed.");
            }

            dbHandle = zkfp2.DBInit();
            if (dbHandle == IntPtr.Zero)
            {
                zkfp2.CloseDevice(deviceHandle);
                deviceHandle = IntPtr.Zero;
                throw new InvalidOperationException("DBInit failed.");
            }

            var paramValue = new byte[4];
            int size = 4;
            zkfp2.GetParameters(deviceHandle, 1, paramValue, ref size);
            zkfp2.ByteArray2Int(paramValue, ref fingerprintWidth);

            size = 4;
            zkfp2.GetParameters(deviceHandle, 2, paramValue, ref size);
            zkfp2.ByteArray2Int(paramValue, ref fingerprintHeight);

            fingerprintBuffer = new byte[fingerprintWidth * fingerprintHeight];
            shouldStopCapture = false;
            captureThread = new Thread(DoCapture) { IsBackground = true };
            captureThread.Start();

            SetStatus("Scanner ready: " + fingerprintWidth + "x" + fingerprintHeight);
        }

        private void DoCapture()
        {
            while (!shouldStopCapture)
            {
                captureTemplateSize = 2048;
                int ret = zkfp2.AcquireFingerprint(deviceHandle, fingerprintBuffer, capturedTemplate, ref captureTemplateSize);
                if (ret == zkfp.ZKFP_ERR_OK)
                {
                    SendMessage(Handle, MessageCapturedOk, IntPtr.Zero, IntPtr.Zero);
                }

                Thread.Sleep(80);
            }
        }

        private async void HandleCapturedFingerprint()
        {
            try
            {
                ShowFingerprintImage();

                if (isEnrolling)
                {
                    await HandleEnrollmentCaptureAsync();
                    return;
                }

                await IdentifyAndRecordAttendanceAsync();
            }
            catch (Exception ex)
            {
                SetBridgeStatus("error", ex.Message);
                Log("Capture handling failed: " + ex.Message);
            }
        }

        private void ShowFingerprintImage()
        {
            var stream = new MemoryStream();
            BitmapFormat.GetBitmap(fingerprintBuffer, fingerprintWidth, fingerprintHeight, ref stream);
            stream.Position = 0;
            fingerprintImage.Image = new Bitmap(stream);
        }

        private async Task HandleEnrollmentCaptureAsync()
        {
            var employee = employeeCombo.SelectedItem as EmployeeDto;
            if (employee == null)
            {
                Log("Select an employee before enrolling.");
                isEnrolling = false;
                return;
            }

            if (registerCount > 0 && zkfp2.DBMatch(dbHandle, capturedTemplate, registerTemplates[registerCount - 1]) <= 0)
            {
                Log("Please scan the same finger for enrollment.");
                return;
            }

            Array.Copy(capturedTemplate, registerTemplates[registerCount], captureTemplateSize);
            registerCount++;

            if (registerCount < RegisterFingerCount)
            {
                SetStatus("Scan again: " + (RegisterFingerCount - registerCount) + " remaining");
                return;
            }

            registerCount = 0;
            registeredTemplateSize = 2048;

            int ret = zkfp2.DBMerge(dbHandle, registerTemplates[0], registerTemplates[1], registerTemplates[2], registeredTemplate, ref registeredTemplateSize);
            if (ret != zkfp.ZKFP_ERR_OK)
            {
                Log("Enroll failed, error code=" + ret);
                isEnrolling = false;
                return;
            }

            string templateBase64 = TemplateToBase64(registeredTemplate, registeredTemplateSize);
            string imageBase64 = FingerprintImageBase64();

            pendingEnrollmentEmployee = employee;
            pendingEnrollmentPayload = new PendingEnrollmentPayload
            {
                command_id = employee.command_id,
                employee = employee,
                employee_id = employee.id,
                finger_index = employee.finger_index <= 0 ? 1 : employee.finger_index,
                template_base64 = templateBase64,
                template_size = registeredTemplateSize,
                device_serial = DeviceSerial(),
                fingerprint_image_base64 = imageBase64,
            };

            isEnrolling = false;
            Log("Fingerprint captured for " + employee.employee_id + ". Waiting for web confirmation.");
            SetBridgeStatus("captured", "Fingerprint captured. Review and submit to save.", employee);
            SetStatus("Fingerprint captured. Click Submit to save or Scan Again to retry.");
            SetEnrollmentConfirmationEnabled(true);
        }

        private async Task ConfirmPendingEnrollmentAsync()
        {
            if (pendingEnrollmentPayload == null || pendingEnrollmentEmployee == null)
            {
                SetStatus("No captured fingerprint is waiting for confirmation.");
                return;
            }

            SetEnrollmentConfirmationEnabled(false);

            try
            {
                await SavePendingEnrollmentAsync();
                Hide();
            }
            catch (Exception ex)
            {
                Log("Unable to save fingerprint: " + ex.Message);
                SetBridgeStatus("error", "Unable to save fingerprint: " + ex.Message, pendingEnrollmentEmployee);
                SetStatus("Unable to save fingerprint.");
                SetEnrollmentConfirmationEnabled(true);
            }
        }

        private async Task SavePendingEnrollmentAsync()
        {
            if (pendingEnrollmentPayload == null || pendingEnrollmentEmployee == null)
            {
                throw new InvalidOperationException("No captured fingerprint is waiting for confirmation.");
            }

            await PostJsonAsync("fingerprints/enroll", new
            {
                employee_id = pendingEnrollmentPayload.employee_id,
                finger_index = pendingEnrollmentPayload.finger_index,
                template_base64 = pendingEnrollmentPayload.template_base64,
                template_size = pendingEnrollmentPayload.template_size,
                device_serial = pendingEnrollmentPayload.device_serial,
                fingerprint_image_base64 = pendingEnrollmentPayload.fingerprint_image_base64,
            });

            var confirmedEmployee = pendingEnrollmentEmployee;
            Log("Enrolled " + confirmedEmployee.employee_id + " successfully.");
            SetBridgeStatus("success", "Fingerprint successfully Registered!", confirmedEmployee);
            SetStatus("Fingerprint successfully registered.");
            await SyncTemplatesAsync();

            pendingEnrollmentPayload = null;
            pendingEnrollmentEmployee = null;
            SetEnrollmentConfirmationEnabled(false);
        }

        private async Task IdentifyAndRecordAttendanceAsync()
        {
            if (loadedTemplates.Count == 0)
            {
                SetStatus("No enrolled templates synced.");
                SetBridgeStatus("error", "No enrolled fingerprint templates synced.");
                return;
            }

            int fid = 0;
            int score = 0;
            int ret = zkfp2.DBIdentify(dbHandle, capturedTemplate, ref fid, ref score);
            if (ret != zkfp.ZKFP_ERR_OK || !loadedTemplates.ContainsKey(fid))
            {
                var fallbackMatch = FindBestTemplateMatch();

                if (fallbackMatch == null)
                {
                    SetStatus("Fingerprint not recognized.");
                    SetBridgeStatus("waiting", "Fingerprint not recognized. Scan again.");
                    return;
                }

                fid = fallbackMatch.id;
                score = fallbackMatch.score;
            }

            var template = loadedTemplates[fid];
            var command = pendingAttendanceCommand;

            if (command != null && string.IsNullOrWhiteSpace(command.attendance_image))
            {
                pendingMatchedTemplate = template;
                pendingMatchedScore = score;
                SetStatus("Matched " + template.employee.employee_id + ". Waiting for attendance photo.");
                SetBridgeStatus(
                    "matched",
                    "Fingerprint matched. Capturing attendance photo...",
                    template.employee,
                    command.attendance_type
                );
                return;
            }

            await RecordMatchedAttendanceAsync(template, score, command);
        }

        private async Task RecordMatchedAttendanceAsync(FingerprintTemplateDto template, int score, ZktecoAttendanceCommand command)
        {
            SetBridgeStatus("recording", "Recording fingerprint attendance...", template.employee, command == null ? null : command.attendance_type);

            var attendance = await PostJsonAsync<AttendanceDto>("attendance", new
            {
                employee_id = template.employee.id,
                template_id = template.id,
                score = score,
                device_serial = DeviceSerial(),
                attendance_type = command == null ? null : command.attendance_type,
                occurred_at = command == null ? null : command.occurred_at,
                offline_id = command == null ? null : command.offline_id,
                attendance_image = command == null ? null : command.attendance_image,
                location = command == null ? null : command.location,
                location_source = command == null ? null : command.location_source,
                latitude = command == null ? null : command.latitude,
                longitude = command == null ? null : command.longitude,
            });

            pendingAttendanceCommand = null;
            pendingMatchedTemplate = null;
            pendingMatchedScore = 0;

            SetStatus("Recorded " + attendance.employee.employee_id + " " + attendance.attendance_type);
            Log("Attendance recorded for " + attendance.employee.name + ", score=" + score);
            SetBridgeStatus(
                "success",
                "Attendance recorded for " + attendance.employee.name + ".",
                attendance.employee,
                attendance.attendance_type
            );
        }

        private async Task SearchEmployeesAsync()
        {
            string query = Uri.EscapeDataString(searchText.Text.Trim());
            var response = await GetJsonAsync<ApiListResponse<EmployeeDto>>("employees?search=" + query);
            employeeCombo.Items.Clear();

            foreach (var employee in response.data ?? new List<EmployeeDto>())
            {
                employeeCombo.Items.Add(employee);
            }

            if (employeeCombo.Items.Count > 0)
            {
                employeeCombo.SelectedIndex = 0;
            }

            Log("Loaded " + employeeCombo.Items.Count + " employee result(s).");
        }

        private async Task SyncTemplatesAsync()
        {
            if (dbHandle == IntPtr.Zero)
            {
                return;
            }

            loadedTemplates.Clear();
            zkfp2.DBClear(dbHandle);

            var response = await GetJsonAsync<ApiListResponse<FingerprintTemplateDto>>("fingerprints");
            foreach (var template in response.data ?? new List<FingerprintTemplateDto>())
            {
                byte[] blob = TemplateFromBase64(template.template_base64);
                if (blob == null || blob.Length == 0)
                {
                    Log("Skipped template #" + template.id + " because its base64 data is invalid.");
                    continue;
                }

                int ret = zkfp2.DBAdd(dbHandle, template.id, blob);
                if (ret == zkfp.ZKFP_ERR_OK)
                {
                    template.template_blob = blob;
                    loadedTemplates[template.id] = template;
                }
                else
                {
                    Log("Failed to load template #" + template.id + ", ret=" + ret);
                }
            }

            Log("Synced " + loadedTemplates.Count + " fingerprint template(s).");
        }

        private static string TemplateToBase64(byte[] template, int size)
        {
            var exactTemplate = new byte[size];
            Array.Copy(template, exactTemplate, size);

            return Convert.ToBase64String(exactTemplate);
        }

        private static byte[] TemplateFromBase64(string templateBase64)
        {
            if (string.IsNullOrWhiteSpace(templateBase64))
            {
                return null;
            }

            try
            {
                return Convert.FromBase64String(templateBase64.Trim());
            }
            catch
            {
                return null;
            }
        }

        private TemplateMatch FindBestTemplateMatch()
        {
            TemplateMatch bestMatch = null;

            foreach (var item in loadedTemplates)
            {
                if (item.Value.template_blob == null || item.Value.template_blob.Length == 0)
                {
                    continue;
                }

                int score = zkfp2.DBMatch(dbHandle, capturedTemplate, item.Value.template_blob);
                if (bestMatch == null || score > bestMatch.score)
                {
                    bestMatch = new TemplateMatch { id = item.Key, score = score };
                }
            }

            if (bestMatch != null)
            {
                Log("Best fingerprint match template #" + bestMatch.id + ", score=" + bestMatch.score);
            }

            return bestMatch != null && bestMatch.score > 0 ? bestMatch : null;
        }

        private void BeginEnrollment()
        {
            if (employeeCombo.SelectedItem == null)
            {
                Log("Search and select an employee first.");
                return;
            }

            registerCount = 0;
            isEnrolling = true;
            pendingEnrollmentPayload = null;
            SetEnrollmentConfirmationEnabled(false);
            SetBridgeStatus("waiting", "Waiting for fingerprint enrollment scans.");
            SetStatus("Scan the same finger 3 times.");
        }

        private void SetEnrollmentConfirmationEnabled(bool enabled)
        {
            if (scanAgainButton != null)
            {
                scanAgainButton.Enabled = enabled;
            }

            if (submitButton != null)
            {
                submitButton.Enabled = enabled;
            }
        }

        private void BeginAttendanceScan(ZktecoAttendanceCommand command)
        {
            if (InvokeRequired)
            {
                BeginInvoke(new Action<ZktecoAttendanceCommand>(BeginAttendanceScan), command);
                return;
            }

            pendingAttendanceCommand = command;
            isEnrolling = false;
            registerCount = 0;
            SetBridgeStatus("waiting", "Waiting for registered fingerprint scan.");
            SetStatus("Waiting for " + (command.attendance_type ?? "attendance") + " fingerprint scan.");
            Log("Attendance requested from web timeclock. Scan a registered finger.");
        }

        private void SetBridgeStatus(string state, string message, EmployeeDto employee = null, string attendanceType = null)
        {
            bridgeStatus = new BridgeStatus
            {
                command_id = pendingAttendanceCommand == null
                    ? (pendingEnrollmentEmployee == null ? bridgeStatus.command_id : pendingEnrollmentEmployee.command_id)
                    : pendingAttendanceCommand.command_id,
                state = state,
                message = message,
                employee_id = employee == null ? null : employee.employee_id,
                employee_name = employee == null ? null : employee.name,
                employee_first_name = employee == null ? null : employee.first_name,
                employee_branch = employee == null ? null : employee.branch,
                is_birthday = employee != null && employee.is_birthday,
                attendance_type = attendanceType,
            };
        }

        private void BeginEnrollmentFor(EmployeeDto employee)
        {
            if (InvokeRequired)
            {
                BeginInvoke(new Action<EmployeeDto>(BeginEnrollmentFor), employee);
                return;
            }

            ShowInTaskbar = true;
            Show();
            WindowState = FormWindowState.Normal;
            Activate();
            BringToFront();

            EmployeeDto existing = null;
            foreach (var item in employeeCombo.Items)
            {
                var candidate = item as EmployeeDto;
                if (candidate != null && candidate.id == employee.id)
                {
                    existing = candidate;
                    break;
                }
            }

            if (existing == null)
            {
                employeeCombo.Items.Add(employee);
                existing = employee;
            }
            else
            {
                existing.command_id = employee.command_id;
                existing.finger_index = employee.finger_index;
                existing.name = employee.name;
                existing.first_name = employee.first_name;
                existing.last_name = employee.last_name;
                existing.position = employee.position;
                existing.branch = employee.branch;
            }

            employeeCombo.SelectedItem = existing;
            BeginEnrollment();
            bridgeStatus.command_id = employee.command_id;
            Log("Enrollment requested from web admin for " + employee.employee_id + ", finger " + (employee.finger_index <= 0 ? 1 : employee.finger_index) + ".");
        }

        private void StartLocalCommandServer()
        {
            shouldStopLocalServer = false;
            localServerThread = new Thread(LocalCommandServerLoop) { IsBackground = true };
            localServerThread.Start();
        }

        private void StopLocalCommandServer()
        {
            shouldStopLocalServer = true;

            if (localServerThread != null && localServerThread.IsAlive)
            {
                localServerThread.Join(500);
            }
        }

        private void LocalCommandServerLoop()
        {
            int port = LocalBridgePort();

            TcpListener listener = null;
            try
            {
                listener = new TcpListener(IPAddress.Loopback, port);
                listener.Start();
                Log("Local web bridge listening on http://127.0.0.1:" + port + "/");
            }
            catch (Exception ex)
            {
                Log("Unable to start local web bridge: " + ex.Message);
                return;
            }

            try
            {
                while (!shouldStopLocalServer)
                {
                    if (!listener.Pending())
                    {
                        Thread.Sleep(200);
                        continue;
                    }

                    using (var client = listener.AcceptTcpClient())
                    {
                        HandleLocalCommand(client);
                    }
                }
            }
            finally
            {
                listener.Stop();
            }
        }

        private int LocalBridgePort()
        {
            string url = ConfigurationManager.AppSettings["LocalBridgeUrl"] ?? "http://127.0.0.1:8765/";
            Uri uri;
            if (Uri.TryCreate(url, UriKind.Absolute, out uri) && uri.Port > 0)
            {
                return uri.Port;
            }

            return 8765;
        }

        private void HandleLocalCommand(TcpClient client)
        {
            var stream = client.GetStream();
            string request = ReadHttpRequest(stream);
            string[] parts = request.Split(new[] { "\r\n\r\n" }, 2, StringSplitOptions.None);
            string header = parts.Length > 0 ? parts[0] : "";
            string body = parts.Length > 1 ? parts[1] : "";
            string firstLine = header.Split(new[] { "\r\n" }, StringSplitOptions.None)[0];
            string[] requestParts = firstLine.Split(' ');
            string method = requestParts.Length > 0 ? requestParts[0] : "";
            string path = requestParts.Length > 1 ? requestParts[1].Trim('/') : "";

            if (method == "OPTIONS")
            {
                WriteLocalJson(stream, 204, "{}");
                return;
            }

            if (method == "GET" && path == "status")
            {
                WriteLocalJson(stream, 200, json.Serialize(bridgeStatus));
                return;
            }

            if (method != "POST" || (path != "enroll" && path != "attendance" && path != "finalize-attendance" && path != "commit-enrollment"))
            {
                WriteLocalJson(stream, 404, "{\"message\":\"Endpoint not found.\"}");
                return;
            }

            if (path == "commit-enrollment")
            {
                var command = json.Deserialize<ZktecoEnrollmentCommitCommand>(body);

                if (pendingEnrollmentPayload == null || pendingEnrollmentEmployee == null)
                {
                    WriteLocalJson(stream, 422, "{\"message\":\"No captured fingerprint is waiting for confirmation.\"}");
                    return;
                }

                if (command == null ||
                    string.IsNullOrWhiteSpace(command.command_id) ||
                    !string.Equals(command.command_id, pendingEnrollmentPayload.command_id, StringComparison.Ordinal))
                {
                    WriteLocalJson(stream, 409, "{\"message\":\"Fingerprint command does not match the pending enrollment.\"}");
                    return;
                }

                SavePendingEnrollmentAsync().GetAwaiter().GetResult();
                Hide();
                WriteLocalJson(stream, 200, "{\"message\":\"Fingerprint successfully Registered!\"}");
                return;
            }

            if (path == "finalize-attendance")
            {
                var command = json.Deserialize<ZktecoAttendanceCommand>(body);

                if (command == null || string.IsNullOrWhiteSpace(command.attendance_image))
                {
                    WriteLocalJson(stream, 422, "{\"message\":\"Attendance photo is required.\"}");
                    return;
                }

                if (pendingAttendanceCommand == null || pendingMatchedTemplate == null)
                {
                    WriteLocalJson(stream, 422, "{\"message\":\"No matched fingerprint is waiting for a photo.\"}");
                    return;
                }

                if (!string.IsNullOrWhiteSpace(command.command_id) &&
                    !string.Equals(command.command_id, pendingAttendanceCommand.command_id, StringComparison.Ordinal))
                {
                    WriteLocalJson(stream, 409, "{\"message\":\"Fingerprint command does not match the pending scan.\"}");
                    return;
                }

                pendingAttendanceCommand.attendance_image = command.attendance_image;
                RecordMatchedAttendanceAsync(pendingMatchedTemplate, pendingMatchedScore, pendingAttendanceCommand).GetAwaiter().GetResult();
                WriteLocalJson(stream, 200, "{\"message\":\"Attendance recorded successfully.\"}");
                return;
            }

            if (path == "attendance")
            {
                var command = json.Deserialize<ZktecoAttendanceCommand>(body);

                if (command == null)
                {
                    WriteLocalJson(stream, 422, "{\"message\":\"Invalid attendance payload.\"}");
                    return;
                }

                BeginAttendanceScan(command);
                WriteLocalJson(stream, 200, "{\"message\":\"Fingerprint Scanner Bridge is ready. Scan a registered finger.\"}");
                return;
            }

            var employee = json.Deserialize<EmployeeDto>(body);

            if (employee == null || employee.id <= 0)
            {
                WriteLocalJson(stream, 422, "{\"message\":\"Invalid employee payload.\"}");
                return;
            }

            BeginEnrollmentFor(employee);
            WriteLocalJson(stream, 200, "{\"message\":\"Fingerprint Scanner Bridge is ready. Scan the same finger 3 times.\"}");
        }

        private EmployeeDto ParseLaunchEnrollmentEmployee(string[] args)
        {
            return ParseLaunchCommand<EmployeeDto>(args, "enroll");
        }

        private ZktecoAttendanceCommand ParseLaunchAttendanceCommand(string[] args)
        {
            return ParseLaunchCommand<ZktecoAttendanceCommand>(args, "attendance");
        }

        private T ParseLaunchCommand<T>(string[] args, string command) where T : class
        {
            if (args == null || args.Length == 0 || string.IsNullOrWhiteSpace(args[0]))
            {
                return null;
            }

            Uri uri;
            if (!Uri.TryCreate(args[0], UriKind.Absolute, out uri) || uri.Scheme != "zkteco-bridge")
            {
                return null;
            }

            if (!string.Equals(uri.Host, command, StringComparison.OrdinalIgnoreCase))
            {
                return null;
            }

            string query = uri.Query.TrimStart('?');
            foreach (string pair in query.Split('&'))
            {
                string[] parts = pair.Split(new[] { '=' }, 2);
                if (parts.Length != 2 || parts[0] != "payload")
                {
                    continue;
                }

                string payload = Uri.UnescapeDataString(parts[1]);
                return json.Deserialize<T>(payload);
            }

            return null;
        }

        private static string ReadHttpRequest(NetworkStream stream)
        {
            var buffer = new byte[4096];
            var data = new MemoryStream();
            int contentLength = 0;

            while (true)
            {
                int read = stream.Read(buffer, 0, buffer.Length);
                if (read <= 0)
                {
                    break;
                }

                data.Write(buffer, 0, read);
                string text = Encoding.UTF8.GetString(data.ToArray());
                int headerEnd = text.IndexOf("\r\n\r\n", StringComparison.Ordinal);

                if (headerEnd < 0)
                {
                    continue;
                }

                contentLength = ContentLength(text.Substring(0, headerEnd));
                long bodyLength = data.Length - headerEnd - 4;

                if (bodyLength >= contentLength)
                {
                    return text;
                }
            }

            return Encoding.UTF8.GetString(data.ToArray());
        }

        private static int ContentLength(string header)
        {
            foreach (string line in header.Split(new[] { "\r\n" }, StringSplitOptions.None))
            {
                if (!line.StartsWith("Content-Length:", StringComparison.OrdinalIgnoreCase))
                {
                    continue;
                }

                int length;
                if (int.TryParse(line.Substring("Content-Length:".Length).Trim(), out length))
                {
                    return length;
                }
            }

            return 0;
        }

        private static void WriteLocalJson(NetworkStream stream, int statusCode, string body)
        {
            string statusText = statusCode == 200 ? "OK" : statusCode == 204 ? "No Content" : statusCode == 404 ? "Not Found" : "Unprocessable Entity";
            byte[] bodyBytes = Encoding.UTF8.GetBytes(body);
            string header =
                "HTTP/1.1 " + statusCode + " " + statusText + "\r\n" +
                "Content-Type: application/json; charset=utf-8\r\n" +
                "Content-Length: " + bodyBytes.Length + "\r\n" +
                "Access-Control-Allow-Origin: *\r\n" +
                "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n" +
                "Access-Control-Allow-Headers: Content-Type, Accept\r\n" +
                "Connection: close\r\n\r\n";
            byte[] headerBytes = Encoding.UTF8.GetBytes(header);
            stream.Write(headerBytes, 0, headerBytes.Length);
            stream.Write(bodyBytes, 0, bodyBytes.Length);
        }

        private void StopScanner()
        {
            shouldStopCapture = true;
            isEnrolling = false;
            registerCount = 0;

            if (captureThread != null && captureThread.IsAlive)
            {
                captureThread.Join(1000);
            }

            if (deviceHandle != IntPtr.Zero)
            {
                zkfp2.CloseDevice(deviceHandle);
                deviceHandle = IntPtr.Zero;
            }

            if (dbHandle != IntPtr.Zero)
            {
                zkfp2.DBFree(dbHandle);
                dbHandle = IntPtr.Zero;
            }

            zkfp2.Terminate();
            SetStatus("Scanner stopped.");
        }

        private async Task<T> GetJsonAsync<T>(string path)
        {
            var response = await httpClient.GetAsync(path);
            string body = await response.Content.ReadAsStringAsync();

            if (!response.IsSuccessStatusCode)
            {
                throw new InvalidOperationException(body);
            }

            return json.Deserialize<T>(body);
        }

        private async Task PostJsonAsync(string path, object payload)
        {
            await PostJsonAsync<object>(path, payload);
        }

        private async Task<T> PostJsonAsync<T>(string path, object payload)
        {
            string body = json.Serialize(payload);
            var response = await httpClient.PostAsync(path, new StringContent(body, Encoding.UTF8, "application/json"));
            string responseBody = await response.Content.ReadAsStringAsync();

            if (!response.IsSuccessStatusCode)
            {
                throw new InvalidOperationException(responseBody);
            }

            if (typeof(T) == typeof(object))
            {
                return default(T);
            }

            var item = json.Deserialize<ApiItemResponse<T>>(responseBody);
            return item.data;
        }

        private async Task RunAsync(Action action)
        {
            try
            {
                await Task.Run(action);
            }
            catch (Exception ex)
            {
                Log(ex.Message);
                SetStatus("Error");
            }
        }

        private string FingerprintImageBase64()
        {
            if (fingerprintImage.Image == null)
            {
                return null;
            }

            using (var stream = new MemoryStream())
            {
                fingerprintImage.Image.Save(stream, System.Drawing.Imaging.ImageFormat.Png);
                return Convert.ToBase64String(stream.ToArray());
            }
        }

        private string DeviceSerial()
        {
            return ConfigurationManager.AppSettings["DeviceSerial"] ?? "ZKTECO-LOCAL";
        }

        private void SetStatus(string text)
        {
            if (InvokeRequired)
            {
                BeginInvoke(new Action<string>(SetStatus), text);
                return;
            }

            statusLabel.Text = text;
        }

        private void Log(string text)
        {
            if (InvokeRequired)
            {
                BeginInvoke(new Action<string>(Log), text);
                return;
            }

            logText.AppendText(DateTime.Now.ToString("HH:mm:ss") + "  " + text + Environment.NewLine);
        }
    }
}

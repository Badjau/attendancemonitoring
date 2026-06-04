using System.Collections.Generic;

namespace ZKTecoBridge
{
    public class ApiListResponse<T>
    {
        public List<T> data { get; set; }
    }

    public class ApiItemResponse<T>
    {
        public string message { get; set; }
        public T data { get; set; }
    }

    public class EmployeeDto
    {
        public string command_id { get; set; }
        public int finger_index { get; set; }
        public int id { get; set; }
        public string employee_id { get; set; }
        public string name { get; set; }
        public string first_name { get; set; }
        public string last_name { get; set; }
        public string position { get; set; }
        public string branch { get; set; }
        public bool is_birthday { get; set; }

        public override string ToString()
        {
            return employee_id + " - " + name;
        }
    }

    public class FingerprintTemplateDto
    {
        public int id { get; set; }
        public EmployeeDto employee { get; set; }
        public int finger_index { get; set; }
        public string template_base64 { get; set; }
        public byte[] template_blob { get; set; }
        public string template_format { get; set; }
        public int? template_size { get; set; }
        public string device_serial { get; set; }
        public string enrolled_at { get; set; }
    }

    public class AttendanceDto
    {
        public int id { get; set; }
        public string attendance_type { get; set; }
        public string attendance_date { get; set; }
        public string time_in { get; set; }
        public string time_out { get; set; }
        public EmployeeDto employee { get; set; }
    }

    public class ZktecoAttendanceCommand
    {
        public string command_id { get; set; }
        public string attendance_type { get; set; }
        public string occurred_at { get; set; }
        public string offline_id { get; set; }
        public string attendance_image { get; set; }
        public string location { get; set; }
        public string location_source { get; set; }
        public double? latitude { get; set; }
        public double? longitude { get; set; }
    }

    public class ZktecoEnrollmentCommitCommand
    {
        public string command_id { get; set; }
    }

    public class PendingEnrollmentPayload
    {
        public string command_id { get; set; }
        public EmployeeDto employee { get; set; }
        public int employee_id { get; set; }
        public int finger_index { get; set; }
        public string template_base64 { get; set; }
        public int template_size { get; set; }
        public string device_serial { get; set; }
        public string fingerprint_image_base64 { get; set; }
    }

    public class BridgeStatus
    {
        public string command_id { get; set; }
        public string state { get; set; }
        public string message { get; set; }
        public string employee_name { get; set; }
        public string employee_first_name { get; set; }
        public string employee_id { get; set; }
        public string employee_branch { get; set; }
        public bool is_birthday { get; set; }
        public string attendance_type { get; set; }
    }

    public class TemplateMatch
    {
        public int id { get; set; }
        public int score { get; set; }
    }
}

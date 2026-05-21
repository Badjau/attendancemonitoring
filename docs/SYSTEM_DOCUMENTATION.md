# Timeclock System Documentation

## Purpose

The Timeclock System is a web-based attendance management application for recording, validating, and monitoring employee time records. It is designed to support a shared timeclock station where authorized personnel first unlock the device, then employees can record attendance through supported identification methods such as RFID, keypad credentials, fingerprint/WebAuthn, and face-assisted flows.

The system centralizes attendance operations by connecting employee profiles, departments, attendance records, announcements, location zones, and administrative reporting in one application. It helps administrators maintain employee records, review daily attendance activity, monitor late arrivals, undertime, overtime, and location compliance, and keep an audit trail of timeclock access.

The application is intended to:

- Provide a controlled timeclock interface that requires authorized unlock access before attendance can be recorded.
- Record employee time-in and time-out events with the attendance method, timestamp, location data, and optional attendance image.
- Validate attendance locations against assigned geofenced zones, including strict zones where attendance outside the assigned area is rejected.
- Support offline attendance capture by accepting offline identifiers and preventing duplicate synced records.
- Track attendance conditions such as present, late, undertime, overtime, pending overtime, and total daily hours.
- Give administrators a Filament-based back office for managing employees, departments, attendance records, announcements, zones, authorized timeclock users, and unlock logs.
- Provide dashboard visibility into attendance activity, including clocked-in employees, late arrivals, pending overtime, recent records, and attendance trends.

In practical terms, the system serves as both the employee-facing timeclock and the administrator-facing attendance control center. Its main purpose is to make attendance recording accurate, auditable, location-aware, and easier to manage across employees and assigned work areas.

## Scope

The Timeclock System covers the core activities required to manage employee attendance from both the timeclock station and the administrative back office.

### Included in the System

- Employee profile management, including employee ID, RFID UID, department, position, password credential, birthday, profile image, face registration, and fingerprint/WebAuthn registration.
- Department management for organizing employees.
- Attendance recording for time-in and time-out events.
- Attendance verification through supported methods such as RFID, keypad credential, fingerprint/WebAuthn, and face-related workflows.
- Timeclock unlock control for restricting access to the attendance station.
- Audit logging for timeclock unlock activity, including unlock method, timestamp, IP address, user agent, and audit image.
- Attendance image capture for time-in and time-out records.
- Location capture using latitude, longitude, readable location, and location source.
- Geofence zone management for assigning employees to allowed work areas.
- Strict geofence validation for employees who must record attendance only within assigned zones.
- Offline attendance support using offline identifiers to avoid duplicate synced records.
- Attendance status tracking, including present, late, undertime, overtime, and pending overtime.
- Total daily hours calculation based on the first time-in and last time-out of the day.
- Announcement management and employee-facing announcement display.
- Administrative dashboards, tables, filters, and reports for monitoring attendance activity.
- Import and activity log support through the administrative panel.

### Outside the Current Scope

- Payroll computation and salary processing.
- Leave filing, leave approval, and leave balance management.
- Dynamic employee shift scheduling. The current attendance logic uses fixed time references for shift start and end.
- Multi-branch payroll rules or complex labor policy automation.
- Native mobile applications. The system is implemented as a web application.

## User Roles

The system is used by three main groups:

- **Employees** use the timeclock interface to record attendance through available identification methods.
- **Authorized timeclock users** unlock the shared timeclock station before employees can record attendance.
- **Administrators** manage employees, departments, attendance records, announcements, geofence zones, authorized users, unlock logs, and dashboard reports through the admin panel.

## Major System Capabilities

### Availability

The system is designed as a centralized web application that can be accessed by authorized users whenever the hosting server, database, and network are available. Attendance records, employee information, announcements, geofence zones, and administrative reports are stored centrally so the timeclock station and admin panel work from the same source of truth.

The application also supports offline attendance capture through offline identifiers. When attendance data is later submitted to the server, the system checks the offline identifier to prevent duplicate records from being created during synchronization.

### Target Deployment Environment

The system is built on Laravel and is intended for deployment on a PHP-supported web server environment. It can run in a local area network, office-hosted server, or web-hosted server depending on the organization's infrastructure requirements.

The current project is suitable for environments that support:

- PHP 8.2 or later.
- Laravel 12.
- A relational database supported by Laravel.
- Node.js and Vite for compiling front-end assets.
- Browser access for the timeclock interface and administrative panel.
- Optional device hardware such as camera, RFID reader, and fingerprint/WebAuthn-capable authenticator depending on the attendance methods deployed.

### Device Accessibility

The system is browser-based, so users access it through supported web browsers instead of a native desktop or mobile application. The employee-facing timeclock can be deployed on a shared workstation, kiosk device, tablet, or browser-capable terminal. Administrators access the Filament admin panel from a browser on a desktop or laptop.

Device capabilities affect which attendance methods can be used. Camera access is required for face-assisted flows and attendance image capture. Location services are required for live geofence validation. RFID attendance requires compatible RFID input hardware. Fingerprint attendance uses WebAuthn-compatible browser and device support.

### Technical Capability

The system provides the technical foundation for secure, auditable, and location-aware attendance processing. It supports controlled timeclock access, multiple employee verification methods, attendance image storage, geofence validation, offline record synchronization, and automated attendance calculations.

The system can:

- Authenticate timeclock unlock access before attendance recording is allowed.
- Record time-in and time-out events with method, timestamp, location, status, and media evidence.
- Validate attendance attempts against assigned geofence zones.
- Track late arrivals, undertime, overtime, pending overtime, and total daily hours.
- Manage employees, departments, announcements, zones, attendance records, authorized timeclock users, and unlock logs.
- Provide administrative dashboards and reports through the Filament admin panel.
- Maintain audit visibility through unlock logs and administrative activity logging.

## Major System Conditions

The following assumptions and constraints define the conditions under which the Timeclock System is expected to operate. These conditions may affect deployment choices, supported devices, implementation options, and day-to-day system use.

### Technology Stack Conditions

- The system must run on a PHP environment compatible with Laravel 12.
- The server environment must support PHP 8.2 or later.
- The system must use a Laravel-supported relational database.
- Front-end assets must be built using the project's Node.js and Vite toolchain.
- Administrative functionality must be delivered through the Filament admin panel.
- Media upload and storage must support employee profile images, attendance images, face registration images, and unlock audit images.

### Network and Availability Conditions

- The timeclock station and admin users must have network access to the application server for normal online operation.
- Centralized records depend on the availability of the server and database.
- Offline attendance capture is limited to records that can later be synchronized with the server.
- Offline synchronization depends on the presence of a unique offline identifier to prevent duplicate attendance records.

### Device and Browser Conditions

- The employee-facing timeclock requires a browser-capable device such as a kiosk, workstation, tablet, or terminal.
- Camera-based features require browser camera permission and compatible camera hardware.
- Location-based validation requires browser location permission and a device capable of providing latitude and longitude.
- RFID-based attendance requires compatible RFID input hardware configured to provide readable employee or card identifiers.
- Fingerprint/WebAuthn attendance requires a browser and device that support WebAuthn authentication.

### Security and Access Conditions

- The timeclock interface must be unlocked by an authorized timeclock user before attendance can be recorded.
- Administrative access requires authenticated admin users.
- Employee password credentials must be stored securely using Laravel hashing behavior.
- Unlock events must be logged with audit information to support accountability.
- Access to employee records, attendance records, zones, and reports must be limited to authorized administrative users.

### Attendance Policy Conditions

- Attendance calculations depend on the configured or implemented schedule rules.
- The current attendance logic uses fixed shift references for late, undertime, and overtime calculations unless schedule behavior is extended.
- Employees assigned to strict geofence zones must record attendance from inside the assigned zone.
- Attendance records require valid employee identification before they can be saved.
- Time-out records depend on an existing time-in record for the same employee and attendance date.

### Operational Conditions

- Employee records should be created and maintained before employees use the timeclock.
- RFID, password, face, or fingerprint credentials must be enrolled before the corresponding attendance method can be used reliably.
- Geofence zones must be configured and assigned to employees before strict location validation can be enforced.
- Administrators are responsible for reviewing attendance exceptions such as late arrivals, undertime, overtime, and pending overtime.

## System Interfaces

The Timeclock System is primarily a web-based application. Its interfaces are composed of browser-facing user interfaces, server-side application services, database storage, media storage, and device-dependent browser capabilities.

### User Interfaces

- **Employee Timeclock Interface**: Used by employees to record time-in and time-out attendance events. This interface supports attendance capture through available methods such as RFID, keypad credentials, fingerprint/WebAuthn, and face-assisted workflows.
- **Timeclock Unlock Interface**: Used by authorized timeclock users to unlock the shared timeclock station before attendance recording is allowed.
- **Administrative Panel**: A Filament-based back office used by administrators to manage employees, departments, attendance records, announcements, geofence zones, authorized users, unlock logs, and reports.
- **Announcement Display Interface**: Displays published announcements to users through the employee-facing side of the system.

### Application and Data Interfaces

- **Laravel Application Layer**: Handles routing, request validation, business rules, authentication, attendance processing, geofence validation, media handling, and administrative resource management.
- **Database Interface**: Stores system records such as users, employees, departments, attendances, announcements, zones, authorized timeclock users, unlock logs, WebAuthn credentials, imports, and activity logs.
- **Media Storage Interface**: Stores uploaded or captured media such as employee profile images, attendance images, face registration images, and timeclock unlock audit images.
- **Offline Attendance Synchronization Interface**: Accepts attendance records with offline identifiers and prevents duplicate records when offline attendance data is submitted to the server.

### Device and Browser Interfaces

- **Camera Interface**: Used for face-related workflows, attendance image capture, and unlock audit image capture. This depends on browser camera permission and available camera hardware.
- **Location Interface**: Uses browser-provided latitude and longitude for attendance location capture and geofence validation.
- **RFID Input Interface**: Supports RFID-based identification when compatible RFID hardware is attached to the timeclock device and configured to provide readable identifiers.
- **WebAuthn/Fingerprint Interface**: Supports fingerprint or platform authenticator verification through browser and device WebAuthn support.
- **Keyboard/Keypad Input Interface**: Supports credential entry for keypad-based employee verification and timeclock unlock access.

### External System Interfaces

The current system does not directly integrate with payroll, human resource information systems, leave management systems, banking systems, or third-party identity providers. Attendance data is maintained inside the application database and managed through the administrative panel.

Future integrations may include:

- Payroll systems for attendance-based payroll processing.
- HR systems for synchronizing employee records and department assignments.
- Leave management systems for reconciling attendance with approved absences.
- External reporting or business intelligence tools for advanced attendance analytics.
- Notification services for attendance alerts, overtime approvals, or announcement delivery.

### Interface Overview

```text
Employees / Authorized Users / Administrators
                |
                v
        Browser-Based Interfaces
                |
                v
        Laravel Application Layer
                |
        +-------+-------------------+------------------+
        |                           |                  |
        v                           v                  v
   Database Records            Media Storage      Device APIs
   Employees                   Profile Images     Camera
   Attendances                 Attendance Images  Location
   Departments                 Audit Images       RFID Input
   Announcements                                  WebAuthn
   Zones
   Unlock Logs
```

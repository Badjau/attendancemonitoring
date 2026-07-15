<?php

use Joaopaulolndev\FilamentGeneralSettings\Enums\TypeFieldEnum;
use Joaopaulolndev\FilamentGeneralSettings\Models\GeneralSetting;

return [
    'model' => GeneralSetting::class,
    'show_application_tab' => true,
    'show_logo_and_favicon' => false,
    'show_analytics_tab' => false,
    'show_seo_tab' => false,
    'show_email_tab' => false,
    'show_social_networks_tab' => false,
    'expiration_cache_config_time' => 60,
    'show_custom_tabs' => true,
    'custom_tabs' => [
        'more_configs' => [
            'label' => 'Attendance Flow',
            'icon' => 'heroicon-o-clock',
            'columns' => 1,
            'fields' => [
                'time_in_start' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Time In',
                    'placeholder' => '08:00',
                    'required' => true,
                    'rules' => ['required', 'date_format:H:i'],
                ],
                'time_out_start' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Time Out',
                    'placeholder' => '18:00',
                    'required' => true,
                    'rules' => ['required', 'date_format:H:i'],
                ],
                'duplicate_scan_window_seconds' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Duplicate Scan Window Seconds',
                    'placeholder' => '60',
                    'required' => true,
                    'rules' => ['required', 'integer', 'min:0', 'max:3600'],
                ],
                'face_capture_width_ratio' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Face Capture Width Ratio',
                    'placeholder' => '0.50',
                    'required' => true,
                    'rules' => ['required', 'numeric', 'min:0.25', 'max:1'],
                ],
                'face_capture_height_ratio' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Face Capture Height Ratio',
                    'placeholder' => '0.68',
                    'required' => true,
                    'rules' => ['required', 'numeric', 'min:0.25', 'max:1'],
                ],
                'show_face_attendance_button' => [
                    'type' => TypeFieldEnum::Boolean->value,
                    'label' => 'Show Facial Recognition Attendance Button',
                ],
                'show_scan_status_messages' => [
                    'type' => TypeFieldEnum::Boolean->value,
                    'label' => 'Show Home Scanner Status Messages',
                ],
                'scan_status_idle' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Scanner Idle Message',
                    'placeholder' => 'RFID and fingerprint scanners are listening.',
                    'required' => false,
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                'scan_status_rfid_not_recognized' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'RFID Not Recognized Message',
                    'placeholder' => 'RFID card not recognized.',
                    'required' => false,
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                'scan_status_fingerprint_waiting' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Fingerprint Waiting Message',
                    'placeholder' => 'Scan your registered finger on the scanner.',
                    'required' => false,
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                'scan_status_fingerprint_not_found' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Fingerprint Not Found Message',
                    'placeholder' => 'Fingerprint not found.',
                    'required' => false,
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                'scan_status_fingerprint_matched' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Fingerprint Matched Message',
                    'placeholder' => 'Fingerprint matched. Starting facial verification...',
                    'required' => false,
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                'scan_status_attendance_recorded' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Attendance Recorded Message',
                    'placeholder' => 'Attendance recorded successfully.',
                    'required' => false,
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
            ],
        ],
    ],
];

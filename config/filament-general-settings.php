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
                'show_face_attendance_button' => [
                    'type' => TypeFieldEnum::Boolean->value,
                    'label' => 'Show Facial Recognition Attendance Button',
                ],
            ],
        ],
    ],
];

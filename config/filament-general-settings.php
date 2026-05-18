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
            'label' => 'Time-In/Time-out',
            'icon' => 'heroicon-o-clock',
            'columns' => 1,
            'fields' => [
                'time_in_start' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Time-in Start',
                    'placeholder' => '00:00 (12:00 AM)',
                    'required' => true,
                    'rules' => ['required', 'date_format:H:i'],
                ],
                'time_in_end' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Time-in End',
                    'placeholder' => '16:00 (04:00 PM)',
                    'required' => true,
                    'rules' => ['required', 'date_format:H:i'],
                ],
                'time_out_start' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Time-out Start',
                    'placeholder' => '16:01 (04:01 PM)',
                    'required' => true,
                    'rules' => ['required', 'date_format:H:i'],
                ],
                'time_out_end' => [
                    'type' => TypeFieldEnum::Text->value,
                    'label' => 'Time-out End',
                    'placeholder' => '23:59 (11:59 PM)',
                    'required' => true,
                    'rules' => ['required', 'date_format:H:i'],
                ],
            ],
        ],
    ],
];

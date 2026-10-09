<?php

return [
    'metrics' => [
        'temperature' => 'Temperature',
        'humidity' => 'Humidity',
        'precipitation' => 'Rain',
        'wind' => 'Wind',
    ],
    'alerts' => [
        'critical_title' => 'Important Elver alert',
        'threshold_message' => ':label :operator :threshold :unit in :location.',
        'above' => 'exceeded',
        'below' => 'fell below',
        'operator_above' => 'Above',
        'operator_below' => 'Below',
        'comparison_above' => 'Above',
        'comparison_below' => 'Below',
    ],
    'recommendations' => [
        'stale' => [
            'title' => 'Review saved data',
            'message' => 'There is no connection right now. Use this forecast as a reference and confirm before important work.',
        ],
        'heat' => [
            'title' => 'High heat and humidity',
            'message' => 'Drink water, find shade, and avoid the heaviest work. Feels like: :temperature; humidity: :humidity%.',
            'notification' => 'Heat and humidity are high in {location}. Drink water and find shade.',
        ],
        'uv' => [
            'title' => 'High UV index',
            'message' => 'Use sun protection and avoid prolonged exposure. UV :uv.',
            'notification' => 'The UV index is high in {location}. Use sun protection and avoid prolonged exposure.',
        ],
        'rain' => [
            'title' => 'Rain is likely soon',
            'message' => 'Rain is likely in about :hours hour(s). Protect tools and plan your work.',
            'notification' => 'Rain is expected soon in {location}. Protect tools and plan your work.',
        ],
        'wind' => [
            'title' => 'Strong wind',
            'message' => 'Secure loose objects and avoid spraying while the wind is strong. Wind: :wind.',
            'notification' => 'Strong wind is expected in {location}. Secure loose objects and avoid spraying.',
        ],
        'temperature_rise' => [
            'title' => 'Temperature will rise quickly',
            'message' => 'It may rise :change in a few hours. Move work that is easier in cooler conditions earlier.',
        ],
        'temperature_drop' => [
            'title' => 'Temperature will drop quickly',
            'message' => 'It may drop :change in a few hours. Protect equipment and sensitive crops.',
        ],
        'temperature_change' => [
            'title' => 'Rapid weather change',
            'notification' => 'The temperature will change quickly in {location}. Review your plans for the next few hours.',
        ],
        'normal' => [
            'title' => 'Stable conditions',
            'message' => 'There is no major change that requires immediate action.',
        ],
    ],
];

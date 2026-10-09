<?php

return [
    'metrics' => [
        'temperature' => 'Temperatura',
        'humidity' => 'Humedad',
        'precipitation' => 'Lluvia',
        'wind' => 'Viento',
    ],
    'alerts' => [
        'critical_title' => 'Alerta importante de Elver',
        'threshold_message' => ':label :operator :threshold :unit en :location.',
        'above' => 'superó',
        'below' => 'bajó de',
        'operator_above' => 'Por encima de',
        'operator_below' => 'Por debajo de',
        'comparison_above' => 'Más de',
        'comparison_below' => 'Menos de',
    ],
    'recommendations' => [
        'stale' => [
            'title' => 'Revisa los datos guardados',
            'message' => 'No hay conexión ahora. Usa este pronóstico como referencia y confirma antes de una labor importante.',
        ],
        'heat' => [
            'title' => 'Calor y humedad altos',
            'message' => 'Toma agua, busca sombra y evita las labores más pesadas. Sensación: :temperature; humedad: :humidity%.',
            'notification' => 'Hace calor y hay humedad alta. Toma agua y busca sombra en {location}.',
        ],
        'uv' => [
            'title' => 'Índice UV alto',
            'message' => 'Usa protección solar y evita la exposición prolongada. UV :uv.',
            'notification' => 'El índice UV está alto en {location}. Usa protección solar y evita exposición prolongada.',
        ],
        'rain' => [
            'title' => 'Lluvia próxima',
            'message' => 'Hay lluvia probable en aproximadamente :hours hora(s). Protege herramientas y planifica la labor.',
            'notification' => 'Se espera lluvia pronto en {location}. Protege herramientas y planifica la labor.',
        ],
        'wind' => [
            'title' => 'Viento fuerte',
            'message' => 'Asegura objetos y evita fumigar mientras el viento siga fuerte. Viento: :wind.',
            'notification' => 'Hay viento fuerte en {location}. Asegura objetos y evita fumigar.',
        ],
        'temperature_rise' => [
            'title' => 'La temperatura subirá rápido',
            'message' => 'Puede subir :change en pocas horas. Adelanta las labores que requieran menos calor.',
        ],
        'temperature_drop' => [
            'title' => 'La temperatura bajará rápido',
            'message' => 'Puede bajar :change en pocas horas. Protege equipos y cultivos sensibles.',
        ],
        'temperature_change' => [
            'title' => 'Cambio rápido del clima',
            'notification' => 'La temperatura cambiará rápido en {location}. Revisa tus planes para las próximas horas.',
        ],
        'normal' => [
            'title' => 'Condiciones estables',
            'message' => 'No hay un cambio importante que requiera una acción inmediata.',
        ],
    ],
];

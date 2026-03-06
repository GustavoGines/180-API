<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shop Configuration
    |--------------------------------------------------------------------------
    |
    | Aquí puedes configurar las reglas de negocio de la pastelería.
    |
    */

    // Límite estándar de pedidos por día
    'default_daily_capacity' => 10,

    // Fechas especiales donde el límite de pedidos se amplía o reduce
    // Formato: 'YYYY-MM-DD' => capacidad
    'special_capacities' => [
        
        // --- SAN VALENTÍN ---
        '2026-02-13' => 30, // Sábado previo
        '2026-02-14' => 30, // Día de los enamorados

        // --- PASCUAS 2026 (Domingo 5 de abril) ---
        '2026-04-04' => 50, // Sábado Santo (Retiros previos)
        '2026-04-05' => 50, // Domingo de Pascua

        // --- DÍA DEL PADRE 2026 (3er domingo de junio - 21 de junio) ---
        '2026-06-20' => 50, // Sábado previo
        '2026-06-21' => 20, // Día del Padre

        // --- DÍA DEL AMIGO 2026 (Lunes 20 de julio) ---
        // Se venden muchas bandejas y cosas para compartir
        '2026-07-20' => 30,

        // --- DÍA DEL NIÑO / INFANCIAS 2026 (3er domingo de agosto - 16 de agosto) ---
        '2026-08-15' => 30, // Sábado previo
        '2026-08-16' => 30, // Día del Niño

        // --- DÍA DE LA MADRE 2026 (3er domingo de octubre - 18 de octubre) ---
        // ¡La fecha de mayor venta en el rubro!
        '2026-10-17' => 50, // Sábado previo a full
        '2026-10-18' => 50, // Domingo de la Madre (Capacidad máxima absoluta)

        // --- FIESTAS DE FIN DE AÑO 2026 ---
        '2026-12-22' => 50, // Retiros previos
        '2026-12-23' => 50, // Nochebuena (A tope)
        '2026-12-29' => 20, // Retiros previos
        '2026-12-30' => 20, // Fin de año

    ],
];
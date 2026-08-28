<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seeding del usuario Admin inicial
    |--------------------------------------------------------------------------
    |
    | Variables leídas en tiempo de deploy/seed. Nunca deben contener
    | credenciales reales en el repositorio. Al exponerse por config se
    | mantienen disponibles aunque `config:cache` esté activo (el seeder no
    | debe depender de env() directo).
    |
    */

    'admin_name' => env('SEED_ADMIN_NAME', 'Admin'),

    'admin_email' => env('SEED_ADMIN_EMAIL'),

    'admin_password' => env('SEED_ADMIN_PASSWORD'),
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default User Office Level
    |--------------------------------------------------------------------------
    |
    | This value determines which office level should be the default for
    | new user registrations. The value corresponds to the 'level' field
    | in the office_levels table (1=Kodam, 2=Korem, 3=Kodim, 4=Koramil).
    | This can also be configured via the is_default_user_level field.
    |
    */

    'default_user_level' => env('OFFICE_DEFAULT_USER_LEVEL', 4),

    /*
    |--------------------------------------------------------------------------
    | Allow Higher Level Office Selection
    |--------------------------------------------------------------------------
    |
    | When set to true, users can select offices from higher levels than
    | the default during registration. When false, only the default level
    | offices are shown in the registration form.
    |
    */

    'allow_higher_level_selection' => env('OFFICE_ALLOW_HIGHER_LEVEL', false),

    /*
    |--------------------------------------------------------------------------
    | Office Code Format
    |--------------------------------------------------------------------------
    |
    | Defines the format for office codes. This can be used for validation
    | or auto-generation of codes based on hierarchy.
    |
    */

    'code_format' => [
        'separator' => '-',
        'pad_length' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Office Hierarchy Labels
    |--------------------------------------------------------------------------
    |
    | Default labels for the office hierarchy. These will be used if no
    | custom office_levels are defined in the database.
    |
    */

    'default_hierarchy' => [
        1 => [
            'name' => 'Kodam',
            'description' => 'Komando Daerah Militer - tingkat provinsi',
        ],
        2 => [
            'name' => 'Korem',
            'description' => 'Komando Resort Militer - tingkat beberapa kabupaten/kota',
        ],
        3 => [
            'name' => 'Kodim',
            'description' => 'Komando Distrik Militer - tingkat kabupaten/kota',
        ],
        4 => [
            'name' => 'Koramil',
            'description' => 'Komando Rayon Militer - tingkat kecamatan',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Office Display Settings
    |--------------------------------------------------------------------------
    |
    | Configure how offices are displayed throughout the application.
    |
    */

    'display' => [
        'show_hierarchy_path' => true,
        'show_office_code' => true,
        'path_separator' => ' > ',
    ],

];

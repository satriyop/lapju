<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Permissions
    |--------------------------------------------------------------------------
    |
    | This file contains all available permissions in the application.
    | Permissions are organized by resource for better maintainability.
    |
    */

    'projects' => [
        'view_projects',
        'create_projects',
        'edit_projects',
        'delete_projects',
    ],

    'tasks' => [
        'view_tasks',
        'create_tasks',
        'edit_tasks',
        'delete_tasks',
    ],

    'reports' => [
        'view_reports',
        'edit_reports',
    ],

    'progress' => [
        'update_progress',
    ],

    'administration' => [
        'manage_users',
        'manage_roles',
        'manage_settings',
    ],

    /*
    |--------------------------------------------------------------------------
    | All Permissions (Flattened)
    |--------------------------------------------------------------------------
    |
    | This is a flattened array of all permissions for easy iteration.
    | This is automatically generated from the categories above.
    |
    */

    'all' => [
        // Projects
        'view_projects',
        'create_projects',
        'edit_projects',
        'delete_projects',

        // Tasks
        'view_tasks',
        'create_tasks',
        'edit_tasks',
        'delete_tasks',

        // Reports
        'view_reports',
        'edit_reports',

        // Progress
        'update_progress',

        // Administration
        'manage_users',
        'manage_roles',
        'manage_settings',
    ],
];

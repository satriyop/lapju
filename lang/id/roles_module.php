<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Roles and Permissions Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for roles and permissions.
    |
    */

    // Page Headers
    'roles_management' => 'Manajemen Peran',
    'manage_roles' => 'Kelola Peran',
    'create_role' => 'Buat Peran',
    'edit_role' => 'Edit Peran',
    'delete_role' => 'Hapus Peran',

    // Role Names (for display translation)
    'names' => [
        'admin' => 'Admin',
        'reporter' => 'Pelapor',
        'viewer' => 'Pengamat',
        'kodim_admin' => 'Admin Kodim',
        'koramil_admin' => 'Admin Koramil',
    ],

    // Role Descriptions
    'descriptions' => [
        'admin' => 'Administrator sistem penuh dengan semua izin',
        'reporter' => 'Dapat membuat proyek, melaporkan kemajuan dan memperbarui penyelesaian tugas di tingkat Koramil',
        'viewer' => 'Akses hanya-baca untuk melihat proyek dan laporan',
        'kodim_admin' => 'Dapat mengelola pengguna dan proyek di Koramil di bawah Kodim mereka',
        'koramil_admin' => 'Dapat mengelola pengguna dan proyek di Koramil mereka',
    ],

    // Table Headers
    'role_name' => 'Nama Peran',
    'description' => 'Deskripsi',
    'permissions' => 'Izin',
    'office_level' => 'Tingkat Kantor',
    'users_count' => 'Jumlah Pengguna',
    'is_system' => 'Sistem',
    'actions' => 'Aksi',

    // Form Labels
    'name' => 'Nama',
    'role_description' => 'Deskripsi Peran',
    'select_permissions' => 'Pilih Izin',
    'select_office_level' => 'Pilih Tingkat Kantor',
    'system_role' => 'Peran Sistem',

    // Permissions List
    'permission_names' => [
        'view_projects' => 'Lihat Proyek',
        'create_projects' => 'Buat Proyek',
        'edit_projects' => 'Edit Proyek',
        'delete_projects' => 'Hapus Proyek',
        'manage_users' => 'Kelola Pengguna',
        'view_reports' => 'Lihat Laporan',
        'manage_settings' => 'Kelola Pengaturan',
        'manage_roles' => 'Kelola Peran',
        'approve_users' => 'Setujui Pengguna',
        'track_progress' => 'Lacak Kemajuan',
        '*' => 'Semua Izin',
    ],

    // Office Levels
    'office_levels' => [
        'kodam' => 'Kodam',
        'korem' => 'Korem',
        'kodim' => 'Kodim',
        'koramil' => 'Koramil',
    ],

    // Actions
    'assign_role' => 'Tugaskan Peran',
    'remove_role' => 'Hapus Peran',
    'save_role' => 'Simpan Peran',
    'update_role' => 'Perbarui Peran',

    // Confirmation Messages
    'confirm_delete' => 'Apakah Anda yakin ingin menghapus peran ini?',
    'cannot_delete_system' => 'Peran sistem tidak dapat dihapus.',
    'cannot_delete_with_users' => 'Tidak dapat menghapus peran dengan pengguna yang ditugaskan.',

    // Success Messages
    'role_created' => 'Peran berhasil dibuat.',
    'role_updated' => 'Peran berhasil diperbarui.',
    'role_deleted' => 'Peran berhasil dihapus.',
    'role_assigned' => 'Peran berhasil ditugaskan.',
    'role_removed' => 'Peran berhasil dihapus.',

    // Error Messages
    'error_creating' => 'Terjadi kesalahan saat membuat peran.',
    'error_updating' => 'Terjadi kesalahan saat memperbarui peran.',
    'error_deleting' => 'Terjadi kesalahan saat menghapus peran.',

    // Validation Messages
    'name_required' => 'Nama peran wajib diisi.',
    'name_unique' => 'Nama peran sudah digunakan.',
    'permissions_required' => 'Setidaknya satu izin harus dipilih.',

    // Other
    'no_roles_found' => 'Tidak ada peran ditemukan.',
    'system_role_badge' => 'Sistem',
    'custom_role_badge' => 'Kustom',
];

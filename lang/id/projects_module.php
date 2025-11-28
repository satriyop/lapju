<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Projects Module Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used in the projects module.
    |
    */

    // Page Headers
    'title' => 'Proyek',
    'create' => 'Buat Proyek',
    'edit' => 'Edit Proyek',
    'delete' => 'Hapus Proyek',

    // Search and Filter
    'search_placeholder' => 'Cari proyek...',
    'no_projects_found' => 'Tidak ada proyek ditemukan.',

    // Table Headers
    'name' => 'Nama',
    'partner' => 'Mitra',
    'office' => 'Kantor',
    'location' => 'Lokasi',
    'status' => 'Status',
    'start_date' => 'Tanggal Mulai',
    'end_date' => 'Tanggal Selesai',
    'assigned_users' => 'Pengguna yang Ditugaskan',
    'actual_progress' => 'Kemajuan Aktual %',
    'actions' => 'Aksi',

    // Form Labels
    'project_name' => 'Nama Proyek',
    'description' => 'Deskripsi',
    'select_partner' => 'Pilih Mitra',
    'select_kodim' => 'Pilih Kodim',
    'select_koramil' => 'Pilih Koramil',
    'select_location' => 'Pilih Lokasi',
    'project_status' => 'Status Proyek',

    // Status Values
    'statuses' => [
        'planning' => 'Perencanaan',
        'active' => 'Aktif',
        'completed' => 'Selesai',
        'on_hold' => 'Ditunda',
    ],

    // Buttons and Actions
    'save_project' => 'Simpan Proyek',
    'update_project' => 'Perbarui Proyek',
    'delete_project' => 'Hapus Proyek',
    'edit_project' => 'Edit Proyek',

    // Confirmation Messages
    'confirm_delete' => 'Apakah Anda yakin ingin menghapus proyek ini?',
    'delete_warning' => 'Tindakan ini tidak dapat dibatalkan.',

    // Success/Error Messages
    'created_successfully' => 'Proyek berhasil dibuat.',
    'updated_successfully' => 'Proyek berhasil diperbarui.',
    'deleted_successfully' => 'Proyek berhasil dihapus.',
    'error_creating' => 'Terjadi kesalahan saat membuat proyek.',
    'error_updating' => 'Terjadi kesalahan saat memperbarui proyek.',
    'error_deleting' => 'Terjadi kesalahan saat menghapus proyek.',

    // Validation Messages
    'name_required' => 'Nama proyek wajib diisi.',
    'partner_required' => 'Mitra wajib dipilih.',
    'office_required' => 'Kantor wajib dipilih.',
    'location_required' => 'Lokasi wajib dipilih.',
    'start_date_required' => 'Tanggal mulai wajib diisi.',
    'end_date_after_start' => 'Tanggal selesai harus setelah tanggal mulai.',
    'start_date_constraint' => 'Tanggal mulai harus pada atau setelah :date (pengaturan proyek).',

    // Other Messages
    'none' => 'Tidak ada',
    'no_users_assigned' => 'Tidak ada pengguna yang ditugaskan',
    'project_details' => 'Detail Proyek',
    'assigned_to' => 'Ditugaskan ke',

    // Modal Headers
    'create_new_project' => 'Buat Proyek Baru',
    'edit_project_details' => 'Edit Detail Proyek',

    // Date Constraints
    'default_start_date_info' => 'Tanggal mulai default: :date',
    'default_end_date_info' => 'Tanggal selesai default: :date',
];

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | User Management Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used in the user management module.
    |
    */

    // Page Headers
    'user_management' => 'Manajemen Pengguna',
    'manage_description' => 'Kelola pendaftaran pengguna, persetujuan, dan penugasan proyek',
    'create_user' => 'Buat Pengguna',
    'edit_user' => 'Edit Pengguna',
    'delete_user' => 'Hapus Pengguna',

    // Search and Filter
    'search_placeholder' => 'Cari berdasarkan nama, telepon, atau NRP...',
    'filter_all' => 'Semua Pengguna',
    'filter_pending' => 'Menunggu Persetujuan',
    'filter_approved' => 'Disetujui',
    'filter_admin' => 'Admin',

    // Table Headers
    'user' => 'Pengguna',
    'nrp' => 'NRP',
    'role' => 'Peran',
    'office' => 'Kantor',
    'status' => 'Status',
    'projects' => 'Proyek',
    'actions' => 'Aksi',

    // Form Labels
    'full_name' => 'Nama Lengkap',
    'email_address' => 'Alamat Email',
    'phone_number' => 'Nomor Telepon',
    'nrp_id' => 'NRP (ID Karyawan)',
    'select_role' => 'Pilih Peran',
    'select_office' => 'Pilih Kantor',
    'password' => 'Kata Sandi',
    'confirm_password' => 'Konfirmasi Kata Sandi',
    'grant_admin' => 'Berikan hak admin',
    'pre_approve' => 'Setujui pengguna ini sebelumnya',

    // Status Values
    'pending' => 'Menunggu',
    'approved' => 'Disetujui',
    'admin' => 'Admin',
    'no_role' => 'Tidak ada peran',
    'no_office' => 'Tidak ada kantor',

    // Actions
    'approve' => 'Setujui',
    'reject' => 'Tolak',
    'assign_projects' => 'Tugaskan Proyek',
    'save_changes' => 'Simpan Perubahan',
    'save_assignments' => 'Simpan Penugasan',

    // Confirmation Messages
    'confirm_approve' => 'Setujui pengguna ini?',
    'confirm_reject' => 'Tolak dan hapus pengguna ini?',
    'confirm_delete' => 'Hapus pengguna ini?',

    // User Deletion with Reassignment
    'reassign_before_deletion' => 'Tugaskan Ulang Proyek Sebelum Penghapusan',
    'reassign_description' => 'Pengguna :name ditugaskan ke :count :projects. Harap tugaskan pengguna pengganti untuk setiap proyek sebelum penghapusan.',
    'reassign_and_delete' => 'Tugaskan Ulang & Hapus Pengguna',
    'assign_to' => 'Tugaskan ke:',
    'select_replacement' => 'Pilih pengguna pengganti',
    'reassignment_required' => 'Harap tugaskan pengguna pengganti untuk semua proyek.',

    // Success Messages
    'user_approved' => 'Pengguna berhasil disetujui.',
    'user_rejected' => 'Pengguna berhasil ditolak.',
    'user_created' => 'Pengguna berhasil dibuat.',
    'user_updated' => 'Pengguna berhasil diperbarui.',
    'user_deleted' => 'Pengguna berhasil dihapus.',
    'projects_assigned' => 'Proyek berhasil ditugaskan.',

    // Error Messages
    'error_approving' => 'Terjadi kesalahan saat menyetujui pengguna.',
    'error_rejecting' => 'Terjadi kesalahan saat menolak pengguna.',
    'error_creating' => 'Terjadi kesalahan saat membuat pengguna.',
    'error_updating' => 'Terjadi kesalahan saat memperbarui pengguna.',
    'error_deleting' => 'Terjadi kesalahan saat menghapus pengguna.',

    // Validation Messages
    'name_required' => 'Nama lengkap wajib diisi.',
    'email_required' => 'Alamat email wajib diisi.',
    'email_valid' => 'Alamat email harus valid.',
    'email_unique' => 'Email ini sudah terdaftar.',
    'phone_required' => 'Nomor telepon wajib diisi.',
    'phone_unique' => 'Nomor telepon ini sudah terdaftar.',
    'phone_format' => 'Nomor telepon harus dimulai dengan 08 dan berisi 10-13 digit.',
    'nrp_required' => 'NRP wajib diisi.',
    'nrp_unique' => 'NRP ini sudah terdaftar.',
    'role_required' => 'Peran wajib dipilih.',
    'office_required' => 'Kantor wajib dipilih.',
    'password_required' => 'Kata sandi wajib diisi.',
    'password_min' => 'Kata sandi minimal 8 karakter.',
    'password_confirmed' => 'Konfirmasi kata sandi tidak cocok.',
    'invalid_office' => 'Pilihan kantor tidak valid.',
    'role_office_mismatch' => 'Peran \':role\' memerlukan pengguna berada di tingkat kantor yang berbeda.',

    // Other
    'no_users_found' => 'Tidak ada pengguna ditemukan.',
    'pending_approval_badge' => ':count Menunggu Persetujuan',
    'project_count' => ':count proyek|:count proyek',
    'select_kodim_first' => 'Pilih Kodim terlebih dahulu',
    'select_role_first' => 'Pilih Peran terlebih dahulu',
    'none_work_at_kodim' => 'Tidak ada - Saya bekerja di tingkat Kodim',

    // Modal Titles
    'assign_projects_to' => 'Tugaskan Proyek',
    'create_new_user' => 'Buat Pengguna Baru',
    'edit_user_details' => 'Edit Pengguna',

    // Office Hierarchy
    'users_count' => ':count pengguna|:count pengguna',
    'pending_count' => ':count menunggu',
];

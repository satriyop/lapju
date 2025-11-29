<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add indexes to improve query performance on frequently accessed columns.
     * These indexes target the dashboard and progress calculations which
     * were causing high CPU usage in production.
     */
    public function up(): void
    {
        // Indexes for task_progress table - heavily queried in dashboard and progress page
        Schema::table('task_progress', function (Blueprint $table) {
            $table->index(['project_id', 'progress_date'], 'task_progress_project_date_idx');
            // Compound index for getLatestProgressMap() - critical for correlated subquery performance
            $table->index(['project_id', 'task_id', 'progress_date'], 'task_progress_project_task_date_idx');
        });

        // Indexes for tasks table - used in parent/child traversal and nested set queries
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['project_id', 'parent_id'], 'tasks_project_parent_idx');
            $table->index('template_task_id', 'tasks_template_idx');
            // Compound index for nested set traversal in calculateParentProgress()
            $table->index(['project_id', '_lft', '_rgt'], 'tasks_project_lft_rgt_idx');
        });

        // Indexes for projects table - used in office/location filtering
        Schema::table('projects', function (Blueprint $table) {
            $table->index('office_id', 'projects_office_idx');
            $table->index('location_id', 'projects_location_idx');
        });

        // Indexes for offices table - used in cascading filters
        Schema::table('offices', function (Blueprint $table) {
            $table->index(['level_id', 'parent_id'], 'offices_level_parent_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_progress', function (Blueprint $table) {
            $table->dropIndex('task_progress_project_date_idx');
            $table->dropIndex('task_progress_project_task_date_idx');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_project_parent_idx');
            $table->dropIndex('tasks_template_idx');
            $table->dropIndex('tasks_project_lft_rgt_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_office_idx');
            $table->dropIndex('projects_location_idx');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->dropIndex('offices_level_parent_idx');
        });
    }
};

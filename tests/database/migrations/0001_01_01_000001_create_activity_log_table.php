<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Test-only migration. Reproduces the full activity_log schema from spatie/laravel-activitylog
// (create_activity_log_table + add_event_column + add_batch_uuid_column stubs) in a single
// .php file, since loadMigrationsFrom() ignores .stub files in vendor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))->create(
            config('activitylog.table_name'),
            function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('log_name')->nullable();
                $table->text('description');
                $table->nullableMorphs('subject', 'subject');
                $table->string('event')->nullable();
                $table->nullableMorphs('causer', 'causer');
                $table->json('properties')->nullable();
                $table->uuid('batch_uuid')->nullable();
                $table->timestamps();
                $table->index('log_name');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))->dropIfExists(
            config('activitylog.table_name')
        );
    }
};

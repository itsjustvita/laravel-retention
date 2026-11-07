<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_log', function (Blueprint $table) {
            $table->id();
            $table->string('model');
            // Null for table policies, whose rows are logged in aggregate.
            $table->char('record_hash', 64)->nullable();
            $table->string('key_id', 16)->nullable();
            $table->unsignedInteger('record_count')->default(1);
            $table->string('category');
            $table->string('rule');
            $table->string('action', 20);
            $table->string('trigger', 20);
            $table->timestamp('created_at')->index();

            $table->index(['model', 'record_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_log');
    }
};

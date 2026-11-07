<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_holds', function (Blueprint $table) {
            $table->id();
            // Use uuidMorphs() or ulidMorphs() instead if your models have UUID or ULID keys.
            $table->morphs('holdable');
            $table->text('reason');
            $table->timestamp('until')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('released_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_holds');
    }
};

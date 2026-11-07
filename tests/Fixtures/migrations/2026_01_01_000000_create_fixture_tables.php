<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('contract_ended_at')->nullable();
            $table->restrictable();
            $table->anonymizable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->timestamp('completed_at')->nullable();
            $table->restrictable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->date('issued_at')->nullable();
            $table->restrictable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('server_logs', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address');
            $table->timestamp('logged_at');
        });
    }
};

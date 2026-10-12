<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // denormalised for fast aggregation
            $table->string('provider_calendar_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 20)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_read_only')->default(true);
            $table->boolean('is_enabled')->default(true);   // included in aggregation + sync
            $table->string('custom_name')->nullable();      // user overrides
            $table->string('custom_color', 20)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['calendar_account_id', 'provider_calendar_id']);
            $table->index(['user_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendars');
    }
};

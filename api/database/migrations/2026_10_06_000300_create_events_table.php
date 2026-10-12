<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imported event instances. Recurring series are expanded into one row per occurrence.
     * All datetimes are stored in UTC.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->string('provider_event_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_all_day')->default(false);
            $table->string('timezone', 64)->nullable();
            $table->string('status', 20)->default('confirmed'); // confirmed | tentative
            $table->boolean('is_busy')->default(true);          // false for "free"/transparent events
            $table->string('visibility', 20)->nullable();       // null/default | private
            $table->string('organizer_email')->nullable();
            $table->text('url')->nullable();
            $table->string('recurring_event_id')->nullable();
            $table->string('sync_batch', 36)->nullable();       // id of the sync run that last saw this event
            $table->timestamps();

            $table->unique(['calendar_id', 'provider_event_id']);
            $table->index(['calendar_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

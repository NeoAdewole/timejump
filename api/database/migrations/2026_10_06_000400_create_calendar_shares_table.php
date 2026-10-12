<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * View-access grants from one user (owner) to another (grantee).
     */
    public function up(): void
    {
        Schema::create('calendar_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('grantee_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission', 20)->default('details'); // free_busy | details
            $table->boolean('all_calendars')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_id', 'grantee_id']);
            $table->index('grantee_id');
        });

        // When all_calendars = false, only these calendars are visible to the grantee.
        Schema::create('calendar_share_calendar', function (Blueprint $table) {
            $table->foreignId('calendar_share_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();

            $table->primary(['calendar_share_id', 'calendar_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_share_calendar');
        Schema::dropIfExists('calendar_shares');
    }
};

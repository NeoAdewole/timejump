<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A connected source of calendars: one Google / Microsoft login, or one iCal feed/upload.
     */
    public function up(): void
    {
        Schema::create('calendar_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);                 // google | outlook | ical
            $table->string('provider_account_id');          // remote user id, or hash of the iCal URL
            $table->string('email')->nullable();
            $table->string('display_name')->nullable();
            $table->text('access_token')->nullable();       // encrypted
            $table->text('refresh_token')->nullable();      // encrypted
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->text('source_url')->nullable();         // encrypted; iCal feed URL
            $table->string('status', 20)->default('active'); // active | error | reauth_required
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'provider_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_accounts');
    }
};

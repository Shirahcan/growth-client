<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * growth-client kit (G2b): the signed events growth-service delivered, one row per event_id.
 * Additive only; never dropped by the kit.
 *
 * source: calendar-client `create_calendar_service_events_table`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('growth_service_events')) {
            return;
        }

        Schema::create('growth_service_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('event_type', 64)->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never dropped by the kit.
    }
};

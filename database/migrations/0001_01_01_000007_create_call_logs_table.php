<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('outcome'); // Connected, Interested, Follow-up Required, Call Later, Busy, No Answer, Not Reachable, Wrong Number, Not Interested, Demo/Meeting Scheduled, Converted, Lost
            $table->integer('duration_seconds')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('called_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};

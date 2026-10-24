<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('custom_columns')->nullable(); // Array of column objects: [{"key": "budget", "label": "Budget", "type": "text"}]
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('tenant_id')->constrained('businesses')->nullOnDelete();
            $table->json('custom_fields')->nullable()->after('notes');

            $table->index(['tenant_id', 'business_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn(['business_id', 'custom_fields']);
        });

        Schema::dropIfExists('businesses');
    }
};

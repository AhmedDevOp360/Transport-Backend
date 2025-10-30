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
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('name')->nullable()->after('user_id'); // Ford Transit
            $table->decimal('capacity_tons', 5, 2)->nullable()->after('type'); // 2.5 Tons
            $table->date('last_used')->nullable()->after('rate_per_km');
            $table->enum('status', ['available', 'in-use', 'maintenance', 'retired'])->default('available')->after('last_used');
            $table->text('notes')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['name', 'capacity_tons', 'last_used', 'status', 'notes']);
        });
    }
};

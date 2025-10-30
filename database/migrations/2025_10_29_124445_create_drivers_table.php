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
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('team_name');
            $table->enum('status', ['active', 'in-transit', 'on-break', 'available'])->default('available');
            $table->string('job_assignment')->nullable();
            $table->string('truck_number')->unique();
            $table->decimal('rating', 2, 1)->default(0.0);
            $table->date('license_expiry')->nullable();
            $table->date('vehicle_maintenance_due')->nullable();
            $table->integer('completed_jobs')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};

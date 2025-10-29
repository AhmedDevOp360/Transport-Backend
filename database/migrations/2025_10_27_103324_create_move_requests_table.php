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
        Schema::create('move_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('move_type');
            $table->string('vehicle_type');
            $table->string('move_title');
            $table->text('pickup_address');
            $table->text('drop_address');
            $table->date('move_date');
            $table->time('move_time');
            $table->string('property_size');
            $table->decimal('budget_min', 10, 2);
            $table->decimal('budget_max', 10, 2);
            $table->date('estimated_delivery_date')->nullable();
            $table->longText('description')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'in-progress', 'completed', 'rejected'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('move_requests');
    }
};

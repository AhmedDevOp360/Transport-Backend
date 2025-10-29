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
        Schema::create('move_request_application_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('move_request_application_id');
            $table->string('service_name');
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->foreign('move_request_application_id', 'mras_app_id_fk')
                ->references('id')
                ->on('move_request_applications')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('move_request_application_services');
    }
};

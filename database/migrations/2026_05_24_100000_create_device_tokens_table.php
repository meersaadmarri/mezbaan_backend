<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fcm_token', 512);
            $table->string('platform', 20)->nullable(); // android, ios
            $table->string('app', 20)->nullable(); // consumer, business
            $table->timestamps();

            $table->unique('fcm_token');
            $table->index(['user_id', 'app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};

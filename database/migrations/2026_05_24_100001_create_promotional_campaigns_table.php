<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('promotional_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            /** all | customers | business */
            $table->string('audience', 20)->default('all');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            /** draft | scheduled | sent | failed */
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotional_campaigns');
    }
};

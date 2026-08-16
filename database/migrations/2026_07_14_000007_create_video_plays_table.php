<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained('episodes')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('played_at')->index();
            $table->timestamps();

            $table->index(['played_at', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_plays');
    }
};

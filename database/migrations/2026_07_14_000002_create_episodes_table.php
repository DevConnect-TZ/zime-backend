<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('season')->default(1);
            $table->unsignedSmallInteger('episode')->default(1);
            $table->string('duration')->nullable();
            $table->string('video_url')->nullable();
            $table->timestamps();

            $table->index(['video_id', 'season', 'episode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};

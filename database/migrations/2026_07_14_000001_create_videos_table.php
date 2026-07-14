<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('single')->index(); // single | series
            $table->decimal('price', 10, 2)->default(0);
            $table->string('thumbnail')->nullable();
            $table->string('trailer_url')->nullable();
            $table->string('video_link')->nullable();
            $table->string('genre')->nullable();
            $table->string('rating')->nullable();
            $table->string('duration')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->boolean('is_trending')->default(false)->index();
            $table->boolean('is_popular')->default(false)->index();
            $table->unsignedBigInteger('views')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};

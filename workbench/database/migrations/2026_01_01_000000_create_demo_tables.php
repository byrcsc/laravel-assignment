<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('customer');
            $table->string('trade');
            $table->boolean('emergency')->default(false);
            $table->timestamps();
        });

        Schema::create('tradies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->string('trade');
            $table->boolean('available')->default(true);
            $table->unsignedTinyInteger('grade')->default(2);
            $table->timestamps();
        });

        Schema::create('callouts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference');
            $table->string('suburb');
            $table->timestamps();
        });

        Schema::create('crews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->string('suburb');
            $table->boolean('available')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crews');
        Schema::dropIfExists('callouts');
        Schema::dropIfExists('tradies');
        Schema::dropIfExists('enquiries');
    }
};

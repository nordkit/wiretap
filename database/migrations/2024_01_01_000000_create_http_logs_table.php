<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('http_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('direction', 10);
            $table->string('driver', 20);
            $table->text('url');
            $table->string('method', 10);
            $table->json('request_headers')->nullable();
            $table->longText('request_body')->nullable();
            $table->integer('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->text('error_message')->nullable();
            $table->nullableUlidMorphs('loggable');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['loggable_type', 'loggable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('http_logs');
    }
};

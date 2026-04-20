<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiretap_traces', function (Blueprint $table): void {
            $table->string('caller_class')->nullable()->after('ip_address');
            $table->string('caller_method')->nullable()->after('caller_class');
        });
    }

    public function down(): void
    {
        Schema::table('wiretap_traces', function (Blueprint $table): void {
            $table->dropColumn(['caller_class', 'caller_method']);
        });
    }
};

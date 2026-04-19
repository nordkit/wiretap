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
            // varchar(45) covers both IPv4 and full IPv6 addresses
            $table->string('ip_address', 45)->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('wiretap_traces', function (Blueprint $table): void {
            $table->dropColumn('ip_address');
        });
    }
};

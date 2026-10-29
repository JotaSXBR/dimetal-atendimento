<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('phone2', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('company', 120)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('notes', 4000)->nullable();
            $table->date('birthday')->nullable();
            $table->json('urls')->nullable();
            // Primeira edição humana: só a partir daqui o contato vai ao Google e o push name deixa de mandar no nome.
            $table->timestamp('edited_at')->nullable();

            // Espelho no Google Contacts.
            $table->string('google_resource_name')->nullable()->unique();
            $table->string('google_etag')->nullable();
            $table->string('google_payload_hash', 64)->nullable();
            $table->timestamp('google_synced_at')->nullable();
            $table->timestamp('google_attempted_at')->nullable();
            $table->string('google_error', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'phone2', 'email', 'company', 'job_title', 'address', 'notes', 'birthday', 'urls', 'edited_at',
                'google_resource_name', 'google_etag', 'google_payload_hash', 'google_synced_at', 'google_attempted_at', 'google_error',
            ]);
        });
    }
};

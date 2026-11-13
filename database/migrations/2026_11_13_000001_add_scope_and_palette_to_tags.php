<?php

use App\Enums\TagColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->string('scope', 10)->default('conversa')->after('name');
            $table->string('color', 20)->default(TagColor::default()->value)->change();
        });

        // A cor livre (#RRGGBB) acaba: cada etiqueta existente vira o tom mais próximo da paleta.
        foreach (DB::table('tags')->get(['id', 'color']) as $tag) {
            DB::table('tags')->where('id', $tag->id)->update(['color' => TagColor::nearest((string) $tag->color)->value]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('tags')->get(['id', 'color']) as $tag) {
            DB::table('tags')->where('id', $tag->id)->update(['color' => (TagColor::tryFrom((string) $tag->color) ?? TagColor::default())->swatch()]);
        }

        Schema::table('tags', function (Blueprint $table) {
            $table->string('color', 7)->default('#64748B')->change();
            $table->dropColumn('scope');
        });
    }
};

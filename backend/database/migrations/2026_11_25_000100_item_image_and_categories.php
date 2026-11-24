<?php

use App\Models\Item;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Product photo on items; raw materials get real categories instead of one "Raw material" bucket. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $t) {
            $t->string('image')->nullable()->after('category');
        });
        foreach (Item::RAW_CATEGORY_BY_CODE as $code => $cat) {
            DB::table('items')->where('code', $code)->where('type', 'RAW')->where('category', 'Raw material')->update(['category' => $cat]);
        }
    }

    public function down(): void
    {
        Schema::table('items', fn (Blueprint $t) => $t->dropColumn('image'));
    }
};

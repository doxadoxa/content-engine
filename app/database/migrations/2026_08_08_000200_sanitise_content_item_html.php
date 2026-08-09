<?php

declare(strict_types=1);

use App\Support\Content\SafeMarkdown;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $renderer = app(SafeMarkdown::class);

        DB::table('content_items')
            ->select(['id', 'body_markdown'])
            ->whereNotNull('body_markdown')
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($renderer): void {
                foreach ($items as $item) {
                    DB::table('content_items')
                        ->where('id', $item->id)
                        ->update([
                            'body_html' => $renderer->render((string) $item->body_markdown),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Deliberately irreversible: restoring executable stored HTML would
        // recreate the vulnerability this migration removes.
    }
};

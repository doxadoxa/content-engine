<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Models\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->char('token_hash', 64)->nullable();
        });

        /** @var array<string, true> $seen */
        $seen = [];

        Channel::acrossProjects()
            ->where('type', ChannelType::PullApi->value)
            ->eachById(function (Channel $channel) use (&$seen): void {
                $hash = $channel->secret === null
                    ? null
                    : Channel::fingerprintPullToken($channel->secret);

                // Before this index, the same bearer token could select two
                // projects. Preserve the first and safely revoke later
                // duplicates rather than leaving deployment stuck halfway.
                if ($hash !== null && isset($seen[$hash])) {
                    $channel->forceFill([
                        'secret' => null,
                        'token_hash' => null,
                        'is_enabled' => false,
                    ])->save();

                    return;
                }

                $channel->forceFill([
                    'token_hash' => $hash,
                ])->save();

                if ($hash !== null) {
                    $seen[$hash] = true;
                }
            });

        Schema::table('channels', function (Blueprint $table): void {
            $table->unique('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropUnique(['token_hash']);
            $table->dropColumn('token_hash');
        });
    }
};

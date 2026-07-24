<?php

namespace App\Console\Commands;

use App\Models\FaceAttempt;
use App\Services\AttendanceScheduleSettings;
use Illuminate\Console\Command;

class PruneFaceAttempts extends Command
{
    protected $signature = 'attendance:prune-face-attempts {--days= : Override the configured retention window in days}';

    protected $description = 'Delete old face attempt logs and their media evidence.';

    public function handle(AttendanceScheduleSettings $settings): int
    {
        $retentionDays = $this->option('days');
        $retentionDays = is_numeric($retentionDays)
            ? max(1, min(3650, (int) $retentionDays))
            : $settings->faceAttemptRetentionDays();

        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;

        FaceAttempt::query()
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where('attempted_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query
                            ->whereNull('attempted_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->chunkById(100, function ($attempts) use (&$deleted): void {
                foreach ($attempts as $attempt) {
                    $attempt->delete();
                    $deleted++;
                }
            });

        $this->info("Deleted {$deleted} face attempt log(s) older than {$retentionDays} day(s).");

        return self::SUCCESS;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Announcement\Status;
use App\Enums\Announcement\Type;
use App\Models\Announcement;
use App\Models\User;
use App\Services\HomeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AnnouncementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_announcements_include_unpinned_items_until_the_end_of_the_expiry_day(): void
    {
        Carbon::setTestNow('2026-06-18 15:30:00');

        $user = User::factory()->create();

        $announcement = Announcement::query()->create([
            'title' => 'Schedule update',
            'content' => 'Today is still visible.',
            'type' => Type::GENERAL->value,
            'status' => Status::PUBLISHED->value,
            'created_by' => $user->id,
            'published_at' => Carbon::now()->subHour(),
            'expires_at' => Carbon::today(),
            'is_pinned' => false,
        ]);

        $announcements = app(HomeService::class)->getAnnouncements();

        $this->assertTrue($announcements->contains('id', $announcement->id));

        Carbon::setTestNow();
    }

    public function test_saving_an_announcement_clears_the_home_cache(): void
    {
        Cache::put(Announcement::HOME_CACHE_KEY, collect(['stale']), 3600);

        $user = User::factory()->create();

        Announcement::query()->create([
            'title' => 'Fresh update',
            'content' => 'Cache should be cleared.',
            'type' => Type::GENERAL->value,
            'status' => Status::PUBLISHED->value,
            'created_by' => $user->id,
            'published_at' => now(),
            'expires_at' => now()->addDay(),
            'is_pinned' => true,
        ]);

        $this->assertFalse(Cache::has(Announcement::HOME_CACHE_KEY));
    }
}

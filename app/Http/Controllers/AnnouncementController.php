<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(): Response
    {
        $announcements = Announcement::with('media')
            ->published()
            ->isNotExpired()
            ->latest('published_at')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Announcements/Index', [
            'announcements' => $announcements,
        ]);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FaceRoutesTest extends TestCase
{
    public function test_legacy_face_register_post_route_is_removed(): void
    {
        $postFaceRegisterRoutes = collect(Route::getRoutes())
            ->filter(fn ($route): bool => in_array('POST', $route->methods(), true))
            ->filter(fn ($route): bool => $route->uri() === 'face/register');

        $this->assertCount(0, $postFaceRegisterRoutes);
    }
}

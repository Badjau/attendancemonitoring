<?php

namespace App\Policies;

use App\Models\User;
use App\Support\AdminAccess;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return ! AdminAccess::isHrAdmin();
    }

    public function view(User $user, Activity $activity): bool
    {
        return ! AdminAccess::isHrAdmin();
    }

    public function viewSensitiveData(User $user, Activity $activity): bool
    {
        return ! AdminAccess::isHrAdmin();
    }
}

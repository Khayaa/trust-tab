<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Only the two people on a tab may listen to it. This reuses TabPolicy rather
 * than restating the rule, so the socket can never be more permissive than the
 * page.
 */
Broadcast::channel('tabs.{tab}', function (User $user, Tab $tab) {
    return $user->can('view', $tab);
});

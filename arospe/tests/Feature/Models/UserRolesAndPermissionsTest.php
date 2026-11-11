<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

test("assigning a role stores the user's uuid in the renamed morph-key column", function () {
    $user = User::factory()->create();
    Role::create(['name' => 'editor']);

    $user->assignRole('editor');

    $morphKeyColumn = config('permission.column_names.model_morph_key');

    $storedModelId = DB::table('model_has_roles')
        ->where('model_type', User::class)
        ->value($morphKeyColumn);

    expect($storedModelId)->toBe($user->id)
        ->and(Str::isUuid($storedModelId, 7))->toBeTrue();
});

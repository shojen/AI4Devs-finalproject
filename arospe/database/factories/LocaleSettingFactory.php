<?php

namespace Database\Factories;

use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocaleSetting>
 *
 * R-17: LocaleSetting's fixed, non-incrementing primary key is unlike every other model in this
 * repo. `id` is ALWAYS LocaleSetting::SINGLETON_ID here, by design (D20) -- a bare second
 * `LocaleSetting::factory()->create()` in the same test MUST duplicate-key-error rather than
 * insert a second row. A test arranging a specific settings state should
 * `LocaleSetting::query()->updateOrCreate(['id' => LocaleSetting::SINGLETON_ID], [...])`, or call
 * this factory only once per test.
 */
class LocaleSettingFactory extends Factory
{
    /**
     * Define the model's default state: both defaults set to English.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => LocaleSetting::SINGLETON_ID,
            'default_ui_locale' => UiLocale::English->value,
            'default_notification_locale' => UiLocale::English->value,
        ];
    }
}

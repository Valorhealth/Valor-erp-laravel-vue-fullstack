<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\UserSettingsController;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class UserSettingsControllerTest extends TestCase
{
    public function test_every_user_receives_complete_defaults_merged_with_saved_preferences(): void
    {
        $user = new User;
        $user->forceFill(['preferences' => ['language' => 'en', 'rows_per_page' => 100, 'number_format' => 'th-TH']]);
        $request = Request::create('/api/v1/settings');
        $request->setUserResolver(fn (): User => $user);

        $response = (new UserSettingsController)->show($request);
        $settings = $response->getData(true)['data'];

        $this->assertSame('en', $settings['language']);
        $this->assertSame(100, $settings['rows_per_page']);
        $this->assertSame('Asia/Bangkok', $settings['timezone']);
        $this->assertSame('24h', $settings['time_format']);
        $this->assertSame('en-US', $settings['number_format']);
        $this->assertSame('normal', $settings['font_scale']);
        $this->assertFalse($settings['high_contrast']);
        $this->assertFalse($settings['reduce_motion']);
        $this->assertTrue($settings['in_app_notifications']);
        $this->assertTrue($settings['email_notifications']);
        $this->assertCount(17, $settings);
        $this->assertCount(5, $settings['notification_categories']);
    }
}

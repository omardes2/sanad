<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use App\Data\InboundMessageData;
use App\Enums\ChannelType;
use App\Enums\MessageType;
use App\Jobs\ProcessInboundMessage;
use App\Models\ChannelAccount;
use App\Models\User;

/**
 * Create a demo user with a Web channel account for pipeline tests.
 */
function pipelineWebAccount(): ChannelAccount
{
    $user = User::factory()->create();

    return ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::Web,
        'external_identifier' => 'web-user-'.$user->id,
    ]);
}

/**
 * Build an inbound web message DTO for the given account.
 */
function pipelineInbound(ChannelAccount $account, string $externalId, string $text = 'مرحبا'): InboundMessageData
{
    return new InboundMessageData(
        channel: ChannelType::Web,
        externalMessageId: $externalId,
        externalUserId: $account->external_identifier,
        type: MessageType::Text,
        text: $text,
    );
}

/**
 * Execute the ProcessInboundMessage job synchronously (deps auto-injected).
 */
function pipelineRunJob(int $messageId): void
{
    app()->call([new ProcessInboundMessage($messageId), 'handle']);
}

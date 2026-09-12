<?php

declare(strict_types=1);

use App\Enums\FollowUpAnswer;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Message;
use App\Support\FollowUps\ExplicitFollowUpIntent;
use App\Support\FollowUps\FollowUpAnswerReader;
use App\Support\FollowUps\FollowUpTimeEvidence;
use App\Support\Memory\ExplicitMemoryIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The three server-side gates that decide whether Sanad may open a loop, and what
 * a reply actually says.
 *
 * All three exist for one reason: a follow-up is a LICENCE TO SPEAK LATER,
 * unprompted and repeatedly, and the model proposing one is a suggestion rather
 * than authority. Everything here is decided from stored evidence in the
 * subscriber's own words.
 */
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Explicit intent
|--------------------------------------------------------------------------
*/

it('reads an explicit request to follow up, and never a bare statement of intent', function (string $text, bool $expected) {
    expect(ExplicitFollowUpIntent::inText($text))->toBe($expected, $text);
})->with([
    // Asking for it.
    ['تابع معي إذا دفعت الفاتورة', true],
    ['تابعني بهاد الموضوع', true],
    ['اسألني بعدين إذا خلصت', true],
    ['تأكد مني بكرا', true],
    ['follow up with me tomorrow', true],
    ['ask me later if I paid', true],
    // NOT asking for it. These are the sentences that must never become days of
    // unsolicited messages just because a model judged them useful.
    ['بكرا بدفع فاتورة الكهربا', false],
    ['لازم أدفع الفاتورة', false],
    ['ذكرني بكرا الساعة ٩ أدفع الفاتورة', false],   // a REMINDER, not a follow-up
    ['احفظ إني بحب القهوة سادة', false],            // a MEMORY, not a follow-up
    ['شكرا', false],
    ['', false],
]);

it('never reads a request to STOP following up as authority to start one', function (string $text) {
    expect(ExplicitFollowUpIntent::inText($text))->toBeFalse($text)
        ->and(ExplicitFollowUpIntent::stopInText($text))->toBeTrue($text);
})->with([
    'بطل المتابعة',
    'وقف متابعة البنك',
    'لا تتابعني بهاد',
    'stop following up',
    'no follow up please',
]);

it('keeps the follow-up phrase set DISJOINT from memory and from the reminder verbs', function () {
    // This is load-bearing rather than tidy. One verb serving two subsystems that
    // message the subscriber on different schedules is how «ذكرني» ends up
    // authorising both a reminder and a loop of proactive questions.
    foreach (ExplicitFollowUpIntent::phrases() as $phrase) {
        expect(ExplicitMemoryIntent::inText($phrase))->toBeFalse($phrase)
            ->and(ExplicitMemoryIntent::forgetInText($phrase))->toBeFalse($phrase)
            ->and(str_contains($phrase, 'ذكرني'))->toBeFalse($phrase)
            ->and(str_contains($phrase, 'تذكر'))->toBeFalse($phrase);
    }

    // And the converse: a memory instruction is not follow-up authority.
    foreach (['احفظ إني بحب القهوة', 'انسى إني بحب القهوة', 'remember that I like coffee'] as $text) {
        expect(ExplicitFollowUpIntent::inText($text))->toBeFalse($text);
    }
});

it('takes evidence only from an inbound message, never from Sanad\'s own words', function () {
    [$user, , $conversation] = fuSubscriber();

    $inbound = fuInbound($user, $conversation, 'تابع معي بكرا');
    $outbound = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'text_content' => 'تابع معي بكرا',
    ]);

    expect(ExplicitFollowUpIntent::present($inbound))->toBeTrue()
        ->and(ExplicitFollowUpIntent::present($outbound))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The time the subscriber actually gave
|--------------------------------------------------------------------------
*/

it('recognises a definite moment in the subscriber\'s own words', function (string $text, bool $expected) {
    expect(FollowUpTimeEvidence::inText($text))->toBe($expected, $text);
})->with([
    ['بكرا', true],
    ['بعد بكرا الصبح', true],
    ['الجمعة الساعة ٩', true],
    ['يوم الاثنين', true],
    ['الاسبوع الجاي', true],
    ['at 9pm', true],
    ['next week', true],
    ['بعد ساعتين', true],
    ['2026-09-20', true],
    // No time at all — this is the case that must make Sanad ASK.
    ['تابع معي', false],
    ['تابعني بهاد الموضوع', false],
    ['follow up with me', false],
    ['', false],
]);

it('does not mistake an English word that merely contains a clock suffix for a time', function () {
    // `am` inside `exam` would match a naive substring check.
    expect(FollowUpTimeEvidence::inText('follow up about the exam'))->toBeFalse()
        ->and(FollowUpTimeEvidence::inText('follow up at 9 am'))->toBeTrue();
});

it('accepts a time the subscriber gave in an earlier message of the same conversation', function () {
    [$user, , $conversation] = fuSubscriber();

    // People speak in two messages: the time, then the request.
    fuInbound($user, $conversation, 'بكرا بدفع فاتورة الكهربا');
    $request = fuInbound($user, $conversation, 'تابع معي');

    expect(FollowUpTimeEvidence::present($request))->toBeTrue();
});

it('does not take a time from another conversation, from Sanad, or from too far back', function () {
    [$user, , $conversation] = fuSubscriber();
    [, , $other] = fuSubscriber();

    // Another conversation entirely.
    fuInbound($user, $other, 'بكرا بدفع الفاتورة');
    // Sanad's own suggestion of a time is not the subscriber supplying one.
    Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'text_content' => 'بكرا الساعة ٩؟',
    ]);
    // And older than the bounded lookback.
    fuInbound($user, $conversation, 'الجمعة عندي موعد');

    foreach (range(1, FollowUpTimeEvidence::LOOKBACK) as $i) {
        fuInbound($user, $conversation, 'ماشي');
    }

    $request = fuInbound($user, $conversation, 'تابع معي');

    expect(FollowUpTimeEvidence::present($request))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What a reply says
|--------------------------------------------------------------------------
*/

it('reads a reply conservatively, and resolves every ambiguity away from "confirmed"', function (string $text, FollowUpAnswer $expected) {
    expect(FollowUpAnswerReader::inText($text))->toBe($expected, $text);
})->with([
    // Confirmed.
    ['اه', FollowUpAnswer::Confirmed],
    ['آه دفعتها امبارح', FollowUpAnswer::Confirmed],
    ['تم', FollowUpAnswer::Confirmed],
    ['خلصت الموضوع', FollowUpAnswer::Confirmed],
    ['yes', FollowUpAnswer::Confirmed],
    ['done', FollowUpAnswer::Confirmed],
    // Not yet — including the forms that CONTAIN an affirmative stem.
    ['لسا', FollowUpAnswer::NotYet],
    ['لسا ما دفعت', FollowUpAnswer::NotYet],
    ['لا، ما دفعت', FollowUpAnswer::NotYet],
    ['not yet', FollowUpAnswer::NotYet],
    ['no', FollowUpAnswer::NotYet],
    ['نسيت', FollowUpAnswer::NotYet],
    // Stop.
    ['بطل المتابعة', FollowUpAnswer::Cancel],
    ['stop following up', FollowUpAnswer::Cancel],
    // Unrecognised — and «اهلا» is the case a substring match would get wrong.
    ['اهلا كيفك', FollowUpAnswer::Unrecognised],
    ['شو الأخبار', FollowUpAnswer::Unrecognised],
    ['بدي أحكيك بشي تاني', FollowUpAnswer::Unrecognised],
    ['', FollowUpAnswer::Unrecognised],
]);

it('refuses to support an outcome the subscriber\'s words do not carry', function () {
    [$user, , $conversation] = fuSubscriber();

    $vague = fuInbound($user, $conversation, 'اهلا كيفك');
    $yes = fuInbound($user, $conversation, 'اه خلصت');
    $no = fuInbound($user, $conversation, 'لسا');

    // The model may propose; these are the proposals the server refuses.
    expect(FollowUpAnswerReader::supports($vague, FollowUpAnswer::Confirmed))->toBeFalse()
        ->and(FollowUpAnswerReader::supports($vague, FollowUpAnswer::NotYet))->toBeFalse()
        ->and(FollowUpAnswerReader::supports($no, FollowUpAnswer::Confirmed))->toBeFalse()
        ->and(FollowUpAnswerReader::supports($yes, FollowUpAnswer::NotYet))->toBeFalse()
        // And the ones it accepts.
        ->and(FollowUpAnswerReader::supports($yes, FollowUpAnswer::Confirmed))->toBeTrue()
        ->and(FollowUpAnswerReader::supports($no, FollowUpAnswer::NotYet))->toBeTrue();
});

it('never reads Sanad\'s own outbound message as an answer', function () {
    [$user, , $conversation] = fuSubscriber();

    $outbound = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'text_content' => 'اه خلصت',
    ]);

    expect(FollowUpAnswerReader::read($outbound))->toBe(FollowUpAnswer::Unrecognised);
});

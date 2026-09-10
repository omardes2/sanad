<?php

declare(strict_types=1);

use App\Support\Voice\OggOpusDuration;

/**
 * Reading a voice note's length from the bytes.
 *
 * This exists because a duration ceiling has to be enforced BEFORE the paid
 * request and nothing in this deployment guarantees `ffprobe`. The property
 * that matters most is the LAST test: when the container does not say, the
 * answer is null and never a guess — an under-reported duration would let
 * exactly the file the ceiling exists for through the ceiling.
 *
 * The fixture is a small, structurally valid Ogg/Opus stream (three pages:
 * OpusHead, OpusTags, one audio page) whose final granule encodes 7.5 seconds
 * at 48 kHz on top of a 312-sample pre-skip.
 */
it('reads the duration of an Ogg/Opus stream from its final granule position', function () {
    $path = base_path('tests/Fixtures/voice/voice-note-7500ms.ogg');

    expect(OggOpusDuration::milliseconds($path))->toBe(7500)
        ->and(OggOpusDuration::seconds($path))->toBe(7.5);
});

it('subtracts the encoder pre-skip, which is priming rather than audio', function () {
    // 7.5s of granule minus 312 samples of pre-skip is 7500ms, not 7506ms.
    // Ignoring the pre-skip would over-report by the priming on every file.
    expect(OggOpusDuration::milliseconds(base_path('tests/Fixtures/voice/voice-note-7500ms.ogg')))
        ->toBe(7500);
});

it('answers null rather than guessing when the bytes are not an Ogg stream', function (string $contents) {
    $path = tempnam(sys_get_temp_dir(), 'voice-test');
    file_put_contents($path, $contents);

    try {
        expect(OggOpusDuration::milliseconds($path))->toBeNull()
            ->and(OggOpusDuration::seconds($path))->toBeNull();
    } finally {
        @unlink($path);
    }
})->with([
    'an MP3-ish header' => ["ID3\x03\x00\x00\x00".str_repeat("\x00", 200)],
    'plain text' => ['this is not audio at all, it is a sentence'],
    'empty' => [''],
    // Starts like Ogg and then stops: a truncated download, which is exactly
    // what a half-finished fetch would leave behind.
    'a truncated Ogg' => ["OggS\x00\x02".str_repeat("\x00", 10)],
]);

it('answers null for a file that is not there', function () {
    expect(OggOpusDuration::milliseconds('/nonexistent/voice-note.ogg'))->toBeNull();
});

it('answers null for an Ogg stream whose final page carries no usable granule', function () {
    // 0xFF…FF is the "no packet finishes on this page" sentinel. Treating it as
    // a number would produce an astronomically long duration — or, wrapped, a
    // negative one.
    $header = 'OggS'.chr(0).chr(2).str_repeat("\xFF", 8).pack('V', 1).pack('V', 0).pack('V', 0).chr(1).chr(0);

    $path = tempnam(sys_get_temp_dir(), 'voice-test');
    file_put_contents($path, $header);

    try {
        expect(OggOpusDuration::milliseconds($path))->toBeNull();
    } finally {
        @unlink($path);
    }
});

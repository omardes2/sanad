<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of AI work being requested. The router resolves a (provider, model)
 * per operation, and providers declare which operations they support via the
 * capability contracts (SupportsChat, ...). Only Chat is wired end-to-end in
 * Phase A; the rest are declared so the catalog/router vocabulary is stable and
 * adding a capability later is a new contract + provider method, not a redesign.
 */
enum AiOperation: string
{
    case Chat = 'chat';
    case Vision = 'vision';
    case Transcription = 'transcription';
    case ImageGeneration = 'image_generation';
    case Embedding = 'embedding';
    case Realtime = 'realtime';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'محادثة',
            self::Vision => 'فهم الصور',
            self::Transcription => 'تفريغ صوتي',
            self::ImageGeneration => 'توليد صور',
            self::Embedding => 'تضمينات',
            self::Realtime => 'صوت فوري',
        };
    }
}

<?php

namespace App\Enums;

/**
 * draft → scripting → rendering → review → queued → uploading
 *       → processing → scheduled → published, or failed at any step.
 */
enum VideoStatus: string
{
    case Draft = 'draft';
    case Scripting = 'scripting';
    case Rendering = 'rendering';
    case Review = 'review';
    case Queued = 'queued';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scripting, self::Rendering, self::Uploading, self::Processing => 'info',
            self::Review => 'warning',
            self::Queued, self::Scheduled => 'primary',
            self::Published => 'success',
            self::Failed => 'danger',
        };
    }

    /** States where the generation chain may be (re)started. */
    public function canGenerate(): bool
    {
        return in_array($this, [self::Draft, self::Review, self::Failed], true);
    }
}

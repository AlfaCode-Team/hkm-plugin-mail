<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * A campaign's `current_state`, as the API reports it.
 *
 * READ-side vocabulary: nothing in this plugin sends a state, so no request DTO
 * takes one. It exists so a caller reading a campaign can branch on a named
 * case instead of comparing string literals, and so the set is written down
 * somewhere — `terminating` and `failed_immediately` in particular are easy to
 * miss when guessing from a single response.
 *
 * Use {@see self::tryFrom()} rather than `from()`: Mailtrap may add a state,
 * and an unrecognised one should read as "something else" rather than throw
 * inside a caller that was only rendering a status badge.
 */
enum CampaignState: string
{
    case Draft             = 'draft';
    case Scheduled         = 'scheduled';
    case Started           = 'started';
    case Queued            = 'queued';
    case Paused            = 'paused';
    case Terminating       = 'terminating';
    case UnderReview       = 'under_review';
    case Finished          = 'finished';
    case Failed            = 'failed';
    case FailedImmediately = 'failed_immediately';

    /** Has the campaign stopped for good, whether it succeeded or not? */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Finished, self::Failed, self::FailedImmediately => true,
            default => false,
        };
    }
}

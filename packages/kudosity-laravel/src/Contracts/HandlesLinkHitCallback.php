<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Contracts;

use ExpertSystems\Kudosity\Data\LinkHitCallbackData;

/**
 * Interface for jobs that handle Link Hit callbacks.
 *
 * Implement this interface on your job class to handle link click
 * notifications for specific messages.
 *
 * Example:
 * ```php
 * class TrackLinkClickJob implements HandlesLinkHitCallback, ShouldQueue
 * {
 *     use Queueable;
 *
 *     public function __construct(
 *         public LinkHitCallbackData $linkHit,
 *         public array $context,
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         LinkClick::create([
 *             'campaign_id' => $this->context['campaign_id'],
 *             'url' => $this->linkHit->url,
 *             'mobile' => $this->linkHit->mobile,
 *             'clicked_at' => $this->linkHit->clickedAt,
 *         ]);
 *     }
 * }
 * ```
 */
interface HandlesLinkHitCallback
{
    /**
     * @param  LinkHitCallbackData  $linkHit  The link hit data from Kudosity
     * @param  array<string, mixed>  $context  The context data passed when sending the message
     */
    public function __construct(LinkHitCallbackData $linkHit, array $context);
}

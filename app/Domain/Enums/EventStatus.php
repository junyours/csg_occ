<?php

namespace App\Domain\Enums;

/**
 * An event's own lifecycle — separate from any individual Session's
 * status (see SessionStatus). A CSG Admin ending one session (e.g.
 * Day 1 Morning) does not end the event; only an explicit "End Event"
 * action does, and that cascades to force-end every still-`ongoing`
 * session under it (see App\Application\Actions\Events\EndEvent).
 *
 * Deliberately no `scheduled` case, unlike SessionStatus: an event is
 * "ongoing" (open for days/sessions to be added and run) from the
 * moment it's created — there's no separate "not started yet" phase
 * at the event level, only at the session level.
 */
enum EventStatus: string
{
    case Ongoing = 'ongoing';
    case Ended = 'ended';
}

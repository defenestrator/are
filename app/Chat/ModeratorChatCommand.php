<?php

namespace App\Chat;

/**
 * A chat command only the channel's broadcaster and moderators may run, such
 * as !clip.
 *
 * ChatCommandRegistry enforces this before handle(), against the channel the
 * message arrived on (the `moderateChannel` gate with the invocation's
 * channelId). A moderator of another served channel is refused (#96). It
 * applies to Twitch chat only, because channel moderators are only known for
 * Twitch. Commands that only sometimes need a moderator should implement
 * ChatCommand and call ChatCommandInvocation::canModerate() instead.
 */
interface ModeratorChatCommand extends ChatCommand {}

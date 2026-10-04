<?php

namespace App\Enums;

/**
 * How a song request arrived.
 */
enum SongRequestSource: string
{
    // The !song chat command.
    case Chat = 'chat';

    // The channel-point reward configured as music.song_request_reward_id.
    case ChannelPoints = 'channel_points';
}

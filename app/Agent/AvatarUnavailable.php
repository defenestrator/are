<?php

namespace App\Agent;

use RuntimeException;

/**
 * The avatar app (VTube Studio or Warudo, through its bridge) did not take
 * an expression. Its own class, so callers never mistake another runtime
 * error, such as the kill-switch gate's 423, for it.
 */
class AvatarUnavailable extends RuntimeException {}

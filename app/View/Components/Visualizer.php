<?php

namespace App\View\Components;

use App\Support\VisualizerAudio;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Visualizer extends Component
{
    public VisualizerAudio $audio;

    /**
     * @param  bool  $overlay  Rendered as the OBS overlay: no click-to-play,
     *                         no audible demo track, and a time-based camera orbit.
     */
    public function __construct(public bool $overlay = false)
    {
        $this->audio = VisualizerAudio::fromRequest(request(), $overlay);
    }

    public function render(): View|Closure|string
    {
        return view('components.visualizer');
    }
}

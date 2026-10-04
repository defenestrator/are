{{--
    A read-only question card for overlays: the question, its vote count and
    who asked it. This is the old /top-vote markup made legible at stream
    size; the interactive card on /vote keeps its vote and delete buttons.

    Expects a question from Question::getSortedQuestions() or
    getRecentQuestions(), which select `votes` and eager-load `user.identities`.
--}}
@props([
    'question',
    'rank' => null,
    'featured' => false,
])

@php
    $name = $question->user?->name ?? 'Someone';
    $avatar = $question->user?->avatar_url;
@endphp

<article {{ $attributes->class([
    'overlay-panel overlay-enter rounded-2xl',
    'px-10 py-8 vertical:px-12 vertical:py-10' => $featured,
    'px-6 py-5 vertical:px-8 vertical:py-6' => ! $featured,
]) }}>
    <div class="flex items-start gap-4">
        @if ($rank !== null)
            <span class="mt-1 flex size-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-lg font-semibold tabular-nums text-white/80 vertical:size-12 vertical:text-2xl">
                {{ $rank }}
            </span>
        @endif

        <p @class([
            'font-semibold leading-snug text-white [overflow-wrap:anywhere]',
            'line-clamp-4 text-[2.75rem] vertical:text-[3.25rem]' => $featured,
            'line-clamp-3 text-[1.625rem] vertical:line-clamp-2 vertical:text-[2.5rem]' => ! $featured,
        ])>{{ $question->question }}</p>
    </div>

    <footer @class([
        'flex items-center justify-between gap-6 text-white/70',
        'mt-6 text-2xl vertical:text-3xl' => $featured,
        'mt-4 text-xl vertical:text-[1.75rem]' => ! $featured,
    ])>
        <div class="flex items-center gap-2 font-semibold tabular-nums">
            <flux:icon.hand-thumb-up variant="outline" class="size-[1.2em] [&_path]:stroke-[2.25]" />
            <span>{{ $question->votes ?? 0 }}</span>
        </div>

        <div class="flex min-w-0 items-center gap-3">
            @if ($avatar)
                <img src="{{ $avatar }}" alt="" class="size-[1.75em] shrink-0 rounded-full object-cover" />
            @else
                <span class="flex size-[1.75em] shrink-0 items-center justify-center rounded-full bg-white/15 text-[0.7em] font-semibold uppercase">
                    {{ mb_substr($name, 0, 1) }}
                </span>
            @endif
            <span class="truncate font-medium text-white/90">{{ $name }}</span>
        </div>
    </footer>
</article>

{{-- @var list<\App\Analytics\AttributionRow> $rows, bool $withStream, \App\Analytics\AttributionRow $totals --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-zinc-500 border-b border-zinc-200 dark:border-zinc-700">
            <tr>
                <th scope="col" class="py-2 pe-4 font-medium">Channel</th>
                @if ($withStream)
                    <th scope="col" class="py-2 pe-4 font-medium">Stream</th>
                @endif
                <th scope="col" class="py-2 pe-4 font-medium text-right">Short-link clicks</th>
                <th scope="col" class="py-2 pe-4 font-medium text-right">Enquiries with consent</th>
                <th scope="col" class="py-2 font-medium text-right">Conversion</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($rows as $row)
                <tr>
                    <td class="py-2 pe-4">{{ $row->channel ?? 'No short link' }}</td>
                    @if ($withStream)
                        <td class="py-2 pe-4 font-mono">{{ $row->stream ?? '—' }}</td>
                    @endif
                    <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($row->clicks) }}</td>
                    <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($row->leads) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ $row->conversionLabel() }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="border-t-2 border-zinc-300 dark:border-zinc-600 font-semibold">
            <tr>
                <th scope="row" class="py-2 pe-4 text-left" @if ($withStream) colspan="2" @endif>Total</th>
                <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($totals->clicks) }}</td>
                <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($totals->leads) }}</td>
                <td class="py-2 text-right tabular-nums">{{ $totals->conversionLabel() }}</td>
            </tr>
        </tfoot>
    </table>
</div>

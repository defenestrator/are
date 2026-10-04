<?php

namespace App\Http\Controllers;

use App\Analytics\AttributionReport;
use App\Analytics\AttributionRow;
use App\Http\Requests\AttributionRangeRequest;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /admin/attribution: which show, on which channel, produced leads. Gated
 * to broadcasters by viewAttribution, because the underlying leads are
 * business PII, though only aggregate counts are ever shown or exported.
 */
class AttributionController extends Controller
{
    public function index(AttributionRangeRequest $request): View
    {
        return view('admin.attribution', ['report' => AttributionReport::for($request->range())]);
    }

    /** The per-stream table and its total as CSV. Counts only, no PII. */
    public function export(AttributionRangeRequest $request): StreamedResponse
    {
        $report = AttributionReport::for($request->range());
        $filename = sprintf('attribution-%s-to-%s.csv', $report->range->from->toDateString(), $report->range->lastDay()->toDateString());

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['channel', 'stream', 'short_link_clicks', 'enquiries_with_consent', 'conversion'], escape: '');

            foreach ([...$report->streams, $report->totals()] as $i => $row) {
                $isTotal = $i === count($report->streams);
                fputcsv($out, [
                    $isTotal ? 'TOTAL' : self::cell($row->channel ?? 'no short link'),
                    $isTotal ? '' : self::cell($row->stream ?? ''),
                    $row->clicks,
                    $row->leads,
                    self::conversion($row),
                ], escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A ratio to four places for spreadsheets, or empty with no clicks. */
    private static function conversion(AttributionRow $row): string
    {
        $conversion = $row->conversion();

        return $conversion === null ? '' : number_format($conversion, 4, '.', '');
    }

    /** Stop a spreadsheet from reading a UTM value as a formula. */
    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}

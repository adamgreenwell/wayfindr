<?php

namespace App\Support\ExternalIssues;

use App\Models\Ticket;
use Illuminate\Support\Str;

class ExternalIssueExportPreview
{
    private const EXPORT_NOTE = 'This issue was created from a Wayfindr ticket. Raw visitor transcripts, cobrowse snapshots, and internal notes were not exported by default.';

    private const OMITTED_CONVERSATION_DESCRIPTION = 'Conversation transcript omitted. Use the Wayfindr ticket link for authorized support context.';

    /**
     * @return array{
     *     title: string,
     *     summary: array<int, array{label: string, value: string}>,
     *     description: string,
     *     export_note: string,
     *     body: string
     * }
     */
    public function forTicket(Ticket $ticket): array
    {
        $ticket->loadMissing(['conversation', 'site']);

        $summary = [
            ['label' => 'Ticket', 'value' => "Wayfindr ticket #{$ticket->id}"],
            ['label' => 'Support code', 'value' => $ticket->conversation?->support_code ?? 'Not linked'],
            ['label' => 'Site', 'value' => $ticket->site->name],
            ['label' => 'Priority', 'value' => Str::headline($ticket->priority)],
            ['label' => 'Category', 'value' => $ticket->categoryLabel()],
            ['label' => 'Status', 'value' => Str::headline($ticket->status)],
            ['label' => 'Wayfindr URL', 'value' => route('dashboard.tickets.show', $ticket)],
        ];
        $description = $this->description($ticket);

        return [
            'title' => $ticket->subject,
            'summary' => $summary,
            'description' => $description,
            'export_note' => self::EXPORT_NOTE,
            'body' => $this->body($summary, $description),
        ];
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $summary
     */
    private function body(array $summary, string $description): string
    {
        $summaryLines = collect($summary)
            ->map(function (array $item): string {
                if ($item['label'] === 'Ticket') {
                    return $item['value'];
                }

                return "{$item['label']}: {$item['value']}";
            })
            ->all();

        return collect([
            $summaryLines[0],
            '',
            ...array_slice($summaryLines, 1),
            '',
            'Description:',
            $description,
            '',
            'Export note:',
            self::EXPORT_NOTE,
        ])->implode(PHP_EOL);
    }

    private function description(Ticket $ticket): string
    {
        $description = trim((string) $ticket->description);

        if ($this->shouldOmitDescription($ticket, $description)) {
            return self::OMITTED_CONVERSATION_DESCRIPTION;
        }

        return $description === '' ? 'No description provided.' : $description;
    }

    private function shouldOmitDescription(Ticket $ticket, string $description): bool
    {
        if ($description === '') {
            return false;
        }

        $descriptionSource = data_get($ticket->metadata, 'description_source');

        if ($descriptionSource === 'agent_summary') {
            return false;
        }

        if ($descriptionSource === 'conversation_transcript') {
            return true;
        }

        return data_get($ticket->metadata, 'source') === 'conversation'
            && $this->looksLikeConversationTranscript($description);
    }

    private function looksLikeConversationTranscript(string $description): bool
    {
        return preg_match('/(?:^|\R)(?:Visitor|Agent|[A-Z][\p{L}\p{M}\p{N} .\'-]{1,80}):\s+\S/u', $description) === 1;
    }
}

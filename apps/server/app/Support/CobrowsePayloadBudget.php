<?php

namespace App\Support;

final class CobrowsePayloadBudget
{
    public const SNAPSHOT_HTML_MAX_CHARACTERS = 65535;

    public const SNAPSHOT_TEXT_MAX_CHARACTERS = 10000;

    public const MUTATION_BATCH_MAX_ITEMS = 50;

    public const MUTATION_TEXT_MAX_CHARACTERS = 5000;

    public const MUTATION_HTML_MAX_CHARACTERS = 10000;

    public const MUTATION_ATTRIBUTE_VALUE_MAX_CHARACTERS = 2048;

    public const MUTATION_RECENT_BATCHES_RETAINED = 20;

    public const TELEMETRY_PAYLOAD_MAX_BYTES = 10485760;

    public const WIDGET_MUTATION_BATCH_MAX_BYTES = 60000;

    public const WIDGET_MUTATION_QUEUE_MAX_RECORDS = 250;

    public const WIDGET_MUTATION_FLUSH_MS = 50;

    public const WIDGET_PRESSURE_RESYNC_MS = 30000;

    public const WIDGET_STATUS_POLL_MS = 5000;

    public const WIDGET_RESYNC_MAX_ATTEMPTS = 3;

    /**
     * @return array<string, int>
     */
    public static function limits(): array
    {
        return [
            'snapshot_html_max_characters' => self::SNAPSHOT_HTML_MAX_CHARACTERS,
            'snapshot_text_max_characters' => self::SNAPSHOT_TEXT_MAX_CHARACTERS,
            'mutation_batch_max_items' => self::MUTATION_BATCH_MAX_ITEMS,
            'mutation_text_max_characters' => self::MUTATION_TEXT_MAX_CHARACTERS,
            'mutation_html_max_characters' => self::MUTATION_HTML_MAX_CHARACTERS,
            'mutation_attribute_value_max_characters' => self::MUTATION_ATTRIBUTE_VALUE_MAX_CHARACTERS,
            'mutation_recent_batches_retained' => self::MUTATION_RECENT_BATCHES_RETAINED,
            'telemetry_payload_max_bytes' => self::TELEMETRY_PAYLOAD_MAX_BYTES,
            'widget_mutation_batch_max_bytes' => self::WIDGET_MUTATION_BATCH_MAX_BYTES,
            'widget_mutation_queue_max_records' => self::WIDGET_MUTATION_QUEUE_MAX_RECORDS,
            'widget_mutation_flush_ms' => self::WIDGET_MUTATION_FLUSH_MS,
            'widget_pressure_resync_ms' => self::WIDGET_PRESSURE_RESYNC_MS,
            'widget_status_poll_ms' => self::WIDGET_STATUS_POLL_MS,
            'widget_resync_max_attempts' => self::WIDGET_RESYNC_MAX_ATTEMPTS,
        ];
    }

    /**
     * @return array<int, array{description: string, items: array<int, array{label: string, value: string}>, label: string}>
     */
    public static function readinessDefaults(): array
    {
        return [
            [
                'description' => 'Server-side caps keep incoming cobrowse snapshots, mutation batches, and telemetry bounded.',
                'items' => [
                    ['label' => 'Snapshot HTML', 'value' => self::formatCharacters(self::SNAPSHOT_HTML_MAX_CHARACTERS)],
                    ['label' => 'Snapshot text', 'value' => self::formatCharacters(self::SNAPSHOT_TEXT_MAX_CHARACTERS)],
                    ['label' => 'Server mutation batch', 'value' => self::formatItems(self::MUTATION_BATCH_MAX_ITEMS)],
                    ['label' => 'Mutation text', 'value' => self::formatCharacters(self::MUTATION_TEXT_MAX_CHARACTERS)],
                    ['label' => 'Mutation HTML', 'value' => self::formatCharacters(self::MUTATION_HTML_MAX_CHARACTERS)],
                    ['label' => 'Recent batches', 'value' => self::formatRetained(self::MUTATION_RECENT_BATCHES_RETAINED)],
                    ['label' => 'Server telemetry payload', 'value' => self::formatBytes(self::TELEMETRY_PAYLOAD_MAX_BYTES)],
                ],
                'label' => 'Server intake',
            ],
            [
                'description' => 'The stock widget trims, flushes, polls, and resyncs within these browser-side defaults.',
                'items' => [
                    ['label' => 'Stock widget batch payload', 'value' => self::formatBytes(self::WIDGET_MUTATION_BATCH_MAX_BYTES)],
                    ['label' => 'Stock widget queue', 'value' => self::formatPending(self::WIDGET_MUTATION_QUEUE_MAX_RECORDS)],
                    ['label' => 'Mutation flush', 'value' => self::formatMilliseconds(self::WIDGET_MUTATION_FLUSH_MS)],
                    ['label' => 'Pressure resync', 'value' => self::formatMilliseconds(self::WIDGET_PRESSURE_RESYNC_MS)],
                    ['label' => 'Status poll', 'value' => self::formatMilliseconds(self::WIDGET_STATUS_POLL_MS)],
                    ['label' => 'Resync attempts', 'value' => self::formatAttempts(self::WIDGET_RESYNC_MAX_ATTEMPTS)],
                ],
                'label' => 'Stock widget',
            ],
        ];
    }

    private static function formatMilliseconds(int $value): string
    {
        return number_format($value).' ms';
    }

    private static function formatCharacters(int $value): string
    {
        return number_format($value).' characters';
    }

    private static function formatItems(int $value): string
    {
        return number_format($value).' items';
    }

    private static function formatRetained(int $value): string
    {
        return number_format($value).' retained';
    }

    private static function formatPending(int $value): string
    {
        return number_format($value).' pending';
    }

    private static function formatAttempts(int $value): string
    {
        return number_format($value).' '.str('attempt')->plural($value);
    }

    private static function formatBytes(int $value): string
    {
        return number_format($value).' bytes';
    }
}

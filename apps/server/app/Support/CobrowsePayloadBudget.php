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
}

<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

/**
 * Opt-in record of every candidate considered for each field, where it
 * came from, its score and why it won or lost. Values are shortened to
 * 200 characters so a trace never carries a whole page.
 */
final class Trace implements \JsonSerializable
{
    /** @var array<string, list<array{source: string, value: string, score: ?float, accepted: bool, reason: string}>> */
    private array $fields = [];

    /** @var array<string, string|int|float|bool> */
    private array $notes = [];

    public function candidate(
        string $field,
        Source|string $source,
        string $value,
        bool $accepted,
        string $reason,
        ?float $score = null,
    ): void {
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > 200) {
            $value = mb_substr($value, 0, 199, 'UTF-8') . "\u{2026}";
        }
        $this->fields[$field][] = [
            'source' => $source instanceof Source ? $source->value : $source,
            'value' => $value,
            'score' => $score,
            'accepted' => $accepted,
            'reason' => $reason,
        ];
    }

    public function note(string $key, string|int|float|bool $value): void
    {
        $this->notes[$key] = $value;
    }

    /** @return list<array{source: string, value: string, score: ?float, accepted: bool, reason: string}> */
    public function candidates(string $field): array
    {
        return $this->fields[$field] ?? [];
    }

    /** @return array<string, list<array{source: string, value: string, score: ?float, accepted: bool, reason: string}>> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, string|int|float|bool> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function toText(): string
    {
        $out = [];
        foreach ($this->notes as $k => $v) {
            $out[] = $k . ': ' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
        }
        foreach ($this->fields as $field => $cands) {
            $out[] = '';
            $out[] = '[' . $field . ']';
            foreach ($cands as $c) {
                $out[] = sprintf(
                    '  %s %-10s %s%s :: %s',
                    $c['accepted'] ? '+' : '-',
                    $c['source'],
                    $c['score'] !== null ? sprintf('(%.2f) ', $c['score']) : '',
                    $c['reason'],
                    str_replace("\n", ' ', $c['value']),
                );
            }
        }
        return implode("\n", $out) . "\n";
    }

    /** @return array{notes: array<string, string|int|float|bool>, fields: array<string, list<array<string, mixed>>>} */
    public function jsonSerialize(): array
    {
        return ['notes' => $this->notes, 'fields' => $this->fields];
    }
}

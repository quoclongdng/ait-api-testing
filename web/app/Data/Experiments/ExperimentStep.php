<?php

namespace App\Data\Experiments;

/**
 * One queued unit of an experiment (S11-02): each step runs in its own worker job so that no
 * single job exceeds the worker timeout (docs/DESIGN.md D10).
 *
 * - GENERATE: generate the suite of repetition r;
 * - VARIANT: reset and run the suite of repetition r on the clean variant (bugId = null) or on
 *   the single-bug variant of bugId, then apply D14;
 * - FINALIZE: aggregate the §11 metrics and complete the experiment.
 */
final readonly class ExperimentStep
{
    public const GENERATE = 'GENERATE';

    public const VARIANT = 'VARIANT';

    public const FINALIZE = 'FINALIZE';

    public function __construct(
        public string $kind,
        public int $repetition = 0,
        public ?int $bugId = null,
    ) {}

    public static function generate(int $repetition): self
    {
        return new self(self::GENERATE, $repetition);
    }

    public static function variant(int $repetition, ?int $bugId): self
    {
        return new self(self::VARIANT, $repetition, $bugId);
    }

    public static function finalize(): self
    {
        return new self(self::FINALIZE);
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::GENERATE => "generate r{$this->repetition}",
            self::VARIANT => "variant r{$this->repetition} ".($this->bugId === null ? 'clean' : "bug#{$this->bugId}"),
            default => 'finalize',
        };
    }
}

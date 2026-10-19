<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Tags\Enums\TagOperation;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * The test double `Tags::fake()` installs. It extends the manager, so injected managers keep
 * type-checking, and it records every successful mutation — from the facade, dependency
 * injection, handles, builders and model traits — while each call still runs for real.
 */
final class TagsFake extends TagsManager
{
    /** @var list<RecordedTagOperation> */
    private array $recorded = [];

    public function __construct(Container $container, TagTypeRegistry $registry)
    {
        parent::__construct($container, $registry);
    }

    public function perform(TagOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        $result = parent::perform($operation, $action, $execute, $context);

        $this->recorded[] = new RecordedTagOperation($operation, $context, $result);

        return $result;
    }

    /**
     * Every recorded operation, optionally of one kind, in call order.
     *
     * @return list<RecordedTagOperation>
     */
    public function recorded(?TagOperation $operation = null): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (RecordedTagOperation $recorded): bool => $operation === null || $recorded->operation === $operation,
        ));
    }

    public function assertNothingHappened(): void
    {
        PHPUnit::assertEmpty(
            $this->recorded,
            sprintf('Expected no tag operations, but %d were recorded.', count($this->recorded)),
        );
    }
}

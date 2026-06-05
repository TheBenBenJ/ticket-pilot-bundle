<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

/**
 * A single step of a browser test recipe.
 */
final readonly class RecipeStep
{
    public const VISIT = 'visit';
    public const CLICK = 'click';
    public const FILL = 'fill';
    public const WAIT_FOR = 'wait_for';
    public const ASSERT_SELECTOR = 'assert_selector';
    public const ASSERT_SEE = 'assert_see';
    public const ASSERT_NOT_SEE = 'assert_not_see';
    public const SCREENSHOT = 'screenshot';

    public const ACTIONS = [
        self::VISIT,
        self::CLICK,
        self::FILL,
        self::WAIT_FOR,
        self::ASSERT_SELECTOR,
        self::ASSERT_SEE,
        self::ASSERT_NOT_SEE,
        self::SCREENSHOT,
    ];

    public function __construct(
        public string $action,
        public ?string $target = null,
        public ?string $value = null,
    ) {
    }
}

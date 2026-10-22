<?php

namespace Tests\Unit;

use App\Support\ArabicDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The counted noun is where a translated product gives itself away. These cases
 * are the ones a reader notices immediately and a test suite never would.
 */
class ArabicDurationTest extends TestCase
{
    /** @return array<string, array{int, string}> */
    public static function spans(): array
    {
        return [
            'under a minute' => [0, 'أقل من دقيقة'],
            'one minute' => [1, 'دقيقة'],
            'two minutes' => [2, 'دقيقتين'],
            'five minutes' => [5, '5 دقائق'],
            'forty minutes' => [40, '40 دقيقة'],
            'one hour' => [60, 'ساعة'],
            'two hours' => [125, 'ساعتين'],
            'five hours' => [300, '5 ساعات'],
            'one day' => [1440, 'يوم'],
            'two days' => [2900, 'يومين'],
            'three days' => [4321, '3 أيام'],
            'twelve days' => [17_280, '12 يومًا'],
        ];
    }

    #[DataProvider('spans')]
    public function test_it_reads_the_way_a_person_would_say_it(int $minutes, string $expected): void
    {
        $this->assertSame($expected, ArabicDuration::fromMinutes($minutes));
    }

    /** Overdue is a direction, not a different span. */
    public function test_a_negative_span_reads_the_same(): void
    {
        $this->assertSame('3 أيام', ArabicDuration::fromMinutes(-4321));
    }

    /**
     * The dual must be oblique, because every sentence that uses it puts it
     * after a preposition or a verb. "متأخرة بـ ساعتان" shipped to the work
     * screen and reads like "overdue by two hour".
     */
    public function test_the_dual_is_the_form_that_follows_a_preposition(): void
    {
        $this->assertSame('ساعتين', ArabicDuration::fromMinutes(125));
        $this->assertSame('يومين', ArabicDuration::fromMinutes(2900));
        $this->assertSame('دقيقتين', ArabicDuration::fromMinutes(2));
    }

    /** The bug that prompted this: three days rendered as four thousand minutes. */
    public function test_a_long_overdue_follow_up_is_not_reported_in_minutes(): void
    {
        $this->assertStringNotContainsString('4321', ArabicDuration::fromMinutes(4321));
    }
}

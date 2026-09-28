<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Presentation\AccentColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The merchant's accent colour carries white text; one that cannot is not used.
 */
class AccentColorTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testAColourWithEnoughContrastIsKept(string $stored, string $expected): void
    {
        $this->assertSame($expected, AccentColor::sanitize($stored));
    }

    public static function accepted(): array
    {
        return [
            'the default' => ['#1f5eff', '#1f5eff'],
            'upper case is normalised' => ['#1F5EFF', '#1f5eff'],
            'short form is expanded' => ['#137', '#113377'],
            'surrounding whitespace is ignored' => ['  #137333 ', '#137333'],
            'black' => ['#000000', '#000000'],
        ];
    }

    #[DataProvider('rejected')]
    public function testAColourWithoutEnoughContrastOrShapeFallsBackToTheDefault(?string $stored): void
    {
        $this->assertSame(AccentColor::DEFAULT, AccentColor::sanitize($stored));
    }

    public static function rejected(): array
    {
        return [
            'unset' => [null],
            'empty' => [''],
            'white' => ['#ffffff'],
            'pale yellow' => ['#fff59d'],
            'light grey' => ['#cccccc'],
            'no hash' => ['1f5eff'],
            'not hex' => ['#12345g'],
            'named colour' => ['blue'],
            'rgb()' => ['rgb(0,0,0)'],
        ];
    }

    public function testTheContrastRuleIsWcag(): void
    {
        $this->assertEqualsWithDelta(21.0, AccentColor::contrastToWhite('#000000'), 0.01);
        $this->assertEqualsWithDelta(1.0, AccentColor::contrastToWhite('#ffffff'), 0.01);
        $this->assertGreaterThan(AccentColor::MIN_CONTRAST_TO_WHITE, AccentColor::contrastToWhite('#1f5eff'));
    }
}

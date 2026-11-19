<?php

namespace Tests\Unit\Integrations;

use App\Domain\Integrations\Leading;
use App\Domain\Integrations\Sync\Merge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The per-field rules of two-way sync, without a database: who changed what
 * since the last sync, and who wins a clash.
 */
class MergeTest extends TestCase
{
    /** @return array<string, array{0: array, 1: array, 2: ?array, 3: ?array, 4: Leading, 5: array, 6: array, 7: string}> */
    public static function cases(): array
    {
        $before = ['city' => 'zwolle', 'phone' => '038'];

        return [
            'nothing moved' => [$before, $before, $before, $before, Leading::External, [], [], 'unchanged'],
            'only they moved' => [$before, ['city' => 'kampen', 'phone' => '038'], $before, $before, Leading::Lavoro, ['city'], [], 'updated'],
            'only we moved' => [['city' => 'deventer', 'phone' => '038'], $before, $before, $before, Leading::External, [], ['city'], 'kept_ours'],
            'different fields moved' => [
                ['city' => 'deventer', 'phone' => '038'], ['city' => 'zwolle', 'phone' => '06'],
                $before, $before, Leading::External, ['phone'], ['city'], 'merged',
            ],
            'clash, they lead' => [
                ['city' => 'deventer', 'phone' => '038'], ['city' => 'kampen', 'phone' => '038'],
                $before, $before, Leading::External, ['city'], [], 'updated',
            ],
            'clash, we lead' => [
                ['city' => 'deventer', 'phone' => '038'], ['city' => 'kampen', 'phone' => '038'],
                $before, $before, Leading::Lavoro, [], ['city'], 'kept_ours',
            ],
            'both moved to the same value' => [
                ['city' => 'kampen', 'phone' => '038'], ['city' => 'kampen', 'phone' => '038'],
                $before, $before, Leading::Lavoro, [], [], 'unchanged',
            ],
            'no history, they lead: every difference is theirs' => [
                ['city' => 'deventer', 'phone' => '038'], ['city' => 'kampen', 'phone' => '038'],
                null, null, Leading::External, ['city'], [], 'updated',
            ],
            'no history, we lead: every difference is ours' => [
                ['city' => 'deventer', 'phone' => '038'], ['city' => 'kampen', 'phone' => '038'],
                null, null, Leading::Lavoro, [], ['city'], 'kept_ours',
            ],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_decides_per_field(array $ours, array $theirs, ?array $ours_before, ?array $theirs_before, Leading $leading, array $take, array $keep, string $outcome): void
    {
        $merge = Merge::decide($ours, $theirs, $ours_before, $theirs_before, $leading);

        $this->assertSame($take, $merge->take);
        $this->assertSame($keep, $merge->keep);
        $this->assertSame($outcome, $merge->outcome());
    }

    public function test_a_kept_field_keeps_its_old_baseline_so_the_next_send_still_sees_it(): void
    {
        $before = ['city' => 'zwolle', 'phone' => '038'];
        $merge = Merge::decide(['city' => 'deventer', 'phone' => '038'], ['city' => 'zwolle', 'phone' => '06'], $before, $before, Leading::External);

        $baseline = $merge->oursBaseline(['city' => 'deventer', 'phone' => '06'], ['city' => 'zwolle', 'phone' => '06'], $before);

        $this->assertSame(['city' => 'zwolle', 'phone' => '06'], $baseline);
    }

    public function test_without_history_a_kept_field_takes_their_value_as_baseline(): void
    {
        $merge = Merge::decide(['city' => 'deventer'], ['city' => 'kampen'], null, null, Leading::Lavoro);

        $this->assertSame(['city' => 'kampen'], $merge->oursBaseline(['city' => 'deventer'], ['city' => 'kampen'], null));
    }
}

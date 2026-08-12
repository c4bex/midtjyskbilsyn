<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class DanishHolidayService
{
    /**
     * @return array<int, array{key: string, name: string, date: string}>
     */
    public function forYear(int $year): array
    {
        $easter = $this->easterSunday($year);

        return [
            $this->holiday('new-year', 'Nytårsdag', CarbonImmutable::create($year, 1, 1)),
            $this->holiday('maundy-thursday', 'Skærtorsdag', $easter->subDays(3)),
            $this->holiday('good-friday', 'Langfredag', $easter->subDays(2)),
            $this->holiday('easter-sunday', 'Påskedag', $easter),
            $this->holiday('easter-monday', '2. påskedag', $easter->addDay()),
            $this->holiday('ascension-day', 'Kristi himmelfartsdag', $easter->addDays(39)),
            $this->holiday('whit-sunday', 'Pinsedag', $easter->addDays(49)),
            $this->holiday('whit-monday', '2. pinsedag', $easter->addDays(50)),
            $this->holiday('christmas-day', '1. juledag', CarbonImmutable::create($year, 12, 25)),
            $this->holiday('boxing-day', '2. juledag', CarbonImmutable::create($year, 12, 26)),
        ];
    }

    /**
     * Meeus/Jones/Butcher-algoritmen for gregoriansk påske.
     */
    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }

    /**
     * @return array{key: string, name: string, date: string}
     */
    private function holiday(string $key, string $name, CarbonImmutable $date): array
    {
        return ['key' => $key, 'name' => $name, 'date' => $date->toDateString()];
    }
}

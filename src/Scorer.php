<?php
declare(strict_types=1);

/**
 * Перетворює статуси перевірок у бали за вагами з config.
 * pass = 100% ваги, warn = 50%, fail = 0%, na = виключається (бали категорії
 * перераховуються пропорційно з решти перевірок цієї категорії).
 */
final class Scorer
{
    private const FACTOR = ['pass' => 1.0, 'warn' => 0.5, 'fail' => 0.0];

    /** @var array<string, array{max: int|float}> */
    private array $categories;

    /** @var array<string, array{category: string, weight: int|float}> */
    private array $weights = [];

    public function __construct(array $config)
    {
        $this->categories = $config['categories'];
        foreach ($config['checks'] as $c) {
            $this->weights[$c['id']] = ['category' => $c['category'], 'weight' => $c['weight']];
        }
    }

    /**
     * @param list<array{id: string, status: string, data?: array}> $checks
     * @return array{score: int, grade: string, categories: list<array<string,mixed>>,
     *               checks: list<array<string,mixed>>}
     */
    public function score(array $checks): array
    {
        $byCat = [];
        foreach ($this->categories as $cat => $_) {
            $byCat[$cat] = ['weight_active' => 0.0, 'earned' => 0.0];
        }

        $detailed = [];
        foreach ($checks as $check) {
            $meta = $this->weights[$check['id']] ?? null;
            if ($meta === null) {
                continue;
            }
            $cat = $meta['category'];
            $weight = (float) $meta['weight'];
            $status = $check['status'];

            if ($status === 'na') {
                $points = null;
                $max = null;
            } else {
                $factor = self::FACTOR[$status] ?? 0.0;
                $byCat[$cat]['weight_active'] += $weight;
                $byCat[$cat]['earned'] += $weight * $factor;
                $points = null; // заповнимо після масштабування категорії
                $max = $weight;
            }

            $detailed[] = [
                'id' => $check['id'],
                'category' => $cat,
                'status' => $status,
                'weight' => $weight,
                'max' => $max,
                'points' => $points,
                'data' => $check['data'] ?? [],
            ];
        }

        $categories = [];
        $total = 0.0;
        foreach ($this->categories as $cat => $info) {
            $catMax = (float) $info['max'];
            $active = $byCat[$cat]['weight_active'];
            $earned = $byCat[$cat]['earned'];
            // Масштабуємо до max категорії по активних (не-na) вагах.
            $catScore = $active > 0 ? ($earned / $active) * $catMax : 0.0;
            $scale = $active > 0 ? $catMax / $active : 0.0;

            foreach ($detailed as &$d) {
                if ($d['category'] === $cat && $d['status'] !== 'na') {
                    $factor = self::FACTOR[$d['status']] ?? 0.0;
                    $d['points'] = round($d['weight'] * $factor * $scale, 2);
                    $d['max'] = round($d['weight'] * $scale, 2);
                }
            }
            unset($d);

            $categories[] = [
                'id' => $cat,
                'score' => (int) round($catScore),
                'max' => (int) round($catMax),
                'all_na' => $active === 0.0,
            ];
            $total += $catScore;
        }

        $score = (int) round($total);
        return [
            'score' => $score,
            'grade' => $this->grade($score),
            'categories' => $categories,
            'checks' => $detailed,
        ];
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 50 => 'C',
            $score >= 30 => 'D',
            default => 'F',
        };
    }
}

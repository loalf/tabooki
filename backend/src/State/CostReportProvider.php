<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CostCall;
use App\ApiResource\CostGroup;
use App\ApiResource\CostMonth;
use App\ApiResource\CostReport;
use App\ApiResource\CostTotal;
use App\Security\CurrentHousehold;
use Doctrine\DBAL\Connection;

/**
 * Adds up extraction_run for the signed-in household, in SQL (a household makes a few thousand
 * calls a year at most).
 *
 * @implements ProviderInterface<CostReport>
 */
final readonly class CostReportProvider implements ProviderInterface
{
    private const int MONTHS = 6;
    private const int RECENT = 20;

    public function __construct(
        private Connection $connection,
        private CurrentHousehold $current,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CostReport
    {
        $household = $this->current->household()->getId()->toRfc4122();
        $thisMonth = new \DateTimeImmutable('first day of this month 00:00', new \DateTimeZone('UTC'));
        $report = new CostReport();

        $report->allTime = $this->total($household);
        $report->thisMonth = $this->total($household, $thisMonth);
        $report->lastMonth = $this->total($household, $thisMonth->modify('-1 month'), $thisMonth);

        $since = $thisMonth->modify(\sprintf('-%d months', self::MONTHS - 1));
        /** @var array<string, array{usd: float|string, calls: int|string}> $byMonth */
        $byMonth = $this->connection->fetchAllAssociativeIndexed(
            <<<'SQL'
                SELECT to_char(created_at, 'YYYY-MM') AS month, COALESCE(SUM(cost_usd), 0) AS usd, COUNT(*) AS calls
                FROM extraction_run WHERE household_id = :h AND created_at >= :since
                GROUP BY 1
                SQL,
            ['h' => $household, 'since' => $since->format('Y-m-d H:i:s')],
        );
        for ($month = $since; $month <= $thisMonth; $month = $month->modify('+1 month')) {
            $line = new CostMonth();
            $line->month = $month->format('Y-m');
            $line->usd = round((float) ($byMonth[$line->month]['usd'] ?? 0), 6);
            $line->calls = (int) ($byMonth[$line->month]['calls'] ?? 0);
            $report->months[] = $line;
        }

        $report->byStep = $this->groups($household, 'step');
        $report->byModel = $this->groups($household, 'model');

        /** @var array{links: int|string, usd: float|string|null} $links */
        $links = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(DISTINCT r.source_id) AS links, SUM(r.cost_usd) AS usd
                FROM extraction_run r
                WHERE r.household_id = :h AND r.step IN ('text', 'video')
                  AND EXISTS (SELECT 1 FROM recipe WHERE recipe.source_id = r.source_id AND recipe.extracted_by IS NOT NULL)
                SQL,
            ['h' => $household],
        );
        $report->linksExtracted = (int) $links['links'];
        $report->perLink = $report->linksExtracted > 0 && null !== $links['usd'] ? round((float) $links['usd'] / $report->linksExtracted, 6) : null;

        /** @var int|string $unpriced */
        $unpriced = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM extraction_run WHERE household_id = :h AND cost_usd IS NULL AND (input_tokens > 0 OR output_tokens > 0)',
            ['h' => $household],
        );
        $report->unpricedCalls = (int) $unpriced;

        /** @var list<array{created_at: string, step: string, model: string, input_tokens: int|string, output_tokens: int|string, cost_usd: float|string|null, ok: bool, error: string|null, source_title: string|null, source_url: string|null, recipe_id: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT r.created_at, r.step, r.model, r.input_tokens, r.output_tokens, r.cost_usd, r.ok, r.error,
                       s.title AS source_title, s.url AS source_url,
                       (SELECT recipe.id FROM recipe WHERE recipe.source_id = s.id ORDER BY recipe.created_at LIMIT 1) AS recipe_id
                FROM extraction_run r LEFT JOIN source s ON s.id = r.source_id
                WHERE r.household_id = :h
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT :limit
                SQL,
            ['h' => $household, 'limit' => self::RECENT],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
        foreach ($rows as $row) {
            $call = new CostCall();
            $call->at = new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC'));
            $call->step = $row['step'];
            $call->model = $row['model'];
            $call->inputTokens = (int) $row['input_tokens'];
            $call->outputTokens = (int) $row['output_tokens'];
            $call->usd = null === $row['cost_usd'] ? null : (float) $row['cost_usd'];
            $call->ok = $row['ok'];
            $call->error = $row['error'];
            $call->sourceTitle = $row['source_title'];
            $call->sourceUrl = $row['source_url'];
            $call->recipeId = $row['recipe_id'];
            $report->recent[] = $call;
        }

        return $report;
    }

    private function total(string $household, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $until = null): CostTotal
    {
        /** @var array{usd: float|string, calls: int|string, input_tokens: int|string, output_tokens: int|string} $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COALESCE(SUM(cost_usd), 0) AS usd, COUNT(*) AS calls,
                       COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens
                FROM extraction_run
                WHERE household_id = :h AND (CAST(:from AS timestamp) IS NULL OR created_at >= :from)
                  AND (CAST(:until AS timestamp) IS NULL OR created_at < :until)
                SQL,
            ['h' => $household, 'from' => $from?->format('Y-m-d H:i:s'), 'until' => $until?->format('Y-m-d H:i:s')],
        );
        $total = new CostTotal();
        $total->usd = round((float) $row['usd'], 6);
        $total->calls = (int) $row['calls'];
        $total->inputTokens = (int) $row['input_tokens'];
        $total->outputTokens = (int) $row['output_tokens'];

        return $total;
    }

    /**
     * @param 'step'|'model' $column
     *
     * @return list<CostGroup> most expensive first
     */
    private function groups(string $household, string $column): array
    {
        /** @var list<array{name: string, usd: float|string, calls: int|string, failed: int|string, input_tokens: int|string, output_tokens: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT {$column} AS name, COALESCE(SUM(cost_usd), 0) AS usd, COUNT(*) AS calls, COUNT(*) FILTER (WHERE NOT ok) AS failed,
                       COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens
                FROM extraction_run WHERE household_id = :h
                GROUP BY {$column} ORDER BY usd DESC, calls DESC
                SQL,
            ['h' => $household],
        );

        return array_map(static function (array $row): CostGroup {
            $group = new CostGroup();
            $group->name = $row['name'];
            $group->usd = round((float) $row['usd'], 6);
            $group->calls = (int) $row['calls'];
            $group->failed = (int) $row['failed'];
            $group->inputTokens = (int) $row['input_tokens'];
            $group->outputTokens = (int) $row['output_tokens'];

            return $group;
        }, $rows);
    }
}

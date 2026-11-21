<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\CostReportProvider;

/**
 * What the household's AI calls have cost (extracting links, sorting ingredients), in USD at
 * list price (config/services.yaml app.llm_prices). Months are calendar months in UTC.
 */
#[ApiResource(
    shortName: 'Costs',
    operations: [new Get(uriTemplate: '/costs', uriVariables: [], provider: CostReportProvider::class)],
    security: "is_granted('ROLE_USER')",
)]
final class CostReport
{
    #[ApiProperty(identifier: true)]
    public string $id = 'costs';

    public CostTotal $thisMonth;

    public CostTotal $lastMonth;

    public CostTotal $allTime;

    /** @var list<CostMonth> the last 6 months, oldest first, months without calls included */
    public array $months = [];

    /** @var list<CostGroup> by step: text, video, classify */
    public array $byStep = [];

    /** @var list<CostGroup> */
    public array $byModel = [];

    /** Links that got at least one recipe from extraction. */
    public int $linksExtracted = 0;

    /** Average extraction cost (text + video, failed calls included) of those links. */
    public ?float $perLink = null;

    /** Calls whose model has no price configured: not in the totals. */
    public int $unpricedCalls = 0;

    /** @var list<CostCall> newest first */
    public array $recent = [];

    public function __construct()
    {
        $this->thisMonth = new CostTotal();
        $this->lastMonth = new CostTotal();
        $this->allTime = new CostTotal();
    }
}

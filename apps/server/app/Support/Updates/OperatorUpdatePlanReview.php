<?php

declare(strict_types=1);

namespace App\Support\Updates;

/** Public release declarations and local guard evidence, bound to one target. */
class OperatorUpdatePlanReview
{
    public function __construct(private ReleaseCatalogClient $catalogs, private UpdatePlanner $planner) {}

    /** @return array<string, mixed> */
    public function build(string $tag, InstallationCapabilities $installation): array
    {
        return $this->planner->build($this->catalogs->fetch($tag), $installation)->toArray();
    }
}

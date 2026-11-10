<?php

namespace Elcreator\aDataForSeo\Api;

/**
 * Who a call is for and within which limits: the project and job it is
 * charged to, the caps that apply and whether a manager already approved an
 * unknown or large estimate.
 */
final class Context
{
    public function __construct(
        public readonly ?int $projectId = null,
        public readonly ?int $jobId = null,
        public readonly ?int $stepId = null,
        public readonly ?int $userId = null,
        public readonly ?float $jobCap = null,
        public readonly ?float $monthlyCap = null,
        public readonly bool $approved = false,
        public readonly int $priority = 1,
        public readonly ?float $balance = null
    ) {
    }

    public function withStep(?int $stepId): self
    {
        return new self($this->projectId, $this->jobId, $stepId, $this->userId, $this->jobCap, $this->monthlyCap, $this->approved, $this->priority, $this->balance);
    }

    public function approved(bool $approved = true): self
    {
        return new self($this->projectId, $this->jobId, $this->stepId, $this->userId, $this->jobCap, $this->monthlyCap, $approved, $this->priority, $this->balance);
    }
}

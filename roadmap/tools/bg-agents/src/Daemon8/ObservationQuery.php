<?php

declare(strict_types=1);

namespace BgAgents\Daemon8;

final readonly class ObservationQuery
{
    /**
     * @param list<string> $kinds       e.g. ['custom', 'log']
     * @param list<string> $tags        all-must-match tags
     * @param list<string> $origins     e.g. ['app:bg-agents', 'device:vega']
     * @param ?string $textMatch        substring search in data
     * @param ?string $correlationId    exact match
     * @param ?int $since               daemon8 checkpoint id; only newer obs returned
     * @param int $limit                capped at 500 by daemon8
     * @param ?string $severityMin      trace|debug|info|warn|error
     * @param bool $includeSystem
     */
    public function __construct(
        public array $kinds = [],
        public array $tags = [],
        public array $origins = [],
        public ?string $textMatch = null,
        public ?string $correlationId = null,
        public ?int $since = null,
        public int $limit = 50,
        public ?string $severityMin = null,
        public bool $includeSystem = false,
    ) {}

    public function toQueryString(): string
    {
        $params = [];

        if ($this->kinds !== []) {
            $params['kinds'] = implode(',', $this->kinds);
        }
        if ($this->tags !== []) {
            $params['tags'] = implode(',', $this->tags);
        }
        if ($this->origins !== []) {
            $params['origins'] = implode(',', $this->origins);
        }
        if ($this->textMatch !== null) {
            $params['text_match'] = $this->textMatch;
        }
        if ($this->correlationId !== null) {
            $params['correlation_id'] = $this->correlationId;
        }
        if ($this->since !== null) {
            $params['since'] = (string) $this->since;
        }
        if ($this->severityMin !== null) {
            $params['severity_min'] = $this->severityMin;
        }
        if ($this->includeSystem) {
            $params['include_system'] = 'true';
        }
        $params['limit'] = (string) min($this->limit, 500);

        return http_build_query($params);
    }
}

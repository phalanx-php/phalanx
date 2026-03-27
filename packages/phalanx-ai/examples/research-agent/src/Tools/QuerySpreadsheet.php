<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

/**
 * Runs calculations or lookups against a CSV/Excel file.
 *
 * Spawns a DataAnalyst sub-agent to interpret the spreadsheet data
 * and answer the specific query.
 */
final class QuerySpreadsheet implements Tool
{
    public string $description {
        get => 'Run calculations or lookups against a CSV/Excel file';
    }

    public function __construct(
        #[Param('Path to the spreadsheet file')]
        private readonly string $filePath,
        #[Param('Natural language description of the calculation or lookup')]
        private readonly string $query,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        // In production:
        //   $data = $scope->service(SpreadsheetParser::class)->load($this->filePath);
        //   $analysis = AgentResult::awaitFrom($scope->execute(
        //       Turn::begin(new DataAnalyst())
        //           ->message(Message::user("Query: {$this->query}\n\nHeaders: ..."))
        //           ->output(SpreadsheetResult::class)
        //   ));

        return ToolOutcome::data([
            'file' => $this->filePath,
            'query' => $this->query,
            'result' => 'Query result placeholder',
        ]);
    }
}

<?php

use Elcreator\aDataForSeo\Mcp\Operations;
use Elcreator\aDataForSeo\Mcp\ToolProvider;
use Elcreator\aDataForSeo\Mcp\Tools\ApplyFixTool;
use Elcreator\aDataForSeo\Mcp\Tools\AuditTool;
use Elcreator\aDataForSeo\Mcp\Tools\CreateDraftTool;
use Elcreator\aDataForSeo\Mcp\Tools\EstimateTool;
use Elcreator\aDataForSeo\Mcp\Tools\MatrixTool;
use Elcreator\aDataForSeo\Mcp\Tools\RankingsTool;
use Elcreator\aDataForSeo\Mcp\Tools\SerpTool;
use Elcreator\aDataForSeo\Mcp\Tools\StartJobTool;
use Elcreator\aDataForSeo\Mcp\Tools\UndoChangeTool;
use Elcreator\aDataForSeo\Models\Job;
use EvolutionCMS\eMCP\Contracts\DestructiveSite;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

require_once dirname(__DIR__) . '/fixtures/mcp.php';

afterEach(fn () => Mockery::close());

it('advertises every supported argument with its schema type and required flag', function (string $class, array $fields, string $required) {
    $schema = Mockery::mock(JsonSchema::class);
    $properties = [];

    foreach ($fields as $name => $type) {
        $property = Mockery::mock();
        $property->shouldReceive('description')->once()->with(Mockery::type('string'))->andReturnSelf();
        $property->shouldReceive('required')->times($name === $required ? 1 : 0)->andReturnSelf();

        if ($name === 'kind') {
            $property->shouldReceive('enum')->once()->with(Job::KINDS)->andReturnSelf();
        }

        if ($name === 'parent') {
            $property->shouldReceive('min')->once()->with(0)->andReturnSelf();
        }

        $schema->shouldReceive($type)->once()->withNoArgs()->andReturn($property);
        $properties[$name] = $property;
    }

    expect((new $class())->schema($schema))->toBe($properties);
})->with([
    'apply overrides' => [ApplyFixTool::class, ['issue' => 'integer', 'fields' => 'object', 'new_url' => 'string', 'preview' => 'boolean'], 'issue'],
    'estimate options' => [EstimateTool::class, ['kind' => 'string', 'options' => 'object'], 'kind'],
    'draft inputs' => [CreateDraftTool::class, ['target' => 'integer', 'parent' => 'integer', 'pagetitle' => 'string', 'preview' => 'boolean'], 'target'],
    'matrix filters' => [MatrixTool::class, ['country' => 'string', 'lang' => 'string', 'tier' => 'string', 'status' => 'string'], ''],
    'SERP target' => [SerpTool::class, ['target' => 'integer'], 'target'],
    'start options' => [StartJobTool::class, ['kind' => 'string', 'options' => 'object'], 'kind'],
    'undo id' => [UndoChangeTool::class, ['change' => 'integer'], 'change'],
]);

it('advertises the saved reports as read-only with structured results', function (string $class, array $fields) {
    $reflection = new ReflectionClass($class);
    $property = Mockery::mock();
    $property->shouldReceive('description', 'enum', 'items', 'nullable', 'required')->andReturnSelf();
    $schema = Mockery::mock(JsonSchema::class);
    $schema->shouldReceive('object', 'array', 'string', 'integer', 'number')->andReturn($property);

    expect($reflection->getAttributes(IsReadOnly::class))->toHaveCount(1)
        ->and(array_keys((new $class())->outputSchema($schema)))->toBe($fields);
})->with([
    'audit' => [AuditTool::class, ['cards']],
    'matrix' => [MatrixTool::class, ['targets', 'totals']],
    'rankings' => [RankingsTool::class, ['dates', 'rows']],
    'SERP' => [SerpTool::class, ['target']],
]);

it('registers the mutations behind the write gate and routes them to service operations', function () {
    $registered = (new ToolProvider())->tools();

    foreach ([ApplyFixTool::class => 'applyFix', CreateDraftTool::class => 'createDraft', StartJobTool::class => 'startJob', UndoChangeTool::class => 'undoChange'] as $class => $operation) {
        expect($registered)->toContain($class)
            ->and(is_subclass_of($class, WritesSite::class))->toBeTrue()
            ->and((new ReflectionMethod($class, 'operation'))->invoke(new $class()))->toBe($operation)
            ->and(method_exists(Operations::class, $operation))->toBeTrue();
    }

    expect(is_subclass_of(EstimateTool::class, WritesSite::class))->toBeFalse()
        ->and(is_subclass_of(UndoChangeTool::class, DestructiveSite::class))->toBeTrue();
});

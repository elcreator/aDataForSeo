<?php

namespace Elcreator\aDataForSeo\Mcp;

use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool as McpTool;

/**
 * An MCP tool over {@see Operations}. eMCP runs the request as the token
 * owner, so `Actor::current()` is that manager; no tool takes a user argument.
 * Loaded only when eMCP (and Laravel MCP) is installed.
 */
abstract class Tool extends McpTool
{
    abstract protected function operation(): string;

    public function handle(Request $request): ResponseFactory
    {
        try {
            return Response::structured(Operations::{$this->operation()}(Actor::current(), $request->all()));
        } catch (WorkbenchException $e) {
            throw ValidationException::withMessages($e->errors ?: [$e->error => Operations::explain($e)]);
        }
    }
}

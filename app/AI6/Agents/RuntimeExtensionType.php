<?php

namespace App\AI6\Agents;

enum RuntimeExtensionType: string
{
    case MCP_SERVERS = 'mcp_servers';
    case PLUGINS = 'plugins';
    case SKILLS = 'skills';
    case HOOKS = 'hooks';
    case COMMANDS = 'commands';
    case AGENT_DEFINITIONS = 'agent_definitions';
    case EXTERNAL_HELPERS = 'external_helpers';
}

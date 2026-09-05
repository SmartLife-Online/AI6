<?php

namespace Tests\Feature\Runs;

use App\AI6\Runs\Console\FakeAgentReleaseGateCommand;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use RuntimeException;

/** Source inventory only; unresolved entries never count as AC-02 evidence. */
final class ReleaseGateWriteAudit
{
    private const TABLES = ['runs', 'ticket_approvals', 'ticket_read_models', 'human_requests', 'interventions', 'execution_jobs', 'scope_decisions', 'run_gates'];

    private const MODELS = ['Run', 'TicketApproval', 'TicketReadModel', 'HumanRequest', 'Intervention', 'ExecutionJob', 'ScopeDecision', 'RunGate'];

    /** @return list<string> */
    public static function sourceFiles(): array
    {
        $files = [];
        $root = str_replace('\\', '/', base_path()).'/';
        foreach (FakeAgentReleaseGateCommand::TEST_PATHS as $path) {
            $name = 'Tests\\'.str_replace('/', '\\', substr($path, 6, -4));
            if (! class_exists($name)) {
                throw new RuntimeException('A bound release test class is missing.');
            }
            $class = new ReflectionClass($name);
            do {
                $candidates = [$class->getFileName()];
                foreach ($class->getMethods() as $method) {
                    $candidates[] = $method->getFileName();
                }
                foreach ($candidates as $candidate) {
                    if (is_string($candidate)) {
                        $normalized = str_replace('\\', '/', $candidate);
                        if (str_starts_with($normalized, $root.'tests/')) {
                            $files[] = substr($normalized, strlen($root));
                        }
                    }
                }
                $class = $class->getParentClass();
            } while ($class !== false);
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /** @return array<string, array{target: string, code: string, context_sha256: string}> */
    public static function inventory(): array
    {
        $entries = [];
        foreach (self::sourceFiles() as $path) {
            $source = file_get_contents(base_path($path));
            if (! is_string($source)) {
                throw new RuntimeException('A release test source cannot be read.');
            }
            foreach (self::scan($source) as $id => $entry) {
                $entries[$path.'::'.$id] = $entry;
            }
        }
        ksort($entries);

        return $entries;
    }

    /** @return array<string, array{target: string, code: string, context_sha256: string}> */
    public static function scan(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse(str_replace("\r\n", "\n", $source)) ?? [];
        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var array<string, array{target: string, code: string, context_sha256: string}> */
            public array $entries = [];

            /** @var list<Stmt\ClassMethod> */
            private array $methods = [];

            /** @var array<string, int> */
            private array $counts = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassMethod) {
                    $this->methods[] = $node;
                }
                $target = ReleaseGateWriteAudit::target($node);
                if ($target !== null) {
                    $method = $this->methods[array_key_last($this->methods)] ?? null;
                    $name = $method?->name->toString() ?? '<file>';
                    $number = $this->counts[$name] = ($this->counts[$name] ?? 0) + 1;
                    $printer = new Standard;
                    $this->entries[$name.'#'.$number] = [
                        'target' => $target,
                        'code' => $printer->prettyPrint([$node]),
                        'context_sha256' => hash('sha256', $printer->prettyPrint($method === null ? [$node] : [$method])),
                    ];
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassMethod) {
                    array_pop($this->methods);
                }

                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($nodes);

        return $visitor->entries;
    }

    public static function target(Node $node): ?string
    {
        if (! ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall || $node instanceof Expr\NullsafeMethodCall)
            || ! $node->name instanceof Node\Identifier) {
            return null;
        }
        $name = strtolower($node->name->toString());
        if (! in_array($name, ['create', 'insert', 'insertgetid', 'insertorignore', 'update', 'upsert', 'updateorcreate', 'firstorcreate', 'delete', 'forcedelete', 'save', 'savequietly', 'updatequietly', 'increment', 'decrement', 'statement', 'unprepared'], true)) {
            return null;
        }
        $root = $node;
        while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
            $root = $root->var;
        }
        if ($root instanceof Expr\StaticCall && $root->class instanceof Node\Name) {
            $class = $root->class->getLast();
            if (in_array($class, self::MODELS, true)) {
                return $class;
            }
            if ($root->class->toString() === 'Illuminate\\Support\\Facades\\DB' && $root->name instanceof Node\Identifier) {
                $argument = $root->args[0] ?? null;
                if ($root->name->toString() === 'table' && $argument instanceof Node\Arg && $argument->value instanceof Node\Scalar\String_) {
                    return in_array($argument->value->value, self::TABLES, true) ? $argument->value->value : null;
                }
                if (in_array($name, ['statement', 'unprepared'], true)) {
                    $sql = (new Standard)->prettyPrintExpr($root);
                    if (preg_match('/\b(?:UPDATE|INTO|FROM)\s+["`]?('.implode('|', self::TABLES).')\b/i', $sql, $matches) === 1) {
                        return $matches[1];
                    }
                }
            }

            return null;
        }

        // Unknown instance receivers require inspection, never a silent pass.
        // forceFill alone is an in-memory mutation; save/update is the write.
        return in_array($name, ['insert', 'insertgetid', 'insertorignore', 'upsert', 'updateorcreate', 'firstorcreate', 'save', 'savequietly', 'update', 'updatequietly', 'delete', 'forcedelete', 'increment', 'decrement'], true)
            ? 'instance_receiver_requires_review' : null;
    }
}

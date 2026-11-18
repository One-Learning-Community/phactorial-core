<?php

declare(strict_types=1);

namespace Phactorial\Tests\Api;

/**
 * Records the public surface of a source tree as sorted text lines, one per public, non-internal symbol.
 *
 * The test suites compare the result with a committed api.txt, so every change to the public API shows up as a diff.
 */
final class ApiManifest
{
    public static function build(string $srcDir, string $namespace): string
    {
        $root = realpath($srcDir);
        if ($root === false) {
            throw new \InvalidArgumentException("Source directory not found: $srcDir");
        }
        $prefix = rtrim($namespace, '\\') . '\\';
        $lines = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = $prefix . str_replace('/', '\\', $relative);
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if (self::isInternal($reflection->getDocComment())) {
                continue;
            }
            array_push($lines, ...self::describeClass($reflection));
        }
        sort($lines);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param \ReflectionClass<object> $class
     * @return list<string>
     */
    private static function describeClass(\ReflectionClass $class): array
    {
        $name = $class->getName();
        $lines = [$name . ' | ' . self::classDetails($class)];

        foreach ($class->getReflectionConstants() as $constant) {
            if (!$constant->isPublic() || $constant->getDeclaringClass()->getName() !== $name || self::isInternal($constant->getDocComment())) {
                continue;
            }
            $value = $constant->getValue();
            $lines[] = $constant->isEnumCase()
                ? $name . '::' . $constant->getName() . ' | case' . ($value instanceof \BackedEnum ? ' = ' . self::export($value->value) : '')
                : $name . '::' . $constant->getName() . ' | ' . ($constant->isFinal() ? 'final ' : '') . 'const ' . (($constantType = $constant->getType()) === null ? '' : self::type($constantType, $constant->getDeclaringClass()) . ' ') . '= ' . self::export($value);
        }

        foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $name || ($class->isEnum() && in_array($property->getName(), ['name', 'value'], true)) || self::isInternal($property->getDocComment())) {
                continue;
            }
            $lines[] = $name . '::$' . $property->getName() . ' | ' . self::propertyDetails($property);
        }

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $name || $method->getFileName() === false || self::isInternal($method->getDocComment())) {
                continue;
            }
            $lines[] = $name . '::' . $method->getName() . '() | ' . self::methodDetails($method);
        }

        return $lines;
    }

    /** @param \ReflectionClass<object> $class */
    private static function classDetails(\ReflectionClass $class): string
    {
        $kind = match (true) {
            $class->isEnum() => 'enum',
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            default => 'class',
        };
        $parts = [];
        if ($class->isAbstract() && $kind === 'class') {
            $parts[] = 'abstract';
        }
        if ($class->isFinal() && $kind === 'class') {
            $parts[] = 'final';
        }
        if ($class->isReadOnly()) {
            $parts[] = 'readonly';
        }
        $parts[] = $kind;
        $parent = $class->getParentClass();
        if ($parent !== false) {
            $parts[] = 'extends ' . $parent->getName();
        }
        $interfaces = $class->getInterfaceNames();
        sort($interfaces);
        if ($interfaces !== []) {
            $parts[] = ($kind === 'interface' ? 'extends ' : 'implements ') . implode(', ', $interfaces);
        }

        return implode(' ', $parts);
    }

    private static function propertyDetails(\ReflectionProperty $property): string
    {
        $parts = [];
        if ($property->isStatic()) {
            $parts[] = 'static';
        }
        if ($property->isReadOnly()) {
            $parts[] = 'readonly';
        }
        if ($property->isPrivateSet()) {
            $parts[] = 'private(set)';
        } elseif ($property->isProtectedSet()) {
            $parts[] = 'protected(set)';
        }
        $hooks = [];
        foreach ([\PropertyHookType::Get, \PropertyHookType::Set] as $type) {
            if ($property->hasHook($type)) {
                $hooks[] = $type->value;
            }
        }
        if ($hooks !== []) {
            $parts[] = 'hooks{' . implode(',', $hooks) . '}';
        }
        $parts[] = ($property->getType() === null ? 'mixed' : self::type($property->getType(), $property->getDeclaringClass()));
        if ($property->hasDefaultValue() && !($property->getType() === null && $property->getDefaultValue() === null)) {
            $parts[] = '= ' . self::export($property->getDefaultValue());
        }

        return implode(' ', $parts) . self::phpdoc($property->getDocComment(), ['var']);
    }

    private static function methodDetails(\ReflectionMethod $method): string
    {
        $params = [];
        foreach ($method->getParameters() as $parameter) {
            $text = ($parameter->getType() === null ? '' : self::type($parameter->getType(), $method->getDeclaringClass()) . ' ')
                . ($parameter->isPassedByReference() ? '&' : '')
                . ($parameter->isVariadic() ? '...' : '')
                . '$' . $parameter->getName();
            if ($parameter->isDefaultValueAvailable()) {
                $text .= ' = ' . ($parameter->isDefaultValueConstant()
                    ? (string) $parameter->getDefaultValueConstantName()
                    : self::export($parameter->getDefaultValue()));
            }
            $params[] = $text;
        }
        $text = ($method->isAbstract() ? 'abstract ' : '') . ($method->isFinal() ? 'final ' : '') . ($method->isStatic() ? 'static ' : '') . '(' . implode(', ', $params) . ')';
        if ($method->hasReturnType()) {
            $text .= ': ' . self::type($method->getReturnType(), $method->getDeclaringClass());
        }

        return $text . self::phpdoc($method->getDocComment(), ['param', 'return']);
    }

    /**
     * Renders a type the same way on every PHP version: PHP 8.5 resolves self to the class name, 8.4 keeps self.
     * A parent type cannot be told from the written parent class name on 8.5, so none of the sources use it.
     *
     * @param \ReflectionClass<object> $declaring
     */
    private static function type(\ReflectionType $type, \ReflectionClass $declaring): string
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            if ($name === $declaring->getName()) {
                $name = 'self';
            }

            return ($type->allowsNull() && !in_array($name, ['mixed', 'null'], true) ? '?' : '') . $name;
        }
        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(
                static fn(\ReflectionType $part): string => $part instanceof \ReflectionIntersectionType
                    ? '(' . self::type($part, $declaring) . ')'
                    : self::type($part, $declaring),
                $type->getTypes(),
            ));
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(static fn(\ReflectionType $part): string => self::type($part, $declaring), $type->getTypes()));
        }

        return (string) $type;
    }

    private static function export(mixed $value): string
    {
        return (string) preg_replace('/\s+/', ' ', var_export($value, true));
    }

    private static function isInternal(string|false $doc): bool
    {
        return $doc !== false && preg_match('/^\s*(?:\/\*\*|\*)?\s*@internal\b/m', $doc) === 1;
    }

    /**
     * Extracts the type text of the given tags, so shapes such as list<array{int, int}> are recorded.
     *
     * @param list<string> $tags
     */
    private static function phpdoc(string|false $doc, array $tags): string
    {
        if ($doc === false) {
            return '';
        }
        $text = (string) preg_replace('/^[ \t]*(?:\/\*\*|\*\/|\*)[ \t]?/m', '', $doc);
        $found = [];
        $pattern = '/(?:^|\s)@(' . implode('|', $tags) . ')[ \t]+/';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return '';
        }
        foreach ($matches[1] as $i => [$tag]) {
            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = 0;
            $type = self::readType($text, $start, $end);
            $entry = '@' . $tag . ' ';
            if ($tag === 'param' && preg_match('/\G\s*(\.\.\.)?(&)?(\$\w+)/', $text, $name, 0, $end) === 1) {
                $entry .= $name[3] . ' ';
            }
            $found[] = $entry . $type;
        }

        return $found === [] ? '' : ' ; ' . implode(' ; ', $found);
    }

    private static function readType(string $text, int $start, int &$end): string
    {
        $depth = 0;
        $length = strlen($text);
        $i = $start;
        while ($i < $length) {
            $char = $text[$i];
            if (str_contains('([{<', $char)) {
                $depth++;
            } elseif (str_contains(')]}>', $char) && !($char === '>' && $i > 0 && $text[$i - 1] === '=')) {
                $depth--;
            } elseif ($depth === 0 && ctype_space($char)) {
                $before = rtrim(substr($text, $start, $i - $start));
                $after = ltrim(substr($text, $i, 2));
                $last = $before === '' ? '' : $before[strlen($before) - 1];
                if (!in_array($last, [':', '|', '&'], true) && ($after === '' || !in_array($after[0], ['|', '&'], true))) {
                    break;
                }
            }
            $i++;
        }
        $end = $i;

        return (string) preg_replace('/\s+/', ' ', trim(substr($text, $start, $i - $start)));
    }
}

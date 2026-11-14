<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fixtures;

use IndianOpinion\ReadableExtract\Exception\FixtureException;

/**
 * A directory of fixtures. Every subdirectory is one fixture and must hold
 * page.html, expected.json and meta.json. A missing file or invalid JSON
 * fails the load with the fixture's name (case 88); nothing is skipped.
 */
final class Suite
{
    /**
     * @param list<Fixture> $fixtures
     */
    private function __construct(public readonly string $dir, public readonly array $fixtures)
    {
    }

    public static function load(string $dir): self
    {
        if (!is_dir($dir)) {
            throw new FixtureException('Fixture directory not found: ' . $dir);
        }
        $names = scandir($dir);
        if ($names === false) {
            throw new FixtureException('Cannot read fixture directory: ' . $dir);
        }
        $fixtures = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || !is_dir($dir . '/' . $name)) {
                continue;
            }
            $fixtures[] = self::loadOne($name, $dir . '/' . $name);
        }
        return new self($dir, $fixtures);
    }

    public static function loadOne(string $name, string $path): Fixture
    {
        foreach (['page.html', 'expected.json', 'meta.json'] as $file) {
            if (!is_file($path . '/' . $file)) {
                throw new FixtureException('Fixture "' . $name . '" is missing ' . $file);
            }
        }
        $html = (string) file_get_contents($path . '/page.html');
        $expected = self::json($name, $path . '/expected.json');
        $meta = self::json($name, $path . '/meta.json');
        FixtureMeta::validate($name, $meta);
        return new Fixture($name, $path, $html, $expected, $meta);
    }

    public function count(): int
    {
        return count($this->fixtures);
    }

    /** @return array<string, mixed> */
    private static function json(string $name, string $file): array
    {
        try {
            $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FixtureException('Fixture "' . $name . '": invalid JSON in ' . basename($file) . ': ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new FixtureException('Fixture "' . $name . '": ' . basename($file) . ' must hold a JSON object');
        }
        return $data;
    }
}

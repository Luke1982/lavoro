<?php

namespace App\Domain\Tools;

use App\Models\User;

/**
 * The catalogue of capabilities the assistant has.
 *
 * Registration is explicit rather than discovered from the filesystem. A tool is
 * a hole in the wall between a language model and the database, and a hole that
 * appears because someone dropped a file in a directory is one nobody decided
 * to open.
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $resolved = [];

    /**
     * @param  array<int, class-string<Tool>>  $tools
     */
    public function __construct(private readonly array $tools) {}

    public function find(string $name): ?Tool
    {
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }

        foreach ($this->tools as $class) {
            if ($class::name() === $name) {
                return $this->resolved[$name] = app($class);
            }
        }

        return null;
    }

    /**
     * The tool definitions to send with a request, in a fixed order.
     *
     * Order is stable because the definitions are the very front of the prompt:
     * reordering them invalidates the cache for everything that follows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitionsFor(User $user): array
    {
        $profile = ToolProfile::forUser($user);

        return array_values(array_map(
            fn (Tool $tool) => [
                'name' => $tool::name(),
                'description' => $tool->description(),
                'input_schema' => $tool->inputSchema(),
                'strict' => true,
            ],
            $this->forProfile($profile),
        ));
    }

    /**
     * @return array<int, Tool>
     */
    /**
     * Keeps the order the tools are configured in. Sorting them here would have
     * meant a new tool landing anywhere in the list and invalidating the cached
     * prefix for every tool after it; appending to the config invalidates
     * nothing, which is the whole reason the order is worth caring about.
     */
    public function forProfile(ToolProfile $profile): array
    {
        $matching = array_filter(
            $this->tools,
            fn (string $class) => in_array($profile, $class::availableTo(), true),
        );

        return array_values(array_map(fn (string $class) => app($class), $matching));
    }

    /**
     * @return array<int, class-string<Tool>>
     */
    public function all(): array
    {
        return $this->tools;
    }
}

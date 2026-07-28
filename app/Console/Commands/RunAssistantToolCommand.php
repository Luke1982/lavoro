<?php

namespace App\Console\Commands;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolRegistry;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Drives a tool by hand, as a given user, without a language model in the loop.
 *
 * This is how the tool layer is developed and checked: if a capability cannot be
 * exercised from here, it is not ready to be handed to an assistant.
 */
class RunAssistantToolCommand extends Command
{
    protected $signature = 'assistant:tool
        {tool? : Name of the tool to run}
        {--user= : Id of the user to run as}
        {--arg=* : Argument as key=value, repeatable}
        {--list : Show the available tools per profile}';

    protected $description = 'Voert één assistent-tool uit als een gekozen gebruiker.';

    public function handle(ToolRegistry $registry, ToolExecutor $executor): int
    {
        if ($this->option('list') || !$this->argument('tool')) {
            return $this->listTools($registry);
        }

        $user = $this->resolveUser();

        if (!$user) {
            return self::FAILURE;
        }

        $call = new ToolCall(
            name: (string) $this->argument('tool'),
            arguments: $this->parseArguments(),
            user: $user,
        );

        $this->line('<comment>Profiel:</comment> ' . ToolProfile::forUser($user)->value);

        $result = $executor->run($call);

        $this->newLine();
        $this->line($result->is_error ? '<error>Mislukt</error>' : '<info>Gelukt</info>');

        if ($result->summary) {
            $this->line($result->summary);
        }

        $this->newLine();
        $this->line(json_encode(
            is_string($result->content) ? ['message' => $result->content] : $result->content,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $result->is_error ? self::FAILURE : self::SUCCESS;
    }

    private function listTools(ToolRegistry $registry): int
    {
        foreach (ToolProfile::all() as $profile) {
            $this->newLine();
            $this->line('<comment>' . $profile->value . '</comment>');

            foreach ($registry->forProfile($profile) as $tool) {
                $this->line('  ' . str_pad($tool::name(), 24) . $this->firstSentence($tool));
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function firstSentence(Tool $tool): string
    {
        $description = $tool->description();
        $end = mb_strpos($description, '. ');

        return $end === false ? $description : mb_substr($description, 0, $end + 1);
    }

    private function resolveUser(): ?User
    {
        $id = $this->option('user');

        $user = $id ? User::find($id) : User::orderBy('id')->first();

        if (!$user) {
            $this->error($id ? 'Gebruiker ' . $id . ' bestaat niet.' : 'Er zijn geen gebruikers.');

            return null;
        }

        $this->line('<comment>Uitvoeren als:</comment> ' . $user->name . ' (#' . $user->id . ')');

        return $user;
    }

    /**
     * Values arrive as strings from the shell, so the obvious scalars are cast
     * back. A tool called through the API gets these types from the model
     * directly, and should behave the same either way.
     *
     * @return array<string, mixed>
     */
    private function parseArguments(): array
    {
        $arguments = [];

        foreach ($this->option('arg') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);

            $arguments[$key] = match (true) {
                $value === 'true' => true,
                $value === 'false' => false,
                $value === 'null' => null,
                is_numeric($value) => $value + 0,
                default => $value,
            };
        }

        return $arguments;
    }
}

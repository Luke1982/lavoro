<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\ServiceOrderMacro;
use App\Models\User;

/**
 * The macros of this company, each as the numbered steps a person sees under
 * Werkbonnen → Macro's, with everything it asks when it runs. That list is what
 * run_macro takes its answers by.
 */
class ListMacrosTool implements Tool
{
    public static function name(): string
    {
        return 'list_macros';
    }

    public function description(): string
    {
        return 'Geeft de macro\'s: vaste reeksen stappen voor een werkbon, met wat ze vragen bij het uitvoeren. '
            . 'Gebruik dit vóór run_macro of als iemand vraagt wat een macro doet.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Deel van de naam; weglaten geeft ze allemaal.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('run', ServiceOrderMacro::class);
    }

    /** Een naam opzoeken; de stappen staan er al als zinnen. */
    public static function difficulty(): int
    {
        return 2;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function execute(ToolCall $call): ToolResult
    {
        $macros = ServiceOrderMacro::query()
            ->when(
                $call->stringArgument('search'),
                fn ($query) => $query->where('title', 'like', $call->likeArgument('search')),
            )
            ->orderBy('title')
            ->get()
            ->filter(fn (ServiceOrderMacro $macro) => $macro->steps()->allows($call->user))
            ->values();

        return ToolResult::ok(
            [
                'macros' => $macros->map(fn (ServiceOrderMacro $macro) => [
                    'macro_id' => $macro->id,
                    'title' => $macro->title,
                    'description' => $macro->description,
                    'steps' => $macro->readable(),
                    'asks' => $macro->steps()->questions(),
                ])->all(),
                'answers' => 'Geef run_macro per vraag op het pad een antwoord met step_id en field uit "asks". '
                    . 'number: een heel getal; text: tekst; yes_no: true of false; location: "all" voor alle '
                    . 'machines of het nummer van een locatie van de klant; file: de naam van een bestand dat in '
                    . 'dit gesprek is meegestuurd. Een vraag met only_if hoort alleen beantwoord te worden als dat '
                    . 'antwoord gegeven is.',
            ],
            $macros->count() . ' macro(\'s) gevonden.',
        );
    }
}

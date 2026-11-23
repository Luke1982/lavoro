<?php

use App\Domain\Tours\Speech\ElevenLabsSpeech;
use App\Domain\Tours\Speech\FakeSpeech;
use App\Domain\Tours\Speech\OpenAiSpeech;

return [

    /*
    |---------------------------------------------------------------------------
    | De stem van de rondleidingen
    |---------------------------------------------------------------------------
    |
    | `php artisan tours:speak` spreekt elke stap één keer in bij een van deze
    | aanbieders en zet het resultaat als gewoon bestand onder public/tours. De
    | rondleiding zelf praat nooit met een aanbieder. Zolang een hoofdstuk geen
    | opname heeft, loopt het stil door met de tekst in beeld; de browser leest
    | nooit zelf voor.
    |
    | `fake` maakt een pieptoon zo lang als de zin, om de opdracht te proberen
    | zonder sleutel en zonder iets te versturen.
    |
    */

    'speech' => [
        'driver' => env('TOUR_SPEECH_DRIVER', 'elevenlabs'),

        'drivers' => [
            'elevenlabs' => [
                'class' => ElevenLabsSpeech::class,
                'key' => env('ELEVENLABS_API_KEY'),
                'voice' => env('ELEVENLABS_VOICE_ID'),
                'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
            ],
            'openai' => [
                'class' => OpenAiSpeech::class,
                'key' => env('TOUR_OPENAI_API_KEY', env('OPENAI_API_KEY')),
                'voice' => env('TOUR_OPENAI_VOICE', 'nova'),
                'model' => env('TOUR_OPENAI_MODEL', 'gpt-4o-mini-tts'),
                'instructions' => 'Spreek vloeiend Nederlands zoals in Nederland, rustig en vriendelijk, als een collega '
                    . 'die iemand op weg helpt. Geen reclamestem.',
            ],
            'fake' => [
                'class' => FakeSpeech::class,
            ],
        ],
    ],

    /*
    | Losse stukken per stap, op de hash van stem en tekst. Ze staan in de
    | repository, zodat een gewijzigde zin alleen die ene stap opnieuw laat
    | inspreken, ook op een andere machine.
    */
    'clips' => resource_path('tours/clips'),

    /* Per hoofdstuk één geluidsbestand en een klein bestand met de tijden per stap. */
    'output' => public_path('tours'),

    /*
     * Stilte na elke stap in het samengevoegde bestand, zodat een stap nooit het
     * begin van de volgende laat horen. Ruim: wat al naar de luidspreker onderweg
     * is, klinkt na het stoppen nog na, op een bluetooth-koptelefoon een paar
     * tienden van een seconde. Niemand hoort deze stilte, want elke stap stopt
     * vlak na zijn laatste woord.
     */
    'gap_seconds' => 1.0,

    /*
     * Leenwoorden die we op zijn Engels uitspreken, geschreven zoals ze klinken.
     * Alleen de stem krijgt deze spelling; in beeld staat het echte woord. Elk
     * woord telt ook midden in een ander woord: "planning" dekt "werkplanning"
     * en "planningen", "gepland" dekt "ingeplande". Het losse woord "plan" staat
     * er bewust niet in: dat zeggen we gewoon op zijn Nederlands.
     */
    'pronunciation' => [
        'planbord' => 'plenbord',
        'planning' => 'plenning',
        'planner' => 'plenner',
        'plannen' => 'plennen',
        'gepland' => 'geplend',
        'inplant' => 'inplent',
        'planrol' => 'plenrol',
        'plangroep' => 'plengroep',
    ],

    'ffmpeg' => env('FFMPEG_BINARY', '/usr/bin/ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', '/usr/bin/ffprobe'),

];

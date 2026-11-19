<?php

namespace App\Domain\Tours\Speech;

/**
 * Turns one line of a rondleiding into spoken audio.
 */
interface SpeechDriver
{
    /**
     * @param  array<string, mixed>  $config  The driver's entry in config('tours.speech.drivers').
     */
    public static function fromConfig(array $config): self;

    /**
     * Names the voice: supplier, voice and model. It is part of every clip's
     * hash, so changing the voice speaks everything again instead of mixing two.
     */
    public function signature(): string;

    /** The line as MP3. Throws when it cannot. */
    public function speak(string $text): SpokenLine;
}

<?php

namespace App\Console\Commands;

use App\Domain\Tours\Speech\SpeechDriver;
use App\Domain\Tours\TourAudio;
use App\Domain\Tours\TourCatalog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Spreekt de rondleidingen in, één keer, als gewone bestanden onder public/tours.
 *
 * Alleen stappen waarvan de gesproken tekst veranderde worden opnieuw
 * ingesproken; de rest komt uit de bewaarde stukken. Zonder opname leest de
 * browser de tekst zelf voor, dus een rondleiding werkt ook zonder deze opdracht.
 */
class SpeakTours extends Command
{
    protected $signature = 'tours:speak
        {tour? : Alleen deze rondleiding, bijvoorbeeld planner}
        {--chapter=* : Alleen deze hoofdstukken}
        {--driver= : elevenlabs, openai of fake (standaard TOUR_SPEECH_DRIVER)}
        {--force : Alles opnieuw inspreken, ook wat niet veranderde}
        {--clips= : Map voor de losse stukken per stap (standaard resources/tours/clips)}
        {--output= : Map voor de hoofdstukken (standaard public/tours)}';

    protected $description = 'Spreekt de rondleidingen in: per stap een stuk, per hoofdstuk één bestand met de tijd van elke stap';

    public function handle(TourCatalog $catalog): int
    {
        $driver_name = (string) ($this->option('driver') ?: config('tours.speech.driver'));
        $config = config('tours.speech.drivers.' . $driver_name);

        if (!is_array($config) || !is_subclass_of($config['class'] ?? '', SpeechDriver::class)) {
            $this->error('Onbekende stem "' . $driver_name . '". Kies uit: '
                . implode(', ', array_keys(config('tours.speech.drivers'))) . '.');

            return self::FAILURE;
        }

        $tour_ids = $this->argument('tour') ? [$this->argument('tour')] : $catalog->ids();
        $only = $this->option('chapter');
        $audio = new TourAudio(
            driver: $config['class']::fromConfig($config),
            clips_root: (string) ($this->option('clips') ?: config('tours.clips')),
            output_root: (string) ($this->option('output') ?: config('tours.output')),
            ffmpeg: (string) config('tours.ffmpeg'),
            ffprobe: (string) config('tours.ffprobe'),
            gap_seconds: (float) config('tours.gap_seconds'),
        );

        $failed = false;
        foreach ($tour_ids as $tour_id) {
            $tour = $catalog->tour($tour_id);
            if ($tour === null) {
                $this->error('Rondleiding "' . $tour_id . '" bestaat niet. Er zijn: ' . implode(', ', $catalog->ids()) . '.');

                return self::FAILURE;
            }

            foreach ($tour['chapters'] as $chapter) {
                if ($only !== [] && !in_array($chapter['id'], $only, true)) {
                    continue;
                }

                $this->line($tour_id . ' / ' . $chapter['id'] . ' (versie ' . $chapter['version'] . ')');
                try {
                    $result = $audio->speakChapter($tour_id, $chapter, (bool) $this->option('force'), fn (string $line) => $this->line($line));
                    $this->info($result['rebuilt']
                        ? '  klaar: ' . $result['spoken'] . ' ingesproken, ' . $result['kept'] . ' bewaard'
                        : '  ongewijzigd');
                } catch (Throwable $e) {
                    $failed = true;
                    $this->error('  mislukt: ' . $e->getMessage());
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

<template>
    <!--
        Boven alles, ook boven een aankondiging: zolang dit staat is de
        applicatie zichzelf aan het opruimen en valt er niets zinnigs meer aan te
        klikken. Dat het klikken tegenhoudt is hier geen bijwerking maar de
        bedoeling.
    -->
    <Transition enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0"
        enter-to-class="opacity-100" leave-active-class="transition-opacity duration-150"
        leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="loggingOut"
            class="fixed inset-0 z-[10001] flex flex-col items-center justify-center gap-3 bg-slate-950/70 backdrop-blur-sm"
            role="status" aria-live="polite">
            <LoaderCircle class="size-8 animate-spin text-lavoro-green" aria-hidden="true" />
            <p class="text-sm font-medium text-white">Bezig met afmelden…</p>
        </div>
    </Transition>
</template>

<script setup>
import { LoaderCircle } from '@lucide/vue'
import { useMenu } from '@/Composables/useMenu.js'

/**
 * Afmelden stopt de plaatsbepaling, zegt de pushdienst op, schrijft de service
 * worker uit en gooit alle caches weg voordat er genavigeerd wordt. Dat duurt
 * merkbaar lang, en zonder deze laag lijkt de knop kapot.
 *
 * Haalt de stand zelf op in plaats van hem als prop te krijgen: hij hoort bij
 * het menu, waar de uitlogknoppen ook staan, en dan hoeft de indeling er niets
 * van te weten.
 */
const { loggingOut } = useMenu()
</script>

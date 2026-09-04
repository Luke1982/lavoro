<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * "Dit kan niet, en dit is waarom" -- bedoeld voor degene die op de knop drukte.
 *
 * Een gewone RuntimeException wordt een 500, en daar maakt de afhandeling
 * "Er is een serverfout opgetreden" van. De echte reden -- er valt niets te
 * factureren, die coupon is al gebruikt, dat adres is al in gebruik -- gaat dan
 * verloren, terwijl hij al opgeschreven was. Wie dit gooit, weet dat de tekst
 * gelezen mag worden.
 *
 * Alleen voor gevallen die de gebruiker zelf kan oplossen. Een ontbrekende
 * extensie of een verkeerde instelling hoort een echte fout te blijven, met
 * stacktrace en al.
 */
class Refusal extends RuntimeException {}

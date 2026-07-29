<?php

namespace App\Domain\Assistant\Contracts;

/**
 * Why a supplier could not answer, in the few kinds a person can act on.
 *
 * Every supplier signals these differently — a status code here, a phrase in an
 * error body there — and translating that is the adapter's job. What is left is
 * a short list, because "top up the account" and "the key is wrong" are the only
 * distinctions anyone can actually do something about.
 */
enum ModelFailure: string
{
    case no_credit = 'no_credit';
    case bad_credentials = 'bad_credentials';
    case unreachable = 'unreachable';
    case other = 'other';
}

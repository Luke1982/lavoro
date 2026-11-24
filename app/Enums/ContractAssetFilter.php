<?php

namespace App\Enums;

/**
 * Which machines under a running maintenance contract a list of machines shows. The
 * value travels in the query string as `contracts`; mixed is the default and has none.
 */
enum ContractAssetFilter: string
{
    case only = 'only';
    case mixed = 'mixed';
    case hide = 'hide';
}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A photo or file came with a question and no configured model can open it.
 * Refused up front, because an attachment nobody read gets answered in the same
 * confident voice as one that was.
 */
class AttachmentsUnreadable extends RuntimeException {}

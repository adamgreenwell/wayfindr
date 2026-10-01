<?php

declare(strict_types=1);

namespace App\Support\Zip;

use RuntimeException;

/** An archive that would outgrow the size or entry count its writer allows. */
final class ArchiveTooLarge extends RuntimeException {}

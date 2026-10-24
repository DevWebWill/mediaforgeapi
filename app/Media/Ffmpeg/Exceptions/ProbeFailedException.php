<?php

namespace App\Media\Ffmpeg\Exceptions;

use RuntimeException;

/** ffprobe no pudo interpretar el archivo (corrupto, truncado o formato no admitido). */
class ProbeFailedException extends RuntimeException {}

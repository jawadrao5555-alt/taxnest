<?php

namespace App\Exceptions;

/**
 * Task 142: user-facing AI Invoice Reader failure (bad/unreadable file,
 * scanned PDF without a rasterizer, not-an-invoice, AI service down).
 * The message is always friendly and safe to show in the UI.
 */
class AiReaderException extends \RuntimeException
{
}

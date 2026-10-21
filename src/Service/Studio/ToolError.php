<?php

namespace App\Service\Studio;

/** What a tool says when it cannot do what it was asked: shown to the AI as is (isError), so it can fix its call. */
final class ToolError extends \RuntimeException
{
}

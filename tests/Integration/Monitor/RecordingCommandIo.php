<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * A real {@see SymfonyCommandIO} over buffered output, so command tests assert
 * against the text an operator would actually see rather than a mock's
 * recorded calls.
 *
 * @internal Test helper.
 */
final class RecordingCommandIo extends SymfonyCommandIO
{
    private BufferedOutput $buffer;
    private BufferedOutput $errorBuffer;

    public function __construct()
    {
        $buffer = new BufferedOutput();
        $errors = new BufferedOutput();

        parent::__construct(new ArrayInput([]), $buffer, $errors);

        $this->buffer = $buffer;
        $this->errorBuffer = $errors;
    }

    /** Everything written to stdout and stderr, which is what a reader sees. */
    public function output(): string
    {
        return $this->buffer->fetch() . $this->errorBuffer->fetch();
    }
}

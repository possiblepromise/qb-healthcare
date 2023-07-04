<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare;

final class Era835Reader
{
    private array $segments;
    private int $currentSegment = 0;

    public function __construct(string $file)
    {
        $data = self::readFileData($file);
        $this->segments = explode('~', $data);

        foreach ($this->segments as $i => $segment) {
            $segment = explode('*', $segment);

            foreach ($segment as $j => $element) {
                $segment[$j] = explode(':', $element);
            }
            $this->segments[$i] = $segment;
        }

        if ($this->read('ST01') !== '835') {
            throw new \RuntimeException('This is not a validly formatted ERA 835 file.');
        }
    }

    public function read($qualifier): ?string
    {
        $matched = preg_match('/^([A-Z0-9]+)(\d{2})(?:-(\d+))?$/', $qualifier, $matches);
        if ($matched === 0) {
            throw new \InvalidArgumentException('Improperly formatted qualifier.');
        }

        $segment = $matches[1];
        $part = $matches[2];
        $subPart = '01';
        if (isset($matches[3])) {
            $subPart = $matches[3];
        }

        $prevPosition = $this->currentSegment;

        while ($this->segments[$this->currentSegment][0][0] !== $segment) {
            ++$this->currentSegment;

            if ($this->currentSegment >= \count($this->segments)) {
                $this->currentSegment = $prevPosition;

                return null;
            }
        }

        return $this->readElement($part, $subPart);
    }

    public function next(): string
    {
        ++$this->currentSegment;

        return $this->readElement('00', '01');
    }

    public function readSegment($qualifier): ?string
    {
        $matched = preg_match('/^(\d{2})(?:-(\d+))?$/', $qualifier, $matches);
        if ($matched === 0) {
            throw new \InvalidArgumentException('Improperly formatted qualifier.');
        }

        $part = $matches[1];
        $subPart = '01';
        if (isset($matches[2])) {
            $subPart = $matches[3];
        }

        return $this->readElement($part, $subPart);
    }

    private static function readFileData(string $file): string
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if (strtolower($extension) === 'zip') {
            $zip = new \ZipArchive();
            $zip->open($file);

            $foundFile = null;

            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with($name, '.835')) {
                    $foundFile = $name;
                }
            }

            if ($foundFile === null) {
                throw new \RuntimeException('No 835 file found in this zip.');
            }

            $file = "zip://{$file}#{$foundFile}";
        }

        return file_get_contents($file);
    }

    private static function toIndex(string $number): int
    {
        sscanf($number, '%0d', $index);

        return $index;
    }

    private function readElement(mixed $part, mixed $subPart): ?string
    {
        $thisSegment = $this->segments[$this->currentSegment];
        $partIndex = self::toIndex($part);
        if (!isset($thisSegment[$partIndex])) {
            return null;
        }

        $subPartIndex = self::toIndex($subPart) - 1;
        if (!isset($thisSegment[$partIndex][$subPartIndex])) {
            return null;
        }

        return $thisSegment[$partIndex][$subPartIndex];
    }
}

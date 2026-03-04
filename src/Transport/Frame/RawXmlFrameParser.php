<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport\Frame;

use SkyDiablo\Keymile\Koap\Transport\FrameParserInterface;

/**
 * Detects complete XML messages by looking for closing root tags.
 *
 * This is the default parser that works without knowing the exact wire framing.
 * It buffers incoming data and looks for </response> or </request> closing tags
 * to split the stream into individual messages.
 */
class RawXmlFrameParser implements FrameParserInterface
{
    private string $buffer = '';

    /** @var list<string> */
    private array $closingTags = ['</response>', '</request>'];

    public function feed(string $data): array
    {
        $this->buffer .= $data;
        $messages = [];

        while (true) {
            $found = false;

            foreach ($this->closingTags as $tag) {
                $pos = strpos($this->buffer, $tag);
                if ($pos !== false) {
                    $end = $pos + strlen($tag);
                    $message = trim(substr($this->buffer, 0, $end));
                    $this->buffer = substr($this->buffer, $end);

                    if ($message !== '') {
                        $messages[] = $message;
                    }

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                break;
            }
        }

        return $messages;
    }

    public function reset(): void
    {
        $this->buffer = '';
    }
}

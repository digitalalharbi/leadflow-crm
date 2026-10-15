<?php

namespace App\Services\Whatsapp;

use App\DataTransferObjects\SentMessage;
use App\Models\Integration;
use Illuminate\Support\Facades\Storage;

/**
 * How a message is to be sent, as data.
 *
 * A message used to be its body, because a message was always text. Once a
 * message can be a template or a file, "send it again" needs more than a
 * string, and the retry job had no way to ask. So the recipe is stored with the
 * message and everything that transmits — the first attempt and every retry —
 * goes through `run`, which means the two cannot disagree about what was sent.
 */
final class SendSpec
{
    public const TEXT = 'text';

    public const TEMPLATE = 'template';

    public const DOCUMENT = 'document';

    /** @return array{kind:string} */
    public static function text(): array
    {
        return ['kind' => self::TEXT];
    }

    /**
     * @param  array<int,string>  $parameters
     * @return array<string,mixed>
     */
    public static function template(string $name, string $language, array $parameters): array
    {
        return [
            'kind' => self::TEMPLATE,
            'name' => $name,
            'language' => $language,
            'parameters' => array_values($parameters),
        ];
    }

    /**
     * By storage path, never by bytes: a retry minutes later should read the
     * file as it is then, and a spec carrying the contents would put a brochure
     * in a JSON column.
     *
     * @return array<string,mixed>
     */
    public static function document(string $path, string $mime, string $filename, ?string $caption): array
    {
        return [
            'kind' => self::DOCUMENT,
            'path' => $path,
            'mime' => $mime,
            'filename' => $filename,
            'caption' => $caption,
        ];
    }

    /**
     * Transmits whatever the spec says, with `$body` as the text of a text send.
     *
     * @param  array<string,mixed>|null  $spec  null means text — every message
     *                                          that predates this column
     */
    public static function run(
        WhatsappProviderInterface $provider,
        Integration $integration,
        string $to,
        ?array $spec,
        string $body,
    ): SentMessage {
        return match ($spec['kind'] ?? self::TEXT) {
            self::TEMPLATE => $provider->sendTemplate(
                $integration,
                $to,
                (string) $spec['name'],
                (string) $spec['language'],
                (array) ($spec['parameters'] ?? []),
            ),

            self::DOCUMENT => self::transmitDocument(
                $provider, $integration, $to, $spec,
            ),

            default => $provider->sendText($integration, $to, $body),
        };
    }

    private static function transmitDocument(
        WhatsappProviderInterface $provider,
        Integration $integration,
        string $to,
        array $spec,
    ): SentMessage {
        $disk = Storage::disk('local');
        $path = (string) ($spec['path'] ?? '');

        /*
         * A file that has gone is a permanent failure, and says so. Retrying it
         * three times would only delay the rep learning that the brochure they
         * thought they sent was deleted.
         */
        if ($path === '' || ! $disk->exists($path)) {
            return SentMessage::failed('file_missing', 'The file is no longer available.');
        }

        return $provider->sendDocument(
            $integration,
            $to,
            $disk->get($path),
            (string) ($spec['mime'] ?? 'application/octet-stream'),
            (string) ($spec['filename'] ?? 'file'),
            $spec['caption'] ?? null,
        );
    }
}

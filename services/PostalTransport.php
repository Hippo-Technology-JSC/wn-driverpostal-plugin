<?php

namespace Hippo\DriverPostal\Services;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Component\Mime\Email;
use Throwable;

class PostalTransport extends AbstractTransport
{
    protected string $baseUri;
    protected string $apiKey;
    protected int $timeout;

    public function __construct(string $baseUri, string $apiKey, int $timeout = 10)
    {
        parent::__construct();
        $this->baseUri   = rtrim($baseUri, '/');
        $this->apiKey = $apiKey;
        $this->timeout   = $timeout;
    }

    public function __toString(): string
    {
        return sprintf('postal+api://%s', parse_url($this->baseUri, PHP_URL_HOST) ?: 'localhost');
    }

    protected function doSend(SentMessage $message): void
    {
        /** @var Email $email */
        $email = $message->getOriginalMessage();

        // to/cc/bcc
        $to  = array_map(fn(Address $a) => (string) $a->toString(), $email->getTo());
        $cc  = array_map(fn(Address $a) => (string) $a->toString(), $email->getCc());
        $bcc = array_map(fn(Address $a) => (string) $a->toString(), $email->getBcc());

        // from (Postal cần thuộc domain đã verify)
        $fromAddress = $email->getFrom()[0] ?? null;
        if (!$fromAddress) {
            throw new \RuntimeException('PostalTransport: missing From address');
        }
        $from = (string) $fromAddress->toString();

        $subject   = $email->getSubject() ?? '';
        $textBody  = $email->getTextBody() ?? null;
        $htmlBody  = $email->getHtmlBody() ?? null;

        // attachments
        $attachments = [];
        foreach ($email->getAttachments() as $att) {
            $attachments[] = [
                'name'        => $att->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'name') ?? 'attachment',
                'content'     => base64_encode($att->getBody()),
                'content_type' => $att->getMediaType() . '/' . $att->getMediaSubtype(),
            ];
        }

        // payload theo Postal API
        $payload = array_filter([
            'from'       => $from,
            'to'         => implode(',', $to),
            'cc'         => $cc ? implode(',', $cc) : null,
            'bcc'        => $bcc ? implode(',', $bcc) : null,
            'subject'    => $subject,
            'text_body'  => $textBody,
            'html_body'  => $htmlBody,
            'attachments' => $attachments ?: null,
        ], fn($v) => $v !== null && $v !== '');

        $url = $this->baseUri . '/api/v1/send/message';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Server-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            throw new \RuntimeException('PostalTransport send failed: ' . $err . ' / HTTP ' . $code . ' / ' . $raw);
        }

        // có thể parse response nếu muốn lấy message_id
        // $data = json_decode($raw, true);
    }
}

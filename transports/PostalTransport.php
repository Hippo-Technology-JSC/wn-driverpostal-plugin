<?php

namespace Hippo\DriverPostal\Transports;

use Postal\Client as PostalClient;
use Postal\Send\Message as PostalMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class PostalTransport extends AbstractTransport
{
    private PostalClient $client;
    private string $baseUrl;

    public function __construct(
        PostalClient $client,
        string $baseUrl,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($dispatcher, $logger);
        $this->client  = $client;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function __toString(): string
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: 'localhost';
        return 'postal+api://' . $host;
    }

    protected function doSend(SentMessage $message): void
    {
        /** @var Email $email */
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            throw new \RuntimeException('PostalTransport chỉ hỗ trợ Symfony\\Mime\\Email.');
        }

        // From (bắt buộc verify domain trên Postal)
        $fromAddress = $email->getFrom()[0] ?? null;
        if (!$fromAddress instanceof Address) {
            throw new \RuntimeException('Postal: thiếu From.');
        }

        // Dựng Postal\Send\Message từ SDK
        $pm = new PostalMessage();

        // Recipients
        foreach ($email->getTo() as $a)  { $pm->to((string)$a->toString()); }
        foreach ($email->getCc() as $a)  { $pm->cc((string)$a->toString()); }
        foreach ($email->getBcc() as $a) { $pm->bcc((string)$a->toString()); }

        // From, subject, bodies
        $pm->from((string)$fromAddress->toString());
        if ($email->getSubject())   { $pm->subject($email->getSubject()); }
        if ($email->getTextBody())  { $pm->plainBody($email->getTextBody()); }
        if ($email->getHtmlBody())  { $pm->htmlBody($email->getHtmlBody()); }

        // X- headers (tuỳ chọn): chỉ đẩy các header bắt đầu bằng X-
        foreach ($email->getHeaders()->all() as $hdr) {
            $name = $hdr->getName();
            if (stripos($name, 'X-') === 0) {
                $pm->header($name, $hdr->getBodyAsString());
            }
        }

        // Đính kèm
        foreach ($email->getAttachments() as $att) {
            $filename = null;
            if ($att instanceof DataPart) {
                $filename = $att->getFilename();
            }
            if (!$filename) {
                // lấy từ header Content-Disposition; fallback 'attachment'
                $filename = $att->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename')
                    ?: 'attachment';
            }
            $mediaType    = $att->getMediaType() ?: 'application';
            $mediaSubtype = $att->getMediaSubtype() ?: 'octet-stream';
            $contentType  = $mediaType . '/' . $mediaSubtype;

            // SDK nhận nội dung thô, không cần base64
            $pm->attach($filename, $contentType, $att->getBody());
        }

        // Gửi qua SDK
        $result = $this->client->send->message($pm);

        // Lấy message-id đầu tiên (nếu muốn hiển thị trong log)
        $recipients = $result->recipients();
        if (is_array($recipients) && $recipients) {
            $first = reset($recipients);
            if (isset($first->id)) {
                $message->setMessageId((string)$first->id);
            }
        }
    }
}
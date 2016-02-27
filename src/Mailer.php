<?php

namespace Kinglozzer\SilverStripeMailgunner;

use Mailer as SilverstripeMailer;
use Mailgun\Mailgun;
use Mailgun\Messages\MessageBuilder;

class Mailer extends SilverstripeMailer
{
    /**
     * @var string
     * @config
     */
    private static $api_domain = '';

    /**
     * @var string
     * @config
     */
    private static $api_endpoint = 'api.mailgun.net';

    /**
     * @var string
     * @config
     */
    private static $api_key = '';

    /**
     * @var boolean
     * @config
     */
    private static $api_ssl = true;

    /**
     * @var string
     * @config
     */
    private static $api_version = 'v3';

    /**
     * An array of temporary file handles opened to store attachments
     * @var array
     */
    protected $tempFileHandles = [];

    /**
     * @var Mailgun\Mailgun
     */
    protected $mailgunClient;

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        $config = $this->config();
        $this->setMailgunClient(new Mailgun(
            $config->api_key,
            $config->api_endpoint,
            $config->api_version,
            $config->api_ssl
        ));
    }

    /**
     * @param Mailgun\Mailgun $client
     * @return self
     */
    public function setMailgunClient(Mailgun $client)
    {
        $this->mailgunClient = $client;
        return $this;
    }

    /**
     * @return Mailgun\Mailgun
     */
    public function getMailgunClient()
    {
        return $this->mailgunClient;
    }

    /**
     * {@inheritdoc}
     */
    public function sendPlain($to, $from, $subject, $plainContent, $attachments = [], $headers = [])
    {
        $this->sendMessage($to, $from, $subject, $htmlContent = '', $plainContent, $attachments, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function sendHTML($to, $from, $subject, $htmlContent, $attachments = [], $headers = [], $plainContent = '')
    {
        $this->sendMessage($to, $from, $subject, $htmlContent, $plainContent, $attachments, $headers);
    }

    /**
     * @param string $to
     * @param string $from
     * @param string $subject
     * @param string $content
     * @param string $plainContent
     * @param array $attachments
     * @param array $headers
     */
    protected function sendMessage($to, $from, $subject, $content, $plainContent, $attachments, $headers)
    {
        $client = $this->getMailgunClient();
        $messageBuilder = $client->MessageBuilder();

        $this->buildMessage($messageBuilder, $to, $from, $subject, $content, $plainContent, $headers);

        if (!empty($attachments)) {
            $attachments = $this->prepareAttachments($attachments);
        }

        try {
            $client->sendMessage($this->config()->api_domain, $messageBuilder->getMessage(), $attachments);
        } catch (\Exception $e) {
            // Close and remove any temp files created for attachments, then let the exception bubble up
            $this->closeTempFileHandles();
            throw $e;
        }

        $this->closeTempFileHandles();
    }

    /**
     * @param Mailgun\Messages\MessageBuilder $messageBuilder
     * @param string $to
     * @param string $from
     * @param string $subject
     * @param string $content
     * @param string $plainContent
     * @param array $attachments
     * @param array $headers
     */
    protected function buildMessage(MessageBuilder $builder, $to, $from, $subject, $content, $plainContent, $headers)
    {
        // Add base info
        $builder->addToRecipient($to);
        $builder->setFromAddress($from);
        $builder->setSubject($subject);

        // HTML content (if not empty)
        if ($content) {
            $builder->setHtmlBody($content);
        }

        // Plain text content (if not empty)
        if ($plainContent) {
            $builder->setTextBody($plainContent);
        }

        // Parse Cc & Bcc headers out if they're set
        if (isset($headers['Cc'])) {
            foreach (explode(', ', $headers['Cc']) as $ccAddress) {
                $builder->addCcRecipient($ccAddress);
            }
            unset($headers['Cc']);
        }

        if (isset($headers['Bcc'])) {
            foreach (explode(', ', $headers['Bcc']) as $bccAddress) {
                $builder->addBccRecipient($bccAddress);
            }
            unset($headers['Bcc']);
        }

        // Add remaining custom headers
        foreach ($headers as $name => $data) {
            $builder->addCustomHeader($name, $data);
        }
    }

    /**
     * Prepare attachments for sending. SilverStripe extracts the content and
     * passes that to the mailer, so to save encoding it we just write them all
     * to individual files and let Mailgun deal with the rest.
     * 
     * @todo Can we handle this better?
     * @param array $attachments
     * @return array
     */
    protected function prepareAttachments(array $attachments)
    {
        $prepared = [];
            
        foreach ($attachments as $attachment) {
            $tempFile = $this->writeToTempFile($attachment['contents']);
            
            $prepared[] = [
                'filePath' => $tempFile,
                'remoteName' => $attachment['filename']
            ];
        }

       return ['attachment' => $prepared];
    }

    /**
     * @param string $contents
     * @return string
     */
    protected function writeToTempFile($contents)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'SS_MG_TMP');
        $fileHandle = fopen($tempFile, 'r+');
        fwrite($fileHandle, $contents);

        $this->tempFileHandles[] = [
            'handle' => $fileHandle,
            'path' => $tempFile
        ];

        return $tempFile;
    }

    /**
     * @return void
     */
    protected function closeTempFileHandles()
    {
        foreach ($this->tempFileHandles as $key => $data) {
            fclose($data['handle']);
            unlink($data['path']);
            unset($this->tempFileHandles[$key]);
        }
    }
}

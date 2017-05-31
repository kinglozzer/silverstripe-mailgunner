<?php

namespace Kinglozzer\SilverStripeMailgunner;

use Debug;
use Exception;
use Mailer as SilverstripeMailer;
use Mailgun\Mailgun;
use Mailgun\Messages\BatchMessage;
use Mailgun\Messages\MessageBuilder;
use SapphireTest;
use SS_Log;

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
     * @var Mailgun
     */
    protected $mailgunClient;

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        $config = $this->config();
        $this->setMailgunClient(Mailgun::create($config->api_key));

        // @todo - Remove, these are deprecated
        $this->mailgunClient->setApiVersion($config->api_version);
        $this->mailgunClient->setSslEnabled($config->api_ssl);
    }

    /**
     * @param Mailgun $client
     * @return self
     */
    public function setMailgunClient(Mailgun $client)
    {
        $this->mailgunClient = $client;
        return $this;
    }

    /**
     * @return Mailgun
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
        return $this->sendMessage($to, $from, $subject, $htmlContent = '', $plainContent, $attachments, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function sendHTML($to, $from, $subject, $htmlContent, $attachments = [], $headers = [], $plainContent = '')
    {
        return $this->sendMessage($to, $from, $subject, $htmlContent, $plainContent, $attachments, $headers);
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
        $domain = $this->config()->api_domain;
        $client = $this->getMailgunClient();
        $attachments = $this->prepareAttachments($attachments);

        if (isset($headers['X-Mailgunner-Batch-Message'])) {
            $builder = $client->BatchMessage($domain);
            unset($headers['X-Mailgunner-Batch-Message']);
        } else {
            $builder = $client->MessageBuilder();
        }

        try {
            $this->buildMessage($builder, $to, $from, $subject, $content, $plainContent, $attachments, $headers);

            if ($builder instanceof BatchMessage) {
                $builder->finalize();
            } else {
                $client->sendMessage($domain, $builder->getMessage(), $builder->getFiles());
            }
        } catch (Exception $e) {
            // Close and remove any temp files created for attachments
            $this->closeTempFileHandles();
            // Throwing the exception would break SilverStripe's Email API expectations, so we log
            // errors and show a message (which is hidden in live mode)
            SS_Log::log('Mailgun error: ' . $e->getMessage(), SS_Log::ERR);
            if (!SapphireTest::is_running_test()) {
                Debug::message('Mailgun error: ' . $e->getMessage());
            }

            return false;
        }

        $this->closeTempFileHandles();

        // This is a stupid API :(
        return array($to, $subject, $content, $headers, '');
    }

    /**
     * @param MessageBuilder $builder
     * @param string $to
     * @param string $from
     * @param string $subject
     * @param string $content
     * @param string $plainContent
     * @param array $attachments
     * @param array $headers
     */
    protected function buildMessage(
        MessageBuilder $builder,
        $to,
        $from,
        $subject,
        $content,
        $plainContent,
        array $attachments,
        array $headers
    ) {
        // Add base info
        $parsedFrom = $this->parseAddresses($from);
        foreach ($parsedFrom as $email => $name) {
            $builder->setFromAddress($email, ['full_name' => $name]);
        }

        $builder->setSubject($subject);
        $builder->setHtmlBody($content);
        $builder->setTextBody($plainContent);

        // Add attachments
        foreach ($attachments as $attachment) {
            $builder->addAttachment($attachment['filePath'], $attachment['remoteName']);
        }

        // Parse Cc & Bcc headers out if they're set
        $ccAddresses = isset($headers['Cc']) ? $headers['Cc'] : '';
        $bccAddresses = isset($headers['Bcc']) ? $headers['Bcc'] : '';

        // We handle these ourselves, so can remove them from the list of headers
        unset($headers['Cc']);
        unset($headers['Bcc']);

        // Add remaining custom headers
        foreach ($headers as $name => $data) {
            $builder->addCustomHeader($name, $data);
        }

        // Add recipients. This is done last as the 'BatchMessage' message builder
        // will trigger sends for every 1000 addresses
        $to = $this->parseAddresses($to);
        foreach ($to as $email => $name) {
            $builder->addToRecipient($email, ['full_name' => $name]);
        }

        $ccAddresses = $this->parseAddresses($ccAddresses);
        foreach ($ccAddresses as $email => $name) {
            $builder->addCcRecipient($email, ['full_name' => $name]);
        }

        $bccAddresses = $this->parseAddresses($bccAddresses);
        foreach ($bccAddresses as $email => $name) {
            $builder->addBccRecipient($email, ['full_name' => $name]);
        }
    }

    /**
     * @todo This can't deal with mismatched quotes, or commas in names.
     *       E.g. "Smith, John" <john.smith@example.com> or "John O'smith" <john.osmith@example.com>
     * @param string
     * @return array
     */
    protected function parseAddresses($addresses)
    {
        $parsed = [];

        $expr = '/\s*["\']?([^><,;"\']+)["\']?\s*((?:<[^><,]+>)?)\s*/';
        if (preg_match_all($expr, $addresses, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $result) {
                if (empty($result[2])) {
                    // If we couldn't parse out a name
                    $parsed[$result[1]] = '';
                } else {
                    $email = trim($result[2], '<>');
                    $parsed[$email] = trim($result[1]);
                }
            }
        }

        return $parsed;
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

        return $prepared;
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

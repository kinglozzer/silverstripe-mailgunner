<?php

namespace Kinglozzer\SilverStripeMailgunner\Tests;

use Kinglozzer\SilverStripeMailgunner\Mailer;
use Config;
use Email;
use Injector;

class MailerTest extends \SapphireTest
{
    /**
     * @param object &$object
     * @param string $methodName
     * @param array $parameters
     * @return mixed
     */
    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Simple test to check that registering the mailer as an Injector service
     * will make it the default mailer used by Email
     */
    public function testMailerRegistrationWithEmail()
    {
        Injector::nest();
        Injector::inst()->registerService(new Mailer, 'Mailer');
        $this->assertInstanceOf('Kinglozzer\SilverStripeMailgunner\Mailer', Email::mailer());

        Injector::unnest();
        $this->assertNotInstanceOf('Kinglozzer\SilverStripeMailgunner\Mailer', Email::mailer());
    }

    public function testSetGetMailgunClient()
    {
        $mailer = new Mailer;
        $mockClient = $this->getMock('Mailgun\Mailgun');

        $this->assertInstanceOf('Mailgun\Mailgun', $mailer->getMailgunClient());

        $mailer->setMailgunClient($mockClient);
        $this->assertSame($mockClient, $mailer->getMailgunClient());
    }

    /**
     * @return array
     */
    protected function getMockEmail()
    {
        return [
            '"Foo Smith" <foo@bar.com>', // to
            '"Baz Smith" <baz@bam.com>', // from
            'Important question', // subject
            '<p>How much foo could a foo bar baz if a baz bam could bar foo?</p>', // html content
            'How much foo could a foo bar baz if a baz bam could bar foo?', // plain text content
            [
                [
                    'filename' => 'filename.jpg',
                    'contents' => 'abcdefg'
                ]
            ], // attachments
            [
                'X-Custom-Header' => 'foo',
                'Cc' => 'bar@baz.com',
                'Bcc' => 'nobodyiswatchingyou@nsa.gov'
            ] // headers
        ];
    }

    public function testSendPlain()
    {
        list($to, $from, $subject, $content, $plainContent, $attachments, $headers) = $this->getMockEmail();

        $mailer = $this->getMock('Kinglozzer\SilverStripeMailgunner\Mailer', ['sendMessage']);
        $mailer->expects($this->once())
            ->method('sendMessage')
            ->with(
                $this->equalTo($to),
                $this->equalTo($from),
                $this->equalTo($subject),
                $this->equalTo(''),
                $this->equalTo($plainContent),
                $this->equalTo($attachments),
                $this->equalTo($headers)
            );

        $mailer->sendPlain($to, $from, $subject, $plainContent, $attachments, $headers);
    }

    public function testSendHTML()
    {
        list($to, $from, $subject, $content, $plainContent, $attachments, $headers) = $this->getMockEmail();

        $mailer = $this->getMock('Kinglozzer\SilverStripeMailgunner\Mailer', ['sendMessage']);
        $mailer->expects($this->once())
            ->method('sendMessage')
            ->with(
                $this->equalTo($to),
                $this->equalTo($from),
                $this->equalTo($subject),
                $this->equalTo($content),
                $this->equalTo($plainContent),
                $this->equalTo($attachments),
                $this->equalTo($headers)
            );

        $mailer->sendHTML($to, $from, $subject, $content, $attachments, $headers, $plainContent);
    }

    public function testSendMessage()
    {
        $domain = 'http://testdomain.com';
        Config::inst()->update('Kinglozzer\SilverStripeMailgunner\Mailer', 'api_domain', $domain);

        list($to, $from, $subject, $content, $plainContent, $attachments, $headers) = $this->getMockEmail();
        $preparedattachments = [
            ['filePath' => '/foo/bar/baz', 'remoteName' => 'image.jpg']
        ];

        $messageBuilder = $this->getMock('Mailgun\Messages\MessageBuilder', ['getMessage', 'getFiles']);
        // We expect that sendMessage() will fetch the full message text from the builder
        $messageBuilder->expects($this->once())
            ->method('getMessage')
            ->will($this->returnValue('test message'));
        $messageBuilder->expects($this->once())
            ->method('getFiles')
            ->will($this->returnValue('attachedfiles'));

        $client = $this->getMock('Mailgun\Mailgun', ['MessageBuilder', 'sendMessage']);
        // We expect that sendMessage() will fetch the message builder from the Mailgun client, and
        // we use this point to inject our mock message builder
        $client->expects($this->once())
            ->method('MessageBuilder')
            ->will($this->returnValue($messageBuilder));
        // We expect that Mailer::sendMessage() will trigger Mailgun::sendMessage() with the
        // domain set in config, and the prepared message and attachments
         $client->expects($this->once())
            ->method('sendMessage')
            ->with(
                $this->equalTo($domain),
                $this->equalTo('test message'),
                $this->equalTo('attachedfiles')
            );

        $mailer = $this->getMock(
            'Kinglozzer\SilverStripeMailgunner\Mailer',
            ['getMailgunClient', 'buildMessage', 'prepareAttachments', 'closeTempFileHandles']
        );
        // We inject our mock Mailgun client while asserting that sendMessage() does request it
        $mailer->expects($this->once())
            ->method('getMailgunClient')
            ->will($this->returnValue($client));
        // We've got attachments, so we assert that sendMessage() passes them off to
        // prepareAttachments() and specify a mock "prepared" return value
        $mailer->expects($this->once())
            ->method('prepareAttachments')
            ->with($this->equalTo($attachments))
            ->will($this->returnValue($preparedattachments));
        // We expect that sendMessage() will pass everything off to the buildMessage() method
        $mailer->expects($this->once())
            ->method('buildMessage')
            ->with(
                $this->equalTo($messageBuilder),
                $this->equalTo($to),
                $this->equalTo($from),
                $this->equalTo($subject),
                $this->equalTo($content),
                $this->equalTo($plainContent),
                $this->equalTo($preparedattachments),
                $this->equalTo($headers)
            );
        // Assert that the mailer attempts to close any remaining open file handles
        $mailer->expects($this->once())
            ->method('closeTempFileHandles');

        // Let's go!
        $this->invokeMethod(
            $mailer,
            'sendMessage',
            [$to, $from, $subject, $content, $plainContent, $attachments, $headers]
        );
    }

    public function testSendMessageBatch()
    {
        $domain = 'http://testdomain.com';
        Config::inst()->update('Kinglozzer\SilverStripeMailgunner\Mailer', 'api_domain', $domain);
        list($to, $from, $subject, $content, $plainContent, $attachments, $headers) = $this->getMockEmail();

        $preparedattachments = [
            ['filePath' => '/foo/bar/baz', 'remoteName' => 'image.jpg']
        ];

         $messageBuilder = $this->getMockBuilder('Mailgun\Messages\BatchMessage')
            ->disableOriginalConstructor()
            ->setMethods(['finalize'])
            ->getMock();
        // We expect that finalize() will be called to send any remaining messages in the queue
        $messageBuilder->expects($this->once())
            ->method('finalize');

        $client = $this->getMock('Mailgun\Mailgun', ['BatchMessage']);
        // We expect that sendMessage() will fetch the message builder from the Mailgun client, and
        // we use this point to inject our mock message builder
        $client->expects($this->once())
            ->method('BatchMessage')
            ->with($this->equalTo($domain))
            ->will($this->returnValue($messageBuilder));

        $mailer = $this->getMock(
            'Kinglozzer\SilverStripeMailgunner\Mailer',
            ['getMailgunClient', 'buildMessage', 'prepareAttachments', 'closeTempFileHandles']
        );
        // We inject our mock Mailgun client while asserting that sendMessage() does request it
        $mailer->expects($this->once())
            ->method('getMailgunClient')
            ->will($this->returnValue($client));
        // We've got attachments, so we assert that sendMessage() passes them off to
        // prepareAttachments() and specify a mock "prepared" return value
        $mailer->expects($this->once())
            ->method('prepareAttachments')
            ->with($this->equalTo($attachments))
            ->will($this->returnValue($preparedattachments));
        // We expect that sendMessage() will pass everything off to the buildMessage() method
        $mailer->expects($this->once())
            ->method('buildMessage')
            ->with(
                $this->equalTo($messageBuilder),
                $this->equalTo($to),
                $this->equalTo($from),
                $this->equalTo($subject),
                $this->equalTo($content),
                $this->equalTo($plainContent),
                $this->equalTo($preparedattachments),
                $this->equalTo($headers)
            );
        // Assert that the mailer attempts to close any remaining open file handles
        $mailer->expects($this->once())
            ->method('closeTempFileHandles');

        // Special header to flag that we want to send a "batch message"
        $headers['X-Mailgunner-Batch-Message'] = true;

        // Let's go!
        $this->invokeMethod(
            $mailer,
            'sendMessage',
            [$to, $from, $subject, $content, $plainContent, $attachments, $headers]
        );
    }

    public function testSendMessageExceptionClosesHandles()
    {
        list($to, $from, $subject, $content, $plainContent, $attachments, $headers) = $this->getMockEmail();

        $client = $this->getMock('Mailgun\Mailgun', ['sendMessage']);
        // Make our mock client trigger an exception
        $client->expects($this->once())
            ->method('sendMessage')
            ->will($this->throwException(new \Exception));

        $mailer = $this->getMock(
            'Kinglozzer\SilverStripeMailgunner\Mailer',
            ['getMailgunClient', 'closeTempFileHandles']
        );
        // Inject our mock Mailgun client
        $mailer->expects($this->once())
            ->method('getMailgunClient')
            ->will($this->returnValue($client));
        // Assert that the exception that the client throws triggers closing open file handles
        $mailer->expects($this->once())
            ->method('closeTempFileHandles');

        $response = $this->invokeMethod(
            $mailer,
            'sendMessage',
            [$to, $from, $subject, $content, $plainContent, $attachments, $headers]
        );

        $this->assertFalse($response);
    }

    public function testBuildMessage()
    {
        list($to, $from, $subject, $content, $plainContent, $attachments, $headers) = $this->getMockEmail();
        // Mock "prepared" attachment
        $attachments = [
            ['filePath' => '/foo/bar/baz', 'remoteName' => 'image.jpg']
        ];

        $messageBuilder = $this->getMock('Mailgun\Messages\MessageBuilder');
        $messageBuilder->expects($this->once())
            ->method('addToRecipient')
            ->with(
                $this->equalTo('foo@bar.com'),
                $this->equalTo(['full_name' => 'Foo Smith'])
            );

        $messageBuilder->expects($this->once())
            ->method('setFromAddress')
            ->with(
                $this->equalTo('baz@bam.com'),
                $this->equalTo(['full_name' => 'Baz Smith'])
            );

        $messageBuilder->expects($this->once())
            ->method('setSubject')
            ->with($this->equalTo($subject));

        $messageBuilder->expects($this->once())
            ->method('setHtmlBody')
            ->with($this->equalTo($content));

        $messageBuilder->expects($this->once())
            ->method('setTextBody')
            ->with($this->equalTo($plainContent));

        $messageBuilder->expects($this->once())
            ->method('addAttachment')
            ->with(
                $this->equalTo('/foo/bar/baz'),
                $this->equalTo('image.jpg')
            );

        $messageBuilder->expects($this->once())
            ->method('addCcRecipient')
            ->with($this->equalTo($headers['Cc']));

        $messageBuilder->expects($this->once())
            ->method('addBccRecipient')
            ->with($this->equalTo($headers['Bcc']));

        $messageBuilder->expects($this->once())
            ->method('addCustomHeader')
            ->with(
                $this->equalTo('X-Custom-Header'),
                $this->equalTo('foo')
            );

        $this->invokeMethod(
            new Mailer,
            'buildMessage',
            [$messageBuilder, $to, $from, $subject, $content, $plainContent, $attachments, $headers]
        );
    }

    public function testParseAddresses()
    {
        $mailer = new Mailer;

        $parsed = $this->invokeMethod($mailer, 'parseAddresses', ['joe.bloggs@example.com']);
        $this->assertEquals(['joe.bloggs@example.com' => ''], $parsed);

        $parsed = $this->invokeMethod($mailer, 'parseAddresses', ['Joe Bloggs <joe.bloggs@example.com>']);
        $this->assertEquals(['joe.bloggs@example.com' => 'Joe Bloggs'], $parsed);

        $parsed = $this->invokeMethod($mailer, 'parseAddresses', ['joe.bloggs@example.com, john.smith@example.com']);
        $this->assertEquals(['joe.bloggs@example.com' => '', 'john.smith@example.com' => ''], $parsed);

        // Test all the different formats
        $raw = '"Joe Bloggs"<joe.bloggs@example.com>; John Smith <john.smith@example.com>';
        $raw .= ', \'James\'<james@example.com>; foo@example.com,<bar@example.com>';
        $expected = [
            'joe.bloggs@example.com' => 'Joe Bloggs',
            'john.smith@example.com' => 'John Smith',
            'james@example.com' => 'James',
            'foo@example.com' => '',
            'bar@example.com' => ''
        ];
        $actual = $this->invokeMethod($mailer, 'parseAddresses', [$raw]);
        $this->assertEquals($expected, $actual);
    }

    public function testPrepareAttachments()
    {
        $attachments = [
            ['filename' => 'test1.jpg', 'contents' => 'abcdefg'],
            ['filename' => 'test2.jpg', 'contents' => 'hijklmn']
        ];

        $expected = [
            ['filePath' => 'tmp/test1.jpg', 'remoteName' => 'test1.jpg'],
            ['filePath' => 'tmp/test2.jpg', 'remoteName' => 'test2.jpg']
        ];

        $mailer = $this->getMock('Kinglozzer\SilverStripeMailgunner\Mailer', ['writeToTempFile']);
        $mailer->expects($this->at(0))
            ->method('writeToTempFile')
            ->with($this->equalTo('abcdefg'))
            ->will($this->returnValue('tmp/test1.jpg'));
        $mailer->expects($this->at(1))
            ->method('writeToTempFile')
            ->with($this->equalTo('hijklmn'))
            ->will($this->returnValue('tmp/test2.jpg'));

        $prepared =  $this->invokeMethod($mailer, 'prepareAttachments', [$attachments]);
        $this->assertEquals($expected, $prepared);
    }

    public function testWriteToTempFile()
    {
        $contents = 'test file contents';
        $mailer = new Mailer;
        $tempFile = $this->invokeMethod($mailer, 'writeToTempFile', [$contents]);

        $this->assertEquals($contents, file_get_contents($tempFile));

        // Assert that the stream and temp file path are stored
        $reflection = new \ReflectionClass(get_class($mailer));
        $property = $reflection->getProperty('tempFileHandles');
        $property->setAccessible(true);
        $fileHandles = $property->getValue($mailer);

        $this->assertNotEmpty($fileHandles);

        // Test the contents of the stream
        $handle = $fileHandles[0]['handle'];
        rewind($handle);
        $this->assertEquals($contents, fread($handle, filesize($fileHandles[0]['path'])));
        $this->assertEquals($tempFile, $fileHandles[0]['path']);
    }

    public function testCloseTempFileHandles()
    {
        $mailer = new Mailer;
        $tempFile = tempnam(sys_get_temp_dir(), 'SS_MG_TESTS_TMP');
        $fileHandle = fopen($tempFile, 'w');
        fwrite($fileHandle, 'test data');
        $handleData = ['handle' => $fileHandle, 'path' => $tempFile];

        $reflection = new \ReflectionClass(get_class($mailer));
        $property = $reflection->getProperty('tempFileHandles');
        $property->setAccessible(true);
        $fileHandles = $property->setValue($mailer, [$handleData]);

        $this->invokeMethod($mailer, 'closeTempFileHandles');

        $this->assertEmpty($property->getValue($mailer));
        $this->assertFalse(file_exists($tempFile));
        $this->assertEquals('Unknown', get_resource_type($fileHandle));
    }
}

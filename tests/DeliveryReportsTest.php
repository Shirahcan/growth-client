<?php

namespace Shirahcan\GrowthClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\Delivery\DeliveryReports;
use Shirahcan\GrowthClient\Exceptions\GrowthServiceException;
use Shirahcan\GrowthClient\GrowthServiceClient;

/** G5h: verdicts forwarded to growth-service, and spam complaints read out of a feedback-loop (ARF) report. */
class DeliveryReportsTest extends TestCase
{
    private array $history = [];

    private function reports(array $responses): DeliveryReports
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new DeliveryReports(new GrowthServiceClient('http://127.0.0.1:8011', 'k-portify', 5, new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8011/'])));
    }

    private const ARF = "From: staff@hotmail.com\r\nTo: abuse@portify.example\r\nSubject: complaint about message\r\nMIME-Version: 1.0\r\n"
        ."Content-Type: multipart/report; report-type=feedback-report;\r\n\tboundary=\"part1\"\r\n\r\n"
        ."--part1\r\nContent-Type: text/plain\r\n\r\nThis is an email abuse report.\r\n\r\n"
        ."--part1\r\nContent-Type: message/feedback-report\r\n\r\nFeedback-Type: abuse\r\nUser-Agent: Hotmail FBL\r\nVersion: 1\r\nOriginal-Mail-From: <bounce@portify.example>\r\nOriginal-Rcpt-To: <Reader@Example.com>\r\n\r\n"
        ."--part1\r\nContent-Type: message/rfc822\r\nContent-Disposition: inline\r\n\r\nFrom: news@portify.example\r\nTo: reader@example.com\r\nMessage-ID: <n-42@portify.example>\r\nSubject: November news\r\n\r\nHello.\r\n--part1--\r\n";

    public function test_it_reads_the_complained_recipient_and_the_original_message_id_from_an_arf_report(): void
    {
        $this->assertSame([['email' => 'reader@example.com', 'ref' => '<n-42@portify.example>', 'type' => 'abuse']], DeliveryReports::complaintsIn(self::ARF));
    }

    public function test_a_redacted_recipient_falls_back_to_the_originals_to_and_other_mail_is_not_a_complaint(): void
    {
        $redacted = str_replace("Original-Rcpt-To: <Reader@Example.com>\r\n", '', self::ARF);
        $this->assertSame('reader@example.com', DeliveryReports::complaintsIn($redacted)[0]['email']);

        $notSpam = str_replace('Feedback-Type: abuse', 'Feedback-Type: not-spam', self::ARF);
        $this->assertSame([], DeliveryReports::complaintsIn($notSpam));
        $this->assertSame([], DeliveryReports::complaintsIn("From: a@b.c\r\nSubject: hi\r\n\r\nJust a note."));
        $this->assertSame([], DeliveryReports::complaintsIn(str_replace(['Original-Rcpt-To: <Reader@Example.com>', 'To: reader@example.com'], ['', 'To: redacted'], self::ARF)), 'a report that names nobody is skipped');
    }

    public function test_it_forwards_a_verdict_and_throws_when_the_service_refuses(): void
    {
        $reports = $this->reports([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['suppressed' => true, 'scope' => 'estate']])),
            new Response(422, ['Content-Type' => 'application/json'], json_encode(['message' => 'The verdict is not known.'])),
        ]);

        $this->assertSame('estate', $reports->report(' Gone@Example.com ', DeliveryReports::HARD_BOUNCE, '550 5.1.1', '<m1>', 'dsn')['scope']);
        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('api/v1/delivery-reports', ltrim($this->history[0]['request']->getUri()->getPath(), '/'));
        $this->assertSame(['email' => 'gone@example.com', 'verdict' => 'hard_bounce', 'detail' => '550 5.1.1', 'ref' => '<m1>', 'source' => 'dsn'], $sent);

        $this->expectException(GrowthServiceException::class);
        $reports->report('a@example.com', DeliveryReports::COMPLAINT);
    }

    public function test_an_unknown_verdict_never_leaves_the_product(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeliveryReports::body('a@example.com', 'maybe');
    }
}

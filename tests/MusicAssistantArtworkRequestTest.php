<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

class MusicAssistantArtworkRequestTest extends MusicAssistantTestCase
{
    public function testArtworkIsLoadedWithoutAuthentication(): void
    {
        $connectionId = $this->createConnection();
        $proxyId = $this->proxyId('a');
        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody()));

        $response = $this->requestArtwork($connectionId, $proxyId);

        $this->assertSame([
            'success'      => true,
            'proxy_id'     => $proxyId,
            'content_type' => 'image/jpeg',
            'content'      => base64_encode($this->jpegBody())
        ], $response);
        $this->assertSame([[
            'url'     => 'http://127.0.0.1:8095/imageproxy/' . $proxyId . '?size=512&fmt=jpg',
            'headers' => ['Accept: image/jpeg, image/png']
        ]], $this->artworkRequests());
    }

    public function testIpv6HostIsBracketedInArtworkUrl(): void
    {
        $connectionId = $this->createConnection('fd00::15');
        $proxyId = $this->proxyId('a');
        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody()));

        $this->requestArtwork($connectionId, $proxyId);

        $this->assertSame(
            'http://[fd00::15]:8095/imageproxy/' . $proxyId . '?size=512&fmt=jpg',
            $this->artworkRequests()[0]['url']
        );
    }

    public function testContentTypeIsNormalized(): void
    {
        $connectionId = $this->createConnection();
        $png = "\x89PNG\r\n\x1A\n" . 'cover';
        $this->simulateArtwork($this->artworkResponse(200, ' Image/PNG; charset=binary ', $png));

        $response = $this->requestArtwork($connectionId, $this->proxyId('a'));

        $this->assertTrue($response['success']);
        $this->assertSame('image/png', $response['content_type']);
        $this->assertSame(base64_encode($png), $response['content']);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function unauthorizedProvider(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    /**
     * @dataProvider unauthorizedProvider
     */
    public function testUnauthorizedResponseIsRetriedWithToken(int $httpCode): void
    {
        $connectionId = $this->createConnection();
        $this->simulateArtwork(
            $this->artworkResponse($httpCode, 'text/plain', 'denied'),
            $this->artworkResponse(200, 'image/jpeg', $this->jpegBody())
        );

        $response = $this->requestArtwork($connectionId, $this->proxyId('a'));

        $this->assertTrue($response['success']);
        $this->assertSame(
            [
                ['Accept: image/jpeg, image/png'],
                ['Accept: image/jpeg, image/png', 'Authorization: Bearer ' . self::TOKEN]
            ],
            array_column($this->artworkRequests(), 'headers')
        );
    }

    public function testFailedAuthenticatedRetryIsReported(): void
    {
        $connectionId = $this->createConnection();
        $this->simulateArtwork(
            $this->artworkResponse(401, 'text/plain', 'denied'),
            $this->artworkResponse(401, 'text/plain', 'denied')
        );

        $response = $this->requestArtwork($connectionId, $this->proxyId('a'));

        $this->assertFalse($response['success']);
        $this->assertSame('IMAGE_ERROR', $response['error']['code']);
        $this->assertCount(2, $this->artworkRequests());
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function invalidArtworkProvider(): array
    {
        $jpeg = "\xFF\xD8\xFF\xE0cover";
        $base = [
            'transfer_succeeded' => true,
            'http_code'          => 200,
            'content_type'       => 'image/jpeg',
            'body'               => $jpeg,
            'size_exceeded'      => false,
            'error_number'       => 0
        ];

        return [
            'HTTP status'        => [['http_code' => 404] + $base],
            'content type'       => [['content_type' => 'text/html'] + $base],
            'empty body'         => [['body' => ''] + $base],
            'signature mismatch' => [['content_type' => 'image/png'] + $base],
            'size exceeded'      => [['size_exceeded' => true, 'transfer_succeeded' => false] + $base],
            'curl size limit'    => [['error_number' => CURLE_FILESIZE_EXCEEDED, 'transfer_succeeded' => false] + $base],
            'connection failed'  => [['transfer_succeeded' => false, 'http_code' => 0, 'error_number' => 7] + $base],
            // Abbruch nach Empfang der Header: Status und Bildanfang sind gültig, die Daten aber unvollständig.
            'transfer timeout'   => [['transfer_succeeded' => false, 'error_number' => 28] + $base]
        ];
    }

    /**
     * @dataProvider invalidArtworkProvider
     */
    public function testInvalidArtworkIsRejected(array $artworkResponse): void
    {
        $connectionId = $this->createConnection();
        $this->simulateArtwork($artworkResponse);

        $response = $this->requestArtwork($connectionId, $this->proxyId('a'));

        $this->assertFalse($response['success']);
        $this->assertSame('IMAGE_ERROR', $response['error']['code']);
        $this->assertCount(1, $this->artworkRequests());
    }

    public function testInvalidProxyIdIsRejectedWithoutRequest(): void
    {
        $connectionId = $this->createConnection();

        $response = $this->requestArtwork($connectionId, '../etc/passwd');

        $this->assertFalse($response['success']);
        $this->assertSame('INVALID_REQUEST', $response['error']['code']);
        $this->assertSame([], $this->artworkRequests());
    }

    private function requestArtwork(int $connectionId, string $proxyId): array
    {
        return $this->forwardToConnection($connectionId, ['Command' => 'ArtworkRequest', 'ProxyID' => $proxyId]);
    }

    private function proxyId(string $seed): string
    {
        return hash('sha256', $seed);
    }
}

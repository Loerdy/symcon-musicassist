<?php
declare(strict_types=1);

// Abruf von Bildern über den Imageproxy der Connection; genutzt von Player und Schnellzugriff.
trait MusicAssistantArtworkClient
{
    private function requestArtwork(string $proxyId): array
    {
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::CONNECTION_REQUEST,
            'Command' => 'ArtworkRequest',
            'ProxyID' => $proxyId
        ], JSON_THROW_ON_ERROR));
        if (!is_string($response) || trim($response) === '') {
            throw new Exception($this->Translate('ArtworkRequest did not return a valid response.'));
        }
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            throw new Exception($this->Translate('ArtworkRequest failed.'));
        }
        return $result;
    }

    /**
     * @return array{content: string, contentType: string} Base64-Inhalt und geprüfter Content-Type
     */
    private function validateArtworkResponse(array $response, string $proxyId): array
    {
        $content = $response['content'] ?? null;
        $contentType = $response['content_type'] ?? null;
        if (($response['proxy_id'] ?? null) !== $proxyId
            || !in_array($contentType, ['image/jpeg', 'image/png'], true)
            || !is_string($content)) {
            throw new Exception($this->Translate('Artwork response is invalid.'));
        }
        $binary = base64_decode($content, true);
        $validSignature = is_string($binary) && $binary !== '' && ($contentType === 'image/jpeg'
            ? substr($binary, 0, 3) === "\xFF\xD8\xFF"
            : substr($binary, 0, 8) === "\x89PNG\r\n\x1A\n");
        if (!$validSignature) {
            throw new Exception($this->Translate('Artwork signature does not match the content type.'));
        }
        return ['content' => $content, 'contentType' => $contentType];
    }
}

<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Helper\OAuth;

use M2Oidc\OAuth\Helper\OAuth\AccessTokenRequestBody;
use M2Oidc\OAuth\Helper\OAuthConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AccessTokenRequestBody.
 *
 * Verifies:
 *  - Public clients (no Authorization header) get client_id in the body
 *    (RFC 6749 §3.2.1)
 *  - Confidential clients (HTTP Basic auth) do NOT duplicate client_id in
 *    the body (RFC 6749 §2.3.1)
 *  - PKCE code_verifier handling is unaffected
 *
 * @covers \M2Oidc\OAuth\Helper\OAuth\AccessTokenRequestBody
 */
class AccessTokenRequestBodyTest extends TestCase
{
    private const REDIRECT_URL = 'https://store.example.com/m2oidc/actions/readauthresponse';
    private const AUTH_CODE    = 'auth-code-123';

    public function testPublicClientIncludesClientIdInBody(): void
    {
        $body = (new AccessTokenRequestBody(
            self::REDIRECT_URL,
            self::AUTH_CODE,
            'pkce-verifier-xyz',
            'public-client-id'
        ))->build();

        $this->assertArrayHasKey('client_id', $body, 'public client must carry client_id in the body');
        $this->assertSame('public-client-id', $body['client_id']);
        $this->assertSame(self::REDIRECT_URL, $body['redirect_uri']);
        $this->assertSame(OAuthConstants::GRANT_TYPE, $body['grant_type']);
        $this->assertSame(self::AUTH_CODE, $body['code']);
        $this->assertSame('pkce-verifier-xyz', $body['code_verifier']);
    }

    public function testConfidentialClientWithHeaderAuthOmitsClientId(): void
    {
        // Confidential clients authenticate via HTTP Basic (Authorization header);
        // the caller passes null so client_id is not duplicated (RFC 6749 §2.3.1).
        $body = (new AccessTokenRequestBody(
            self::REDIRECT_URL,
            self::AUTH_CODE,
            'pkce-verifier-xyz'
        ))->build();

        $this->assertArrayNotHasKey(
            'client_id',
            $body,
            'Confidential client using Basic auth must not duplicate client_id in the body'
        );
        $this->assertSame(self::REDIRECT_URL, $body['redirect_uri']);
        $this->assertSame(OAuthConstants::GRANT_TYPE, $body['grant_type']);
        $this->assertSame(self::AUTH_CODE, $body['code']);
    }

    public function testEmptyClientIdIsNotIncluded(): void
    {
        $body = (new AccessTokenRequestBody(
            self::REDIRECT_URL,
            self::AUTH_CODE,
            null,
            ''
        ))->build();

        $this->assertArrayNotHasKey('client_id', $body, 'Empty client_id must not be sent');
    }

    public function testNullCodeVerifierOmitsCodeVerifierKey(): void
    {
        $body = (new AccessTokenRequestBody(self::REDIRECT_URL, self::AUTH_CODE))->build();

        $this->assertArrayNotHasKey('code_verifier', $body);
        $this->assertArrayNotHasKey('client_id', $body);
    }
}

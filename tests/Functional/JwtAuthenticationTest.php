<?php

declare(strict_types=1);

/*

Copyright (c) 2015-2022 Mika Tuupola

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

*/

/**
 * @see       https://github.com/tuupola/slim-jwt-auth
 * @see       https://appelsiini.net/projects/slim-jwt-auth
 */

namespace Tuupola\Tests\Middleware;

use Equip\Dispatch\MiddlewareCollection;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Token\Plain;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Tuupola\Http\Factory\ResponseFactory;
use Tuupola\Http\Factory\ServerRequestFactory;
use Tuupola\Http\Factory\StreamFactory;
use Tuupola\Middleware\JwtAuthentication;
use Tuupola\Middleware\JwtAuthentication\RequestMethodRule;
use Tuupola\Middleware\JwtAuthentication\RequestPathRule;
use Tuupola\Middleware\JwtAuthenticationOption;
use Tuupola\Middleware\JwtAuthentificationAcl;
use Tuupola\Middleware\JwtAuthentificationAfter;
use Tuupola\Middleware\JwtAuthentificationBefore;
use Tuupola\Middleware\JwtAuthentificationError;
use Tuupola\Tests\Middleware\Assets\TestAfterHandler;
use Tuupola\Tests\Middleware\Assets\TestBeforeHandler;
use Tuupola\Tests\Middleware\Assets\TestErrorHandler;

use function assert;
use function json_encode;

/** @psalm-suppress UnusedClass */
class JwtAuthenticationTest extends TestCase
{
    /* @codingStandardsIgnoreStart */
    public static string $acmeToken = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiIsImZvbyI6ImJhciJ9.eyJpc3MiOiJBY21lIFRvb3RocGljcyBMdGQiLCJhdWQiOiJ3d3cuZXhhbXBsZS5jb20iLCJqdGkiOiI0ZjFnMjNhMTJhYSIsImlhdCI6MTY5NTgzNDEzOC41MjI4MjQsIm5iZiI6MTY5NTgzNDE5OC41MjI4MjQsImV4cCI6MjMyNjk4NjEzOC41MjI4MjQsInVpZCI6MX0.4oMy-zTQDQI_4-MuiIrbzAIoZwiiAq9H394_c0w-FT0";
    public static string $betaToken = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiIsImZvbyI6ImJhciJ9.eyJpc3MiOiJBY21lIFRvb3RocGljcyBMdGQiLCJhdWQiOiJ3d3cueW95b295by5jb20iLCJqdGkiOiI0ZjFnMjNhMTJhYSIsImlhdCI6MTY5NTgzNDc2Ny42ODcwNTQsIm5iZiI6MTY5NTgzNDgyNy42ODcwNTQsImV4cCI6MjMyNjk4Njc2Ny42ODcwNTQsInVpZCI6MX0.9OnoIErgS3LnNMwmhy2JPY3Vt2f58I8fbvJDO8H8jis";
    public static string $expired = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiIsImZvbyI6ImJhciJ9.eyJpc3MiOiJBY21lIFRvb3RocGljcyBMdGQiLCJhdWQiOiJ3d3cueW95b295by5jb20iLCJqdGkiOiI0ZjFnMjNhMTJhYSIsImlhdCI6MTY5NTgzNDc5MC45MDA5NzMsIm5iZiI6MTY5NTgzNDg1MC45MDA5NzMsImV4cCI6MTA2NDY4Mjc5MC45MDA5NzMsInVpZCI6MX0.c4rxuu3ZUsuP-xhbj8c4B-b6d0XYl3GiliUQW58IBcc";
    /* @codingStandardsIgnoreEnd */

    /** @var array<string, string|string[]> */
    public static array $acmeTokenArray = ['iss' => 'Acme Toothpics Ltd'];

    /** @var array<string, string|string[]> */
    public static array $betaTokenArray = ['iss' => 'Beta Sponsorship Ltd'];

    public function testShouldReturn401WithoutToken(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('debug')->with('Token not found', []);

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('', $response->getBody());
    }

    public function testShouldReturn200WithTokenFromHeader(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('X-Token', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('debug')->with('Using token from request header', []);

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))->withHeader('X-Token');

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldReturn200WithTokenFromHeaderWithCustomRegexp(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('X-Token', self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('debug')->with('Using token from request header', []);

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withHeader('X-Token')
            ->withRegexp('/(.*)/');

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldReturn200WithTokenFromCookie(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withCookieParams(['nekot' => self::$acmeToken]);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('debug')->with('Using token from cookie', []);

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withCookie('nekot');

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldReturn200WithTokenFromCookieButEmptyValue(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withCookieParams(['nekot' => '']);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('debug')->with('Using token from cookie', []);

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withCookie('nekot');

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        $body = $response->getBody();
        $body->rewind();
        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('', $body->getContents());
    }

    public function testShouldReturn200WithTokenFromBearerCookie(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withCookieParams(['nekot' => 'Bearer ' . self::$acmeToken]);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('debug')->with('Using token from cookie', []);

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withCookie('nekot');

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldAlterResponseWithAnonymousAfter(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withAfter(new class implements JwtAuthentificationAfter {
                public function __invoke(ResponseInterface $response, Plain $token): ResponseInterface
                {
                    return $response->withHeader('X-Brawndo', 'plants crave');
                }
            });

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('plants crave', $response->getHeaderLine('X-Brawndo'));
    }

    public function testWronParser(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Token not signed', [self::$acmeToken]);

        $token = self::createMock(Token::class);
        $token->expects(self::once())->method('isExpired')->willReturn(false);
        $parser = self::createMock(Parser::class);
        $parser->expects(self::once())->method('parse')->willReturn($token);

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withAfter(new TestAfterHandler());

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger, $parser),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
    }

    public function testShouldAlterResponseWithInvokableAfter(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withAfter(new TestAfterHandler());

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('plants crave', $response->getHeaderLine('X-Brawndo'));
    }

    public function testShouldReturn200WithOptions(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withMethod('OPTIONS');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldReturn400WithInvalidToken(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer invalid' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('', $response->getBody());
    }

    public function testShouldReturn400WithExpiredToken(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$expired);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Token expired', [self::$expired]);
        $logger->expects(self::once())->method('debug')->with('Using token from request header', []);

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('', $response->getBody());
    }

    public function testShouldReturn200WithoutTokenWithPath(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/public');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->addRule(new RequestPathRule(['/api', '/foo'], []));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldReturn200WithoutTokenWithIgnore(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api/ping');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->addRule(new RequestPathRule(['/api', '/foo'], ['/api/ping']));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldNotAllowInsecure(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Insecure use of middleware over HTTP denied by configuration');
        $collection->dispatch($request, $default);
    }

    public function testShouldAllowInsecure(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withSecure(false);

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldRelaxInsecureInLocalhost(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldRelaxInsecureInExampleCom(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withRelaxed(['example.com']);

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldAttachToken(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (ServerRequestInterface $request): ResponseInterface {
            $decodedToken = $request->getAttribute('token');

            assert($decodedToken instanceof Plain);

            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write((string) json_encode($decodedToken->claims()->get('iss')));
            $response->getBody()->rewind();

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        $body = $response->getBody();
        $body->rewind();

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('"Acme Toothpics Ltd"', $body->getContents());
    }

    public function testShouldAttachCustomToken(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (ServerRequestInterface $request): ResponseInterface {
            $decodedToken = $request->getAttribute('nekot');

            assert($decodedToken instanceof Plain);

            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write((string) json_encode($decodedToken->claims()->get('iss')));

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withAttribute('nekot');

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        $body = $response->getBody();
        $body->rewind();

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('"Acme Toothpics Ltd"', $body->getContents());
    }

    public function testShouldCallAfterWithProperArguments(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withAfter(new class implements JwtAuthentificationAfter {
                public function __invoke(ResponseInterface $response, Plain $token): ResponseInterface
                {
                    return $response->withHeader('decoded', (string) json_encode($token->claims()->get('iss')));
                }
            });

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
        self::assertJsonStringEqualsJsonString((string) json_encode(self::$acmeTokenArray['iss']), $response->getHeaderLine('decoded'));
    }

    public function testShouldCallBeforeWithProperArguments(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (ServerRequestInterface $request): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success' . (string) json_encode($request->getAttribute('decoded')));

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withBefore(new class implements JwtAuthentificationBefore {
                public function __invoke(ServerRequestInterface $request, Plain $token): ServerRequestInterface
                {
                    return $request->withAttribute('decoded', (string) json_encode($token->claims()->get('iss')));
                }
            });

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        $body = $response->getBody();

        $body->rewind();
        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success' . (string) json_encode(json_encode(self::$acmeTokenArray['iss'])), $body->getContents());
    }

    public function testShouldCallAnonymousErrorFunction(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withError(new class implements JwtAuthentificationError {
                public function __invoke(ServerRequestInterface $request, ResponseInterface $response, Throwable $exception): ResponseInterface
                {
                    $response->getBody()->write('error');

                    return $response
                        ->withHeader('X-Electrolytes', 'Plants');
                }
            });

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('Plants', $response->getHeaderLine('X-Electrolytes'));
        self::assertEquals('error', $response->getBody());
    }

    public function testShouldCallInvokableErrorClass(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withError(new TestErrorHandler());

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(402, $response->getStatusCode());
        self::assertEquals('Bar', $response->getHeaderLine('X-Foo'));
        self::assertEquals(TestErrorHandler::class, $response->getBody());
    }

    public function testShouldCallErrorAndModifyBody(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withError(new class implements JwtAuthentificationError {
                public function __invoke(ServerRequestInterface $request, ResponseInterface $response, Throwable $exception): ResponseInterface
                {
                     $response->getBody()->write('Error');

                    return $response;
                }
            });

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('Error', $response->getBody());
    }

    public function testShouldAllowUnauthenticatedHttp(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/public/foo');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->addRule(new RequestPathRule(['/api', '/foo'], []));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);
        $response   = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldReturn401FromAfter(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withAfter(new class implements JwtAuthentificationAfter {
                public function __invoke(ResponseInterface $response, Plain $token): ResponseInterface
                {
                    return $response
                        ->withBody((new StreamFactory())->createStream())
                        ->withStatus(401);
                }
            });

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('', $response->getBody());
    }

    public function testShouldModifyRequestUsingAnonymousBefore(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (ServerRequestInterface $request): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write((string) json_encode($request->getAttribute('test')));

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withBefore(new class implements JwtAuthentificationBefore {
                public function __invoke(ServerRequestInterface $request, Plain $token): ServerRequestInterface
                {
                    return $request->withAttribute('test', 'test');
                }
            });

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('"test"', (string) $response->getBody());
    }

    public function testShouldModifyRequestUsingInvokableBefore(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (ServerRequestInterface $request): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write((string) json_encode($request->getAttribute('test')));

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withBefore(new TestBeforeHandler());

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('"invoke"', (string) $response->getBody());
    }

    public function testShouldHandleRulesArrayBug84(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withRules(
                new RequestPathRule(['/api'], ['/api/login']),
                new RequestMethodRule(['OPTIONS']),
            );

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('', $response->getBody());

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api/login');

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldHandleDefaultPathBug118(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->addRule(new RequestPathRule(['/'], ['/api/login']));

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('', $response->getBody());

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api/login');

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldBindToMiddleware(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer ' . self::$acmeToken);

        $default = static function (ServerRequestInterface $request): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write((string) json_encode($request->getAttribute('before')));

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $option =                 JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withAfter(new class implements JwtAuthentificationAfter {
                public function __invoke(ResponseInterface $response, Plain $token): ResponseInterface
                {
                     $response->getBody()->write('im after');

                    return $response;
                }
            })
            ->withBefore(new class implements JwtAuthentificationBefore {
                public function __invoke(ServerRequestInterface $request, Plain $token): ServerRequestInterface
                {
                    return $request->withAttribute('before', 'im before');
                }
            });

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);
        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('"im before"im after', (string) $response->getBody());
    }

    public function testShouldHandlePsr7(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withHeader('X-Token', 'Bearer ' . self::$acmeToken);

        $response = (new ResponseFactory())->createResponse();

        $option =                 JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withHeader('X-Token');

        $auth = new JwtAuthentication($option);

        $next = static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write('Success');

            return $response;
        };

        $response = $auth($request, $response, $next);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }

    public function testShouldHaveUriInErrorHandlerIssue96(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api/foo?bar=pop');

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $option =                 JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withError(new class implements JwtAuthentificationError {
                public function __invoke(ServerRequestInterface $request, ResponseInterface $response, Throwable $exception): ResponseInterface
                {
                    return $response->withHeader('X-Uri', (string) $request->getUri());
                }
            });

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(401, $response->getStatusCode());
        self::assertEquals('', $response->getBody());
        self::assertEquals('https://example.com/api/foo?bar=pop', $response->getHeaderLine('X-Uri'));
    }

    public function testShouldUseCookieIfHeaderMissingIssue156(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api')
            ->withCookieParams(['token' => self::$acmeToken]);

        $default = static function (): ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $response->getBody()->write('Success');

            return $response;
        };

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $option = JwtAuthenticationOption::create(InMemory::base64Encoded('mBC5v1sOKVvbdEitdSBenu59nfNfhwkedkJVNabosTw='))
            ->withHeader('X-Token')
            ->withRegexp('/(.*)/');

        $collection = new MiddlewareCollection([
            new JwtAuthentication($option, $logger),
            new JwtAuthentificationAcl($option),
        ]);

        $response = $collection->dispatch($request, $default);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('Success', $response->getBody());
    }
}
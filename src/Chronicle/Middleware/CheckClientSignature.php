<?php
declare(strict_types=1);
namespace ParagonIE\Chronicle\Middleware;

use ParagonIE\Chronicle\Chronicle;
use ParagonIE\Chronicle\Exception\ClientNotFound;
use ParagonIE\Chronicle\Exception\SecurityViolation;
use ParagonIE\Chronicle\MiddlewareInterface;
use Psr\Http\Message\{
    RequestInterface,
    ResponseInterface
};
use Slim\Http\Request;

/**
 * Class CheckClientSignature
 *
 * Checks the client signature on a RequestInterface
 *
 * @package ParagonIE\Chronicle\Middleware
 */
class CheckClientSignature implements MiddlewareInterface
{
    const PROPERTIES_TO_SET = ['authenticated'];

    /**
     * @param RequestInterface $request
     * @return string
     * @throws ClientNotFound
     * @throws SecurityViolation
     */
    public function getClientId(RequestInterface $request): string
    {
        $header = $request->getHeader(Chronicle::CLIENT_IDENTIFIER_HEADER);
        if (!$header) {
            throw new ClientNotFound('No client header provided');
        }
        if (\count($header) !== 1) {
            throw new SecurityViolation('Only one client header may be provided');
        }
        return (string) \array_shift($header);
    }

    /**
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param callable $next
     * @return ResponseInterface
     * @throws ClientNotFound
     */
    public function __invoke(
        RequestInterface $request,
        ResponseInterface $response,
        callable $next
    ): ResponseInterface {
        try {
            // Get the client ID from the request
            $clientId = $this->getClientId($request);
        } catch (\Exception $ex) {
            return Chronicle::errorResponse($response, $ex->getMessage(), 403);
        }

        try {
            $publicKey = Chronicle::getClientsPublicKey($clientId);
        } catch (ClientNotFound $ex) {
            return Chronicle::errorResponse($response, 'Invalid client', 403);
        }

        try {
            $request = Chronicle::getSapient()->verifySignedRequest($request, $publicKey);
            if ($request instanceof Request) {
                $serverPublicKey = Chronicle::getSigningKey()->getPublicKey()->getString();
                if (\hash_equals($serverPublicKey, $publicKey->getString())) {
                    return Chronicle::errorResponse(
                        $response,
                        "The server's signing keys cannot be used by clients.",
                        403
                    );
                }
                // Cache authenticated status in the request
                foreach (static::PROPERTIES_TO_SET as $prop) {
                    $request = $request->withAttribute($prop, true);
                }
                // Store the public key in the request as well
                $request = $request->withAttribute('publicKey', $publicKey);
            }
        } catch (\Throwable $ex) {
            return Chronicle::errorResponse($response, $ex->getMessage(), 403);
        }

        return $next($request, $response);
    }
}

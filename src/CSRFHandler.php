<?php

namespace Riimu\Kit\CSRF;

use Riimu\Kit\SecureRandom\SecureRandom;

/**
 * CSRF token validator and generator.
 *
 * CSRFHandler provides a simple way to generate and validate CSRF tokens.
 * Precautions have been taken to avoid timing and BREACH attacks. For secure
 * random bytes, the library uses Kit\SecureRandom library to handle
 * generating tokens and random encryption keys for each request.
 *
 * @author Riikka Kalliomäki <riikka.kalliomaki@gmail.com>
 * @copyright Copyright (c) 2014, Riikka Kalliomäki
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class CSRFHandler
{
    /**
     * List of request methods validated by validateRequest() method.
     * @var string[]
     */
    protected $validatedMethods = ['POST', 'PUT', 'DELETE'];

    /**
     * Number of bytes used in CSRF tokens.
     * @var integer
     */
    protected $tokenLength = 32;

    /**
     * Secure random generator for generating bytes.
     * @var \Riimu\Kit\SecureRandom\SecureRandom
     */
    private $generator;

    /**
     * Persistent storage where to store the actual token.
     * @var Storage\CookieStorage
     */
    private $storage;

    /**
     * Available sources where to look for the CSRF token.
     * @var array
     */
    private $sources;

    /**
     * Current actual csrf token.
     * @var string
     */
    private $token;

    /**
     * Creates a new instance of CSRFHandler.
     * @param boolean $useCookies True to store the token in cookies, false for session
     */
    public function __construct($useCookies = true)
    {
        $this->token = null;
        $this->generator = new SecureRandom();
        $this->storage = $useCookies ? new Storage\CookieStorage() : new Storage\SessionStorage();
        $this->sources = [
            new Source\PostSource(),
            new Source\HeaderSource(),
        ];
    }

    /**
     * Sets the random generator for secure bytes.
     * @param SecureRandom $generator Secure random generator for tokens
     */
    public function setGenerator(SecureRandom $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Sets the persistent storage for tokens.
     * @param Storage\TokenStorage $storage Persistent storage handler for tokens
     */
    public function setStorage(Storage\TokenStorage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Sets the possible the token sources.
     *
     * Multiple sources can be added using an array. The handler will look for
     * the token from the sources in the order they appear in the array.
     *
     * @param Source\TokenSource[] $sources List of token sources.
     */
    public function setSources(array $sources)
    {
        $this->sources = [];

        foreach ($sources as $source) {
            $this->addSource($source);
        }
    }

    /**
     * Adds additional token source.
     * @param Source\TokenSource $source Token source to use.
     */
    private function addSource(Source\TokenSource $source)
    {
        $this->sources[] = $source;
    }

    /**
     * Validates the csrf token in the http request.
     *
     * The intention of this method is to be called at the beginning of each
     * request. There is no need to check for the request type, since the
     * token validation will be skipped for all but POST, PUT and DELETE
     * requests.
     *
     * If the token validation fails, the method will send a HTTP 400 response
     * and kill the script. You can alternatively set the throw argument to
     * true, which will cause the method to send an exception instead.
     *
     * For loading and storing the csrf token, this method should be called
     * after the session has been started but before headers have been sent.
     *
     * @param boolean $throw True to throw exception on error instead of dying
     * @return true Always returns true
     * @throws InvalidCSRFTokenException If throwing is enabled and csrf token is invalid
     */
    public function validateRequest($throw = false)
    {
        $this->getTrueToken();

        if (!in_array($_SERVER['REQUEST_METHOD'], $this->validatedMethods)) {
            return true;
        }

        $token = $this->getRequestToken();

        if ($token === false || !$this->validateToken($token)) {
            if ($throw) {
                throw new InvalidCSRFTokenException('Request token was invalid');
            } else { // @codeCoverageIgnoreStart
                header('HTTP/1.0 400 Bad Request');
                die;
            } // @codeCoverageIgnoreEnd
        }

        return true;
    }

    /**
     * Validates the csrf token.
     *
     * The token must be provided as a base64 encoded string, which is provided
     * by the getToken() method.
     *
     * @param string $token The base64 encoded token provided by getToken()
     * @return boolean True if the token is valid, false if it is not
     */
    public function validateToken($token)
    {
        if (!is_string($token)) {
            return false;
        }

        $token = base64_decode($token);

        if (strlen($token) !== $this->tokenLength * 2) {
            return false;
        }

        list($key, $encrypted) = str_split($token, $this->tokenLength);
        return $this->constantTimeCompare($key ^ $encrypted, $this->getTrueToken());
    }

    /**
     * Generates a new secure base64 encoded csrf token for forms.
     *
     * Every time this method called, a new string is returned. The actual token
     * does not change, but a new encryption key for the token is generated on
     * each call.
     *
     * @return string Base64 encoded and encrypted csrf token
     */
    public function getToken()
    {
        $key = $this->generator->getBytes($this->tokenLength);
        return base64_encode($key . ($key ^ $this->getTrueToken()));
    }

    /**
     * Regenerates the actual csrf token.
     *
     * After this method is called, any token generated previously by getToken()
     * will no longer validate. It is highly recommended to regenerate the
     * csrf token after user authentication.
     *
     * @return CSRFHandler Returns self for call chaining
     */
    public function regenerateToken()
    {
        $this->token = false;
        $this->getTrueToken();

        return $this;
    }

    /**
     * Returns the current actual csrf token.
     *
     * This returns the actual 32 byte string that sent tokens are validated
     * against. Note that the bytes are random and should not be used without
     * proper escaping.
     *
     * @return string The current actual token
     */
    public function getTrueToken()
    {
        if (!isset($this->token)) {
            $this->token = $this->storage->getStoredtoken();
        }

        if ($this->token === false || strlen($this->token) !== $this->tokenLength) {
            $this->token = $this->generator->getBytes($this->tokenLength);
            $this->storage->storeToken($this->token);
        }

        return $this->token;
    }

    /**
     * Returns the token sent in the request.
     * @return string|false The token sent in the request or false if none
     */
    private function getRequestToken()
    {
        foreach ($this->sources as $source) {
            if (($token = $source->getRequestToken()) !== false) {
                break;
            }
        }

        return $token;
    }

    /**
     * Compares two string in constant time.
     * @param string $a First string to compare
     * @param string $b Second string to compare
     * @return boolean True if the strings are equal, false if not
     */
    private function constantTimeCompare($a, $b)
    {
        $result = "\x00";

        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $result |= $a[$i] ^ $b[$i];
        }

        return $result === "\x00";
    }
}

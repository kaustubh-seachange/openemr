<?php

/**
 * Authorization Server Member
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2020 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use DateInterval;
use Exception;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use OpenEMR\Common\Auth\AuthUtils;
use OpenEMR\Common\Auth\OpenIDConnect\Entities\ClientEntity;
use OpenEMR\Common\Auth\OpenIDConnect\Entities\ScopeEntity;
use OpenEMR\Common\Auth\OpenIDConnect\Entities\UserEntity;
use OpenEMR\Common\Auth\OpenIDConnect\Repositories\AccessTokenRepository;
use OpenEMR\Common\Auth\OpenIDConnect\Repositories\AuthCodeRepository;
use OpenEMR\Common\Auth\OpenIDConnect\Repositories\ClientRepository;
use OpenEMR\Common\Auth\OpenIDConnect\Repositories\IdentityRepository;
use OpenEMR\Common\Auth\OpenIDConnect\Repositories\RefreshTokenRepository;
use OpenEMR\Common\Auth\OpenIDConnect\Repositories\ScopeRepository;
use OpenEMR\Common\Auth\OpenIDConnect\Repositories\UserRepository;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenIDConnectServer\ClaimExtractor;
use OpenIDConnectServer\Entities\ClaimSetEntity;
use OpenIDConnectServer\IdTokenResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

class AuthorizationController
{
    public $siteId;
    public $serverBaseUrl;
    public $authBaseUrl;
    private $privateKey;
    private $encryptionKey;
    private $grantType;
    private $providerForm;
    private $authRequestSerial;

    public function __construct($providerForm = true)
    {
        global $gbl;
        $this->siteId = $gbl::$SITE;
        $this->serverBaseUrl = $gbl::$REST_BASE_URL;
        $this->authBaseUrl = $gbl::$REST_FULLAUTH_URL;
        $this->authRequestSerial = $_SESSION['authRequestSerial'] ?? '';
        $this->encryptionKey = 'lxZFUEsBCJ2Yb14IF2ygAHI5N4+ZAUXXaSeeJm6+twsUmIen';
        $this->privateKey = $gbl::$privateKey;
        $this->providerForm = $providerForm;
    }

    public function clientRegistration(): void
    {
        $response = $this->createServerResponse();
        $request = $this->createServerRequest();
        $headers = array();
        try {
            $request_headers = $request->getHeaders();
            foreach ($request_headers as $header => $value) {
                $headers[strtolower($header)] = $value[0];
            }
            if (!$headers['content-type'] || strpos($headers['content-type'], 'application/json') !== 0) {
                throw new OAuthServerException('Unexpected content type', 0, 'invalid_client_metadata');
            }
            $json = file_get_contents('php://input');
            if (!$json) {
                throw new OAuthServerException('No JSON body', 0, 'invalid_client_metadata');
            }
            $data = json_decode($json, true);
            if (!$data) {
                throw new OAuthServerException('Invalid JSON', 0, 'invalid_client_metadata');
            }
            // many of these are optional and are here if we want to implement
            $keys = array('contacts' => null,
                'application_type' => null,
                'client_name' => null,
                'logo_uri' => null,
                'redirect_uris' => null,
                'post_logout_redirect_uris' => null,
                'token_endpoint_auth_method' => array('client_secret_basic', 'client_secret_post'),
                'policy_uri' => null,
                'tos_uri' => null,
                'jwks_uri' => null,
                'jwks' => null,
                'sector_identifier_uri' => null,
                'subject_type' => array('pairwise', 'public'),
                'default_max_age' => null,
                'require_auth_time' => null,
                'default_acr_values' => null,
                'initiate_login_uri' => null,
                'request_uris' => null,
                'response_types' => null,
                'grant_types' => null,
            );
            $client_id = $this->base64url_encode(\random_bytes(32));
            $reg_token = $this->base64url_encode(\random_bytes(32));
            $reg_client_uri_path = $this->base64url_encode(\random_bytes(16));
            $params = array(
                'client_id' => $client_id,
                'client_id_issued_at' => time(),
                'registration_access_token' => $reg_token,
                'registration_client_uri_path' => $reg_client_uri_path
            );
            // only include secret if a confidential app else force PKCE for native and web apps.
            $client_secret = '';
            if ($data['application_type'] === 'private') {
                $client_secret = $this->base64url_encode(\random_bytes(64));
                $params['client_secret'] = $client_secret;
            }
            foreach ($keys as $key => $supported_values) {
                if (isset($data[$key])) {
                    if (in_array($key, array('contacts', 'redirect_uris', 'request_uris', 'post_logout_redirect_uris', 'grant_types', 'response_types', 'default_acr_values'))) {
                        $params[$key] = implode('|', $data[$key]);
                    } elseif ($key === 'jwks') {
                        $params[$key] = json_encode($data[$key]);
                    } else {
                        $params[$key] = $data[$key];
                    }
                    if (!empty($supported_values)) {
                        if (!in_array($params[$key], $supported_values)) {
                            throw new OAuthServerException("Unsupported $key value : $params[$key]", 0, 'invalid_client_metadata');
                        }
                    }
                }
            }
            if (!isset($data['redirect_uris'])) {
                throw new OAuthServerException('redirect_uris is invalid', 0, 'invalid_redirect_uri');
            }
            if (isset($data['post_logout_redirect_uris']) && !isset($data['post_logout_redirect_uris'])) {
                throw new OAuthServerException('post_logout_redirect_uris is invalid', 0, 'invalid_client_metadata');
            }
            // save to oauth client table
            $badSave = $this->newClientSave($client_id, $params);
            if ($badSave) {
                throw OAuthServerException::serverError("Try again. Unable to create account");
            }
            $reg_uri = $this->authBaseUrl . '/client/' . $reg_client_uri_path;
            unset($params['registration_client_uri_path']);
            $client_json = array(
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'registration_access_token' => $reg_token,
                'registration_client_uri' => $reg_uri,
                'client_id_issued_at' => time(),
                'client_secret_expires_at' => 0
            );
            $array_params = array('contacts', 'redirect_uris', 'request_uris', 'post_logout_redirect_uris', 'response_types', 'grant_types', 'default_acr_values');
            foreach ($array_params as $aparam) {
                if (isset($params[$aparam])) {
                    $params[$aparam] = explode('|', $params[$aparam]);
                }
            }
            if (!empty($params['jwks'])) {
                $params['jwks'] = json_decode($params['jwks'], true);
            }
            if (isset($params['require_auth_time'])) {
                $params['require_auth_time'] = ($params['require_auth_time'] === 1);
            }
            // send response
            $response->withHeader("Cache-Control", "no-store");
            $response->withHeader("Pragma", "no-cache");
            $response->withHeader('Content-Type', 'application/json');
            $body = $response->getBody();
            $body->write(json_encode(array_merge($client_json, $params)));

            $this->emitResponse($response->withStatus(200)->withBody($body));
        } catch (OAuthServerException $exception) {
            $this->emitResponse($exception->generateHttpResponse($response));
        }
    }

    private function createServerResponse(): ResponseInterface
    {
        return (new Psr17Factory())->createResponse();
    }

    private function createServerRequest(): ServerRequestInterface
    {
        $psr17Factory = new Psr17Factory();

        return (new ServerRequestCreator(
            $psr17Factory, // ServerRequestFactory
            $psr17Factory, // UriFactory
            $psr17Factory, // UploadedFileFactory
            $psr17Factory  // StreamFactory
        ))->fromGlobals();
    }

    public function base64url_encode($data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function newClientSave($clientId, $info): bool
    {
        $user = 0;
        $site = $this->siteId;
        $private = empty($info['client_secret']) ? 0 : 1;
        $contacts = $info['contacts'];
        $redirects = $info['redirect_uris'];
        try {
            $sql = "INSERT INTO `oauth_clients` (`client_id`, `client_role`, `client_name`, `client_secret`, `registration_token`, `registration_uri_path`, `register_date`, `revoke_date`, `contacts`, `redirect_uri`, `grant_types`, `scope`, `user_id`, `site_id`, `is_confidential`) VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL, ?, ?, 'authorization_code', 'openid email phone address api:oemr api:fhir api:port api:pofh', ?, ?, ?)";
            $i_vals = array(
                $clientId, 'users', $info['client_name'], $info['client_secret'], $info['registration_access_token'], $info['registration_client_uri_path'], $contacts, $redirects, $user, $site, $private
            );

            return sqlQuery($sql, $i_vals);
        } catch (\RuntimeException $e) {
            die($e);
        }
    }

    public function emitResponse($response): void
    {
        if (headers_sent()) {
            throw new RuntimeException('Headers already sent.');
        }
        $statusLine = sprintf(
            'HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );
        header($statusLine, true);
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeader = sprintf('%s: %s', $name, $response->getHeaderLine($name));
            header($responseHeader, false);
        }
        // send it along.
        echo $response->getBody();
    }

    public function clientRegisteredDetails(): void
    {
        $response = $this->createServerResponse();

        try {
            $token = $_REQUEST['access_token'];
            if (!$token) {
                $token = $this->getBearerToken();
                if (!$token) {
                    throw new OAuthServerException('invalid_request', 0, 'No Access Code', 403);
                }
            }
            $pos = strpos($_SERVER['PATH_INFO'], '/client/');
            if ($pos === false) {
                throw new OAuthServerException('invailid_request', 0, 'Invalid path', 403);
            }
            $uri_path = substr($_SERVER['PATH_INFO'], $pos + 8);
            $client = sqlQuery("SELECT * FROM `oauth_clients` WHERE `registration_uri_path` = ?", array($uri_path));
            if (!$client) {
                throw new OAuthServerException('invalid_request', 0, 'Invalid client', 403);
            }
            if ($client['registration_access_token'] !== $token) {
                throw new OAuthServerException('invalid _request', 0, 'Invalid registration token', 403);
            }
            $params['client_id'] = $client['client_id'];
            $params['client_secret'] = $client['client_secret'];
            $params['contacts'] = explode('|', $client['contacts']);
            $params['application_type'] = $client['client_role'];
            $params['client_name'] = $client['client_name'];
            $params['redirect_uris'] = explode('|', $client['redirect_uri']);

            $response->withHeader("Cache-Control", "no-store");
            $response->withHeader("Pragma", "no-cache");
            $response->withHeader('Content-Type', 'application/json');
            $body = $response->getBody();
            $body->write(json_encode($params));

            $this->emitResponse($response->withStatus(200)->withBody($body));
        } catch (OAuthServerException $exception) {
            $this->emitResponse($exception->generateHttpResponse($response));
        }
    }

    public function getBearerToken()
    {
        $request = $this->createServerRequest();
        $request_headers = $request->getHeaders();
        $headers = [];
        foreach ($request_headers as $header => $value) {
            $headers[strtolower($header)] = $value[0];
        }
        $authorization = $headers['authorization'];
        if ($authorization) {
            $pieces = explode(' ', $authorization);
            if (strcasecmp($pieces[0], 'bearer') !== 0) {
                return null;
            }

            return rtrim($pieces[1]);
        }
        return null;
    }

    public function oauthAuthorizationFlow(): void
    {
        $response = $this->createServerResponse();
        $request = $this->createServerRequest();

        if ($nonce = $request->getQueryParams()['nonce']) {
            $_SESSION['nonce'] = $request->getQueryParams()['nonce'];
        }

        $this->grantType = 'authorization_code';
        $server = $this->getAuthorizationServer();
        try {
            // Validate the HTTP request and return an AuthorizationRequest object.
            $authRequest = $server->validateAuthorizationRequest($request);
            $_SESSION['csrf'] = $authRequest->getState();
            $_SESSION['scopes'] = $request->getQueryParams()['scope'];
            $_SESSION['client_id'] = $request->getQueryParams()['client_id'];
            // If needed, serialize into a users session
            if ($this->providerForm) {
                $this->serializeUserSession($authRequest);
                // call our login then login calls authorize if approved by user
                header("Location: " . $this->authBaseUrl . "/provider/login", true, 301);
                exit;
            }
        } catch (OAuthServerException $exception) {
            $this->emitResponse($exception->generateHttpResponse($response));
        } catch (Exception $exception) {
            $body = $response->getBody();
            $body->write($exception->getMessage());
            $this->emitResponse($response->withStatus(500)->withBody($body));
        }
    }

    public function getAuthorizationServer(): AuthorizationServer
    {
        $customClaim = new ClaimSetEntity('', ['']);
        if ($_SESSION['nonce']) {
            // nonce scope added later. this is for id token nonce claim.
            $customClaim = new ClaimSetEntity('nonce', ['nonce']);
        }
        // OpenID Connect Response Type
        $responseType = new IdTokenResponse(new IdentityRepository(), new ClaimExtractor([$customClaim]));
        $authServer = new AuthorizationServer(
            new ClientRepository(),
            new AccessTokenRepository(),
            new ScopeRepository(),
            $this->privateKey,
            $this->encryptionKey,
            $responseType
        );
        if (empty($this->grantType)) {
            $this->grantType = 'authorization_code';
        }
        if ($this->grantType === 'authorization_code') {
            $grant = new AuthCodeGrant(
                new AuthCodeRepository(),
                new RefreshTokenRepository(),
                new \DateInterval('PT1M') // auth token. should be short turn around.
            );
            $grant->setRefreshTokenTTL(new \DateInterval('P3M')); // minimum per ONC
            $authServer->enableGrantType(
                $grant,
                new \DateInterval('PT1H') // access token
            );
        }
        if ($this->grantType === 'refresh_token') {
            $grant = new RefreshTokenGrant(new refreshTokenRepository());
            $grant->setRefreshTokenTTL(new \DateInterval('P3M'));
            $authServer->enableGrantType(
                $grant,
                new \DateInterval('PT1H') // The new access token will expire after 1 hour
            );
        }
        if ($this->grantType === 'password') {
            $grant = new PasswordGrant(
                new UserRepository(),
                new RefreshTokenRepository()
            );
            $grant->setRefreshTokenTTL(new DateInterval('P3M'));
            $authServer->enableGrantType(
                $grant,
                new \DateInterval('PT1H') // access token
            );
        }

        return $authServer;
    }

    private function serializeUserSession($authRequest): void
    {
        // keeping somewhat granular
        try {
            $scopes = $authRequest->getScopes();
            $scoped = [];
            foreach ($scopes as $scope) {
                $scoped[] = $scope->getIdentifier();
            }
            $client['name'] = $authRequest->getClient()->getName();
            $client['redirectUri'] = $authRequest->getClient()->getRedirectUri();
            $client['identifier'] = $authRequest->getClient()->getIdentifier();
            $client['isConfidential'] = $authRequest->getClient()->isConfidential();
            $outer = array(
                'grantTypeId' => $authRequest->getGrantTypeId(),
                'authorizationApproved' => false,
                'redirectUri' => $authRequest->getRedirectUri(),
                'state' => $authRequest->getState(),
                'codeChallenge' => $authRequest->getCodeChallenge(),
                'codeChallengeMethod' => $authRequest->getCodeChallengeMethod(),
            );
            $result = array('outer' => $outer, 'scopes' => $scoped, 'client' => $client);
            $this->authRequestSerial = json_encode($result, JSON_THROW_ON_ERROR);
            $_SESSION['authRequestSerial'] = $this->authRequestSerial;
        } catch (Exception $e) {
            echo $e;
        }
    }

    public function userLogin(): void
    {
        $response = $this->createServerResponse();

        if (empty($_POST['username']) && empty($_POST['password'])) {
            $_SESSION['get_credentials'] = 1;
            require_once(__DIR__ . "/../../oauth2/provider/login.php");
            exit();
        }
        unset($_SESSION['get_credentials']);
        $continueLogin = false;
        if (isset($_POST['user_role'])) {
            $continueLogin = $this->verifyLogin($_POST['username'], $_POST['password'], $_POST['email'], $_POST['user_role']);
        }
        unset($_POST['username'], $_POST['password']);
        if (!$continueLogin) {
            $invalid = "Sorry, Invalid!"; // todo: display error
            $_SESSION['get_credentials'] = 1;
            require_once(__DIR__ . "/../../oauth2/provider/login.php");
            exit();
        }
        $_SESSION['persist_login'] = isset($_POST['persist_login']) ? 1 : 0;
        $user = new UserEntity();
        $user->setIdentifier($_SESSION['user_id']);
        $user->setUserRole($_SESSION['user_role']);
        $_SESSION['claims'] = $user->getClaims();
        $_SESSION['get_authorization'] = 1;
        require_once(__DIR__ . "/../../oauth2/provider/login.php");
    }

    private function verifyLogin($username, $password, $email = '', $type = 'api'): bool
    {
        $auth = new AuthUtils($type);
        $is_true = $auth->confirmPassword($username, $password, $email);
        if (!$is_true) {
            return false;
        }
        if ($id = $auth->getUserId()) {
            $_SESSION['user_id'] = $this->getUserUuid($id, 'users');
            $_SESSION['user_role'] = 'users';
            return true;
        }
        if ($id = $auth->getPatientId()) {
            $_SESSION['user_id'] = $this->getUserUuid($id, 'patient');
            $_SESSION['user_role'] = 'patient';
            return true;
        }

        return false;
    }

    protected function getUserUuid($userId, $userRole): string
    {
        switch ($userRole) {
            case 'users':
                (new UuidRegistry(['table_name' => 'users']))->createMissingUuids();
                $account_sql = "SELECT `uuid` FROM `users` WHERE `id` = ?";
                break;
            case 'patient':
                (new UuidRegistry(['table_name' => 'patient_data']))->createMissingUuids();
                $account_sql = "SELECT `uuid` FROM `patient_data` WHERE `pid` = ?";
                break;
            default:
                return '';
        }
        $id = sqlQueryNoLog($account_sql, array($userId))['uuid'];

        $uuidRegistry = new UuidRegistry();
        return $uuidRegistry::uuidToString($id);
    }

    public function authorizeUser(): void
    {
        $response = $this->createServerResponse();
        $authRequest = $this->deserializeUserSession();

        if (isset($_POST['proceed'])) {
            $this->saveTrustedUser($_SESSION['client_id'], $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['scopes'], $_SESSION['persist_login']);
        }
        $server = $this->getAuthorizationServer();
        $user = new UserEntity();
        $user->setIdentifier($_SESSION['user_id']);
        $user->setUserRole($_SESSION['user_role']);
        $authRequest->setUser($user);
        $authRequest->setAuthorizationApproved(true);
        // Return the HTTP redirect response
        $result = $server->completeAuthorizationRequest($authRequest, $response);
        $this->emitResponse($result);
    }

    private function deserializeUserSession(): AuthorizationRequest
    {
        $authRequest = new AuthorizationRequest();
        try {
            $requestData = $_SESSION['authRequestSerial'] ?? $this->authRequestSerial;
            $restore = json_decode($requestData, true, 512);
            $outer = $restore['outer'];
            $client = $restore['client'];
            $scoped = $restore['scopes'];
            $authRequest->setGrantTypeId($outer['grantTypeId']);
            $e = new ClientEntity();
            $e->setName($client['name']);
            $e->setRedirectUri($client['redirectUri']);
            $e->setIdentifier($client['identifier']);
            $e->setIsConfidential($client['isConfidential']);
            $authRequest->setClient($e);
            $scopes = [];
            foreach ($scoped as $scope) {
                $s = new ScopeEntity();
                $s->setIdentifier($scope);
                $scopes[] = $s;
            }
            $authRequest->setScopes($scopes);
            $authRequest->setAuthorizationApproved($outer['authorizationApproved']);
            $authRequest->setRedirectUri($outer['redirectUri']);
            $authRequest->setState($outer['state']);
            $authRequest->setCodeChallenge($outer['codeChallenge']);
            $authRequest->setCodeChallengeMethod($outer['codeChallengeMethod']);
        } catch (Exception $e) {
            echo $e;
        }

        return $authRequest;
    }

    public function oauthAuthorizeToken(): void
    {
        $response = $this->createServerResponse();
        $request = $this->createServerRequest();
        $this->grantType = $request->getParsedBody()['grant_type'];

        $server = $this->getAuthorizationServer();
        try {
            $result = $server->respondToAccessTokenRequest($request, $response);
            $this->emitResponse($result);
            \session_id('authserver');
            \session_destroy();
        } catch (OAuthServerException $exception) {
            \session_id('authserver');
            \session_destroy();
            $this->emitResponse($exception->generateHttpResponse($response));
        } catch (Exception $exception) {
            \session_id('authserver');
            \session_destroy();
            $body = $response->getBody();
            $body->write($exception->getMessage());
            $this->emitResponse($response->withStatus(500)->withBody($body));
        }
    }

    public function oauthPasswordFlow(): void
    {
        $response = $this->createServerResponse();
        $request = $this->createServerRequest();

        $this->grantType = 'password';
        $server = $this->getAuthorizationServer();

        try {
            // Respond to the access token request
            $result = $server->respondToAccessTokenRequest($request, $response);
            $this->emitResponse($result);
            \session_id('authserver');
            \session_destroy();
        } catch (OAuthServerException $exception) {
            \session_id('authserver');
            \session_destroy();
            $this->emitResponse($exception->generateHttpResponse($response));
        } catch (Exception $exception) {
            \session_id('authserver');
            \session_destroy();
            $body = $response->getBody();
            $body->write($exception->getMessage());
            $this->emitResponse($response->withStatus(500)->withBody($body));
        }
    }

    public function trustedUser($clientId, $userId, $userRole)
    {
        return sqlQueryNoLog("SELECT `id` FROM `oauth_trusted_user` WHERE `client_id`= ? AND `user_id`= ? AND `user_role`= ?", array($clientId, $userId, $userRole));
    }

    public function saveTrustedUser($clientId, $userId, $userRole, $scope, $persist)
    {
        $id = $this->trustedUser($clientId, $userId, $userRole)['id'];
        $sql = "REPLACE INTO `oauth_trusted_user` (`id`, `user_id`, `user_role`, `client_id`, `scope`, `persist_login`, `time`) VALUES (?, ?, ?, ?, ?, ?, Now())";

        return sqlQuery($sql, array($id, $userId, $userRole, $clientId, $scope, $persist));
    }
}

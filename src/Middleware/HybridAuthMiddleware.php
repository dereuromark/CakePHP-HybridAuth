<?php
declare(strict_types=1);

/**
 * ADmad\HybridAuth plugin.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 */

namespace ADmad\HybridAuth\Middleware;

use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Event\EventManager;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Routing\Router;
use Cake\Utility\Hash;
use Hybridauth\Hybridauth;
use Hybridauth\User\Profile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

class HybridAuthMiddleware implements MiddlewareInterface, EventDispatcherInterface
{
    use EventDispatcherTrait;
    use InstanceConfigTrait;
    use LocatorAwareTrait;

    /**
     * The query string key used for remembering the referred page when
     * getting redirected to login.
     */
    public const QUERY_STRING_REDIRECT = 'redirect';

    /**
     * The name of the event that is fired after user identification.
     */
    public const EVENT_AFTER_IDENTIFY = 'HybridAuth.afterIdentify';

    /**
     * Default config.
     *
     * ### Options
     *
     * - `requestMethod`: Request method type. Default "POST".
     * - `loginUrl`: Login page URL. In case of auth failure user is redirected
     *   to this login page with "error" query string var.
     * - `userEntity`: Whether to return entity or array for user. Default `false`.
     * - `userModel`: User model name. Default "Users".
     * - `profileModel`: Social profile model. Default "ADmad/HybridAuth.SocialProfiles".
     * - `finder`: Table finder. Default "all".
     * - `fields`: Specify password field for removal in returned user identity.
     *   Default `['password' => 'password']`.
     * - `sessionKey`: Session key to write user record to. Default "Auth.User".
     * - `getUserCallback`: The callback method which will be called on user
     *   model for getting user record matching social profile. Defaults "getUser".
     * - `serviceConfig`: SocialConnect/Auth service providers config.
     * - `logErrors`: Whether social connect errors should be logged. Default `true`.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'requestMethod' => 'POST',
        'loginUrl' => '/users/login',
        'loginRedirect' => '/',
        'userEntity' => false,
        'userModel' => 'Users',
        'profileModel' => 'ADmad/HybridAuth.SocialProfiles',
        'finder' => 'all',
        'fields' => [
            'password' => 'password',
        ],
        'sessionKey' => 'Auth',
        'getUserCallback' => 'getUser',
        'serviceConfig' => [],
        'logErrors' => true,
    ];

    /**
     * @var \Hybridauth\Hybridauth
     */
    protected $_auth;

    /**
     * User model instance.
     *
     * @var \Cake\ORM\Table
     */
    protected $_userModel;

    /**
     * Profile model instance.
     *
     * @var \Cake\ORM\Table
     */
    protected $_profileModel;

    /**
     * Error.
     *
     * @var string
     */
    protected $_error;

    /**
     * Constructor.
     *
     * @param array $config Configuration.
     * @param \Cake\Event\EventManager|null $eventManager Event manager instance.
     */
    public function __construct(
        array $config = [],
        ?EventManager $eventManager = null
    ) {
        $this->setConfig($config);

        if ($eventManager !== null) {
            $this->setEventManager($eventManager);
        }

        $this->
    }

    /**
     * Handle authentication.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     *
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $params = $request->getAttribute('params');
        $action = Hash::get($params, 'action');

        if (
            Hash::get($params, 'plugin') !== 'ADmad/HybridAuth'
            || Hash::get($params, 'controller') !== 'Auth'
            || !in_array($action, ['login', 'callback'], true)
        ) {
            return $handler->handle($request);
        }

        $method = '_handle' . ucfirst($action) . 'Action';

        return $this->{$method}($request);
    }

    /**
     * Handle login action, initiate authentication process.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     *
     * @return \Cake\Http\Response A response.
     */
    protected function _handleLoginAction(ServerRequest $request): Response
    {
        $request->allowMethod($this->getConfig('requestMethod'));

        $this->_setupModelInstances();

        $providerName = $request->getParam('provider');

        $profile = $this->_getProfile($providerName, $request);
        dd($profile);
    }

    /**
     * Handle callback action.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     *
     * @return \Cake\Http\Response A response.
     */
    protected function _handleCallbackAction(ServerRequest $request): Response
    {
        $this->_setupModelInstances();

        $config = $this->getConfig();
        $providerName = $request->getParam('provider');
        $response = new Response();

        $profile = $this->_getProfile($providerName, $request);
        if (!$profile) {
            return $response->withLocation(
                Router::url($config['loginUrl'], true) . '?error=' . $this->_error
            );
        }

        $user = $this->_getUser($profile, $request->getSession());
        if (!$user) {
            return $response->withLocation(
                Router::url($config['loginUrl'], true) . '?error=' . $this->_error
            );
        }

        $user->unset($config['fields']['password']);

        if (!$config['userEntity']) {
            $user = $user->toArray();
        }

        $event = $this->dispatchEvent(self::EVENT_AFTER_IDENTIFY, ['user' => $user]);
        $result = $event->getResult();
        if ($result !== null) {
            $user = $event->getResult();
        }

        $request->getSession()->write($config['sessionKey'], $user);

        return $response->withLocation(
            Router::url($this->_getRedirectUrl($request), true)
        );
    }

    /**
     * Setup model instances.
     *
     * @return void
     */
    protected function _setupModelInstances(): void
    {
        $this->_profileModel = $this->getTableLocator()->get($this->getConfig('profileModel'));
        $this->_profileModel->belongsTo($this->getConfig('userModel'));

        $this->_userModel = $this->getTableLocator()->get($this->getConfig('userModel'));
    }

    /**
     * Get social profile record.
     *
     * @param string $providerName Provider name.
     * @param \Cake\Http\ServerRequest $request Request instance.
     *
     * @return \Cake\Datasource\EntityInterface|null
     */
    protected function _getProfile(string $providerName, ServerRequest $request): ?EntityInterface
    {
        $hybridConfig = $this->_buildConfig($request);

        try {
            $this->_auth = new Hybridauth($hybridConfig);
            $adapter = $this->_auth->authenticate($providerName);

            $identity = $adapter->getUserProfile();

        } catch (\Exception $e) {
            if ($e->getCode() < 5) {
                throw new \RuntimeException($e->getMessage());
            } else {
                //\Hybridauth\Hybridauth::initialize($hybridConfig);
            }
        }
        /*
        try {
            $provider = $this->_getService($request)->getProvider($providerName);
            $accessToken = $provider->getAccessTokenByRequestParameters($request->getQueryParams());
            $identity = $provider->getIdentity($accessToken);
        } catch (SocialConnectException $e) {
            $this->_error = 'provider_failure';

            if ($this->getConfig('logErrors')) {
                Log::error($this->_getLogMessage($request, $e));
            }

            return null;
        }
       */

        /** @var \ADmad\HybridAuth\Model\Entity\SocialProfile|null $profile */
        $profile = $this->_profileModel->find()
            ->where([
                $this->_profileModel->aliasField('provider') => $providerName,
                $this->_profileModel->aliasField('identifier') => $identity->identifier,
            ])
            ->first();

        return $this->_patchProfile(
            $providerName,
            $identity,
            $profile
        );
    }

    /**
     * Append the "redirect" query string param to URL.
     *
     * @param string|array $url URL
     * @param string $redirectQueryString Redirect query string
     * @return string URL
     */
    protected function _appendRedirectQueryString($url, $redirectQueryString)
    {
        if (!$redirectQueryString) {
            return $url;
        }

        if (is_array($url)) {
            $url['?'][static::QUERY_STRING_REDIRECT] = $redirectQueryString;
        } else {
            $char = strpos($url, '?') === false ? '?' : '&';
            $url .= $char . static::QUERY_STRING_REDIRECT . '=' . urlencode($redirectQueryString);
        }

        return $url;
    }

    /**
     * Get user record.
     *
     * @param \Cake\Datasource\EntityInterface $profile Social profile entity
     * @param \Cake\Http\Session $session Session instance.
     *
     * @return \Cake\Datasource\EntityInterface|null User array or entity
     *   on success, null on failure.
     */
    protected function _getUser(EntityInterface $profile, $session): ?EntityInterface
    {
        $user = null;

        if ($profile->get('user_id')) {
            /** @var string $userPkField */
            $userPkField = $this->_userModel->getPrimaryKey();
            $userPkField = $this->_userModel->aliasField($userPkField);

            /** @var \Cake\Datasource\EntityInterface $user */
            $user = $this->_userModel->find()
                ->where([
                    $userPkField => $profile->get('user_id'),
                ])
                ->find($this->getConfig('finder'))
                ->first();
        }

        if (!$user) {
            if ($profile->get('user_id')) {
                $this->_error = 'finder_failure';

                return null;
            }

            $user = $this->_getUserEntity($profile, $session);
            $profile->set('user_id', $user->id);
        }

        if ($profile->isDirty()) {
            $this->_saveProfile($profile);
        }

        $user->set('social_profile', $profile);
        $user->unset($this->getConfig('fields.password'));

        return $user;
    }

    /**
     * Get social profile entity.
     *
     * @param string $providerName Provider name.
     * @param \Hybridauth\User\Profile $identity Social connect entity.
     * @param \Cake\Datasource\EntityInterface $profile Social profile entity
     *
     * @return \Cake\Datasource\EntityInterface
     */
    protected function _patchProfile(
        $providerName,
        Profile $identity,
        ?EntityInterface $profile = null
    ): EntityInterface {
        if ($profile === null) {
            $profile = $this->_profileModel->newEntity([
                'provider' => $providerName,
            ]);
        }

        $data = [
        ];

        foreach (get_object_vars($identity) as $key => $value) {
            switch ($key) {
                case 'id':
                    $data['identifier'] = $value;
                    break;
                case 'lastname':
                    $data['last_name'] = $value;
                    break;
                case 'firstname':
                    $data['first_name'] = $value;
                    break;
                case 'birthday':
                    $data['birth_date'] = $value;
                    break;
                case 'emailVerified':
                    $data['email_verified'] = $value;
                    break;
                case 'fullname':
                    $data['full_name'] = $value;
                    break;
                case 'sex':
                    $data['gender'] = $value;
                    break;
                case 'pictureURL':
                    $data['picture_url'] = $value;
                    break;
                default:
                    $data[$key] = $value;
                    break;
            }
        }

        return $this->_profileModel->patchEntity($profile, $data);
    }

    /**
     * Get new user entity.
     *
     * The method specified in "getUserCallback" will be called on the User model
     * with profile entity. The method should return a persisted user entity.
     *
     * @param \Cake\Datasource\EntityInterface $profile Social profile entity.
     * @param \Cake\Http\Session $session Session instance.
     *
     * @return \Cake\Datasource\EntityInterface User entity.
     */
    protected function _getUserEntity(EntityInterface $profile, $session): EntityInterface
    {
        $callbackMethod = $this->getConfig('getUserCallback');

        $user = call_user_func([$this->_userModel, $callbackMethod], $profile, $session);

        if (!($user instanceof EntityInterface)) {
            throw new RuntimeException('"getUserCallback" method must return a user entity.');
        }

        return $user;
    }

    /**
     * Save social profile entity.
     *
     * @param \Cake\Datasource\EntityInterface $profile Social profile entity.
     *
     * @throws \RuntimeException Thrown when unable to save social profile.
     *
     * @return void
     */
    protected function _saveProfile(EntityInterface $profile): void
    {
        if (!$this->_profileModel->save($profile)) {
            throw new RuntimeException('Unable to save social profile.');
        }
    }

    /**
     * Save URL to redirect to after authentication to session.
     *
     * @param \Cake\Http\ServerRequest $request Request instance.
     *
     * @return void
     */
    protected function _setRedirectUrl(ServerRequest $request): void
    {
        $request->getSession()->delete('HybridAuth.redirectUrl');

        /** @var string $redirectUrl */
        $redirectUrl = $request->getQuery(static::QUERY_STRING_REDIRECT);
        if (
            empty($redirectUrl)
            || substr($redirectUrl, 0, 1) !== '/'
            || substr($redirectUrl, 0, 2) === '//'
        ) {
            return;
        }

        $request->getSession()->write('HybridAuth.redirectUrl', $redirectUrl);
    }

    /**
     * Get URL to redirect to after authentication.
     *
     * @param \Cake\Http\ServerRequest $request Request instance.
     *
     * @return string|array
     */
    protected function _getRedirectUrl(ServerRequest $request)
    {
        $redirectUrl = $request->getSession()->read('HybridAuth.redirectUrl');
        if ($redirectUrl) {
            $request->getSession()->delete('HybridAuth.redirectUrl');

            return $redirectUrl;
        }

        return $this->getConfig('loginRedirect');
    }

    /**
     * Generate the error log message.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The current request.
     * @param \Exception $exception The exception to log a message for.
     *
     * @return string Error message
     */
    protected function _getLogMessage($request, $exception): string
    {
        $message = sprintf(
            '[%s] %s',
            get_class($exception),
            $exception->getMessage()
        );

        $message .= "\nRequest URL: " . $request->getRequestTarget();

        $referer = $request->getHeaderLine('Referer');
        if ($referer) {
            $message .= "\nReferer URL: " . $referer;
        }

        if ($exception instanceof InvalidResponse && $exception->getResponse()) {
            $message .= "\nProvider Response: " . $exception->getResponse()->getBody();
        }

        $message .= "\nStack Trace:\n" . $exception->getTraceAsString() . "\n\n";

        return $message;
    }

    /**
     * @param \Cake\Http\ServerRequest $request
     *
     * @return array
     */
    protected function _buildConfig(ServerRequest $request): array
    {
        Configure::read('HybridAuth');

        if (empty($hybridConfig['base_url'])) {
            $hybridConfig['base_url'] = [
                'plugin' => 'ADmad/HybridAuth',
                'controller' => 'HybridAuth',
                'action' => 'endpoint',
                'prefix' => false
            ];
        }

        $hybridConfig['base_url'] = $this->_appendRedirectQueryString(
            $hybridConfig['base_url'],
            $request->getQuery(static::QUERY_STRING_REDIRECT)
        );

        $hybridConfig['base_url'] = Router::url($hybridConfig['base_url'], true);

        return $hybridConfig;
    }
}

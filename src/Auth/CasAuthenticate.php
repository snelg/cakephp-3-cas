<?php
/**
 * Copyright 2015 Glen Sawyer

 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @copyright 2015 Glen Sawyer
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 */
namespace CasAuth\Auth;

use Cake\Auth\BaseAuthenticate;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Component\AuthComponent;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventDispatcherTrait;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use phpCAS;

class CasAuthenticate extends BaseAuthenticate
{
    use EventDispatcherTrait;

    protected $_defaultConfig = [
        'hostname' => null,
        'port' => 443,
        'uri' => ''
    ];

    /**
     * {@inheritDoc}
     */
    public function __construct(ComponentRegistry $registry, array $config = [])
    {
        //Configuration params can be set via global Configure::write or via Auth->config
        //Auth->config params override global Configure, so we'll pass them in last
        parent::__construct($registry, (array)Configure::read('CAS'));
        $this->setConfig($config);

        //Get the merged config settings
        $settings = $this->getConfig();

        if (!empty($settings['debug'])) {
            phpCAS::setDebug(LOGS . 'phpCas.log');
        }

        //The "isInitialized" check isn't necessary during normal use,
        //but during *testing* if Authentication is tested more than once, then
        //the fact that phpCAS uses a static global initialization can
        //cause problems
        if (!phpCAS::isInitialized()) {
            phpCAS::client(CAS_VERSION_2_0, $settings['hostname'], $settings['port'], $settings['uri']);
        }

        if (empty($settings['cert_path'])) {
            phpCAS::setNoCasServerValidation();
        } else {
            phpCAS::setCasServerCACert($settings['cert_path']);
        }

        if (!empty($registry)) {
            $controller = $registry->getController();
            if (!empty($controller)) {
                $this->eventManager($controller->eventManager());
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(ServerRequest $request, Response $response)
    {
        phpCAS::handleLogoutRequests(false);
        phpCAS::forceAuthentication();
        //If we get here, then phpCAS::forceAuthentication returned
        //successfully and we are thus authenticated

        $user = array_merge(['username' => phpCAS::getUser()], phpCAS::getAttributes());

        //Listen for this event if you need to add/modify CAS user attributes
        $event = $this->dispatchEvent('CasAuth.authenticate', $user);
        if (!empty($event->result)) {
            $user = $event->result;
        }

        return $user;
    }

    /**
     * {@inheritDoc}
     */
    public function getUser(ServerRequest $request)
    {
        if (empty($this->_registry)) {
            return false;
        }
        $controller = $this->_registry->getController();
        if (empty($controller->Auth)) {
            return false;
        }

        //Since CAS authenticates externally (i.e. via browser redirect),
        //we directly trigger Auth->identify() here
        //This will eventually call back in to $this->authenticate above
        $user = $controller->Auth->identify();

        $controller->Auth->setUser($user);

        return $user;
    }

    /**
     * Log a user out. Interrupts initial call to AuthComponent logout
     * to handle CAS logout, which happens on separate CAS server
     *
     * @param Event $event Auth.logout event
     *
     * @return void
     */
    public function logout(Event $event)
    {
        if (phpCAS::isAuthenticated()) {
            //Step 1. When the client clicks logout, this will run.
            //        phpCAS::logout will redirect the client to the CAS server.
            //        The CAS server will, in turn, redirect the client back to
            //        this same logout URL.
            //
            //        phpCAS will stop script execution after it sends the redirect
            //        header, which is a problem because CakePHP still thinks the
            //        user is logged in. See Step 2.
            $auth = $event->subject();
            if ($auth instanceof AuthComponent) {
                $redirectUrl = $auth->config('logoutRedirect');
            }
            if (empty($redirectUrl)) {
                $redirectUrl = '/';
            }
            phpCAS::logout(['url' => Router::url($redirectUrl, true)]);
        }
        //Step 2. We reach this line when the CAS server has redirected the
        //        client back to us. Do nothing in this block; then after this
        //        method returns, CakePHP will do whatever is necessary to log
        //        the user out from its end (destroying the session or whatever).
    }

    /**
     * {@inheritDoc}
     */
    public function implementedEvents()
    {
        return ['Auth.logout' => 'logout'];
    }
}

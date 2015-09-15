<?php
namespace CasAuth\Auth;

use Cake\Auth\BaseAuthenticate;
use Cake\Event\EventDispatcherTrait;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Controller\ComponentRegistry;
use Cake\Core\Configure;
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

    public function __construct(ComponentRegistry $registry, array $config = [])
    {
        //Configuration params can be set via global Configure::write or via Auth->config
        //Auth->config params override global Configure, so we'll pass them in last
        parent::__construct($registry, (array)Configure::read('CAS'));
        $this->config($config);

        //Get the merged config settings
        $settings = $this->config();

        if(!empty($settings['debug'])){
            phpCAS::setDebug(LOGS . 'phpCas.log');
        }

        phpCAS::client(CAS_VERSION_2_0, $settings['hostname'], $settings['port'], $settings['uri']);

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

    public function authenticate(Request $request, Response $response)
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

    public function getUser(Request $request)
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

    public function logout()
    {
        if(phpCAS::isAuthenticated()){
            //Step 1. When the client clicks logout, this will run.
            //        phpCAS::logout will redirect the client to the CAS server.
            //        The CAS server will, in turn, redirect the client back to
            //        this same logout URL.
            //
            //        phpCAS will stop script execution after it sends the redirect
            //        header, which is a problem because CakePHP still thinks the
            //        user is logged in. See Step 2.
            phpCAS::logout(array('url' => Router::url('/', true)));
        } else {
            //Step 2. This will run when the CAS server has redirected the client
            //        back to us. Do nothing in this block, then after this method
            //        returns CakePHP will do whatever is necessary to log the user
            //        out from its end (destroying the session or whatever).
        }
    }

    public function implementedEvents()
    {
        return ['Auth.logout' => 'logout'];
    }
}
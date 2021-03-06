<?php

namespace Yummy\Controller\Component;

use Cake\Controller\Component;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Core\Configure;

/**
 * This component is a rudimentary ACL system for applying group level access to controllers and methods
 * @todo this may need to operate differently for parsed extensions such as json and xml
 */
class YummyAclComponent extends Component
{

    public $components = ['Flash', 'Auth'];

    public function initialize(array $config)
    {
        parent::initialize($config);

        $this->controller = $this->_registry->getController();
        $this->controllerName = $this->controller->getName() . 'Controller';
        $this->actionName = $this->controller->request->getParam('action');

        if (!$this->getConfig('use_config_file')) {
            $this->getConfig('use_config_file', false);
        }

        return true;
    }

    public function startup()
    {
        // check for required components
        $this->checkComponents();

        // determine if we are using a flat file config
        $this->whichConfig();

        // perform sanity check
        $this->sanityCheck();

        // determine the redirect url
        $this->setRedirect();

        // has action access?
        if ($this->checkActionAccess() == false) {
            return $this->denyAccess();
        }

        return true;
    }

    /**
     * Set allowed groups for a controller
     * @param string|array $config
     * @return bool true on succes
     * @throws InternalErrorException
     */
    public function allow($config)
    {
        if ((is_string($config) && $config != '*') || ( is_array($config) && empty($config) )) {
            throw new InternalErrorException('YummyAcl::allow argument must be either a string value of "*" or an '
            . 'array of groups');
        }

        $this->setConfig('allow', $config);
        return true;
    }

    /**
     * Set ACLs for a controllers actions
     * @param array $config
     * @return bool true on succes
     * @throws InternalErrorException
     */
    public function actions(array $config)
    {
        if (!is_array($config) || empty($config)) {
            throw new InternalErrorException('YummyAcl::actions argument must be an array. Check documentation for '
            . 'array structure');
        }

        $this->setConfig('actions', $config);
        return true;
    }

    /**
     * Ensures component was configured correctly
     * @return void
     * @throws InternalErrorException
     */
    private function sanityCheck()
    {
        $config = $this->_config;

        // if allow is set must be "*" or (array)
        if (isset($config['allow']) && !is_string($config['allow']) && !is_array($config['allow'])) {
            throw new InternalErrorException(__($this->controllerName . ' YummyAcl config "allow" option must be '
                    . '(1) not set, (2) an array of groups, or (3) equal to wildcard (*)'));
        }

        // if actions is set must be (array)
        if (isset($config['actions']) && !is_array($config['actions'])) {
            throw new InternalErrorException(__($this->controllerName . ' YummyAcl config "actions" should be an array '
                    . 'of [action => [groups]]'));
        }
    }

    /**
     * Sets flash message and if redirect is not set throws a 403 exception
     * @return boolean - on false issue deny access
     * @throws ForbiddenException
     */
    private function denyAccess()
    {
        $this->Flash->warn(__('You are not authorized to view this section'), [
            'params' => [
                'title' => 'Access denied'
            ]
        ]);

        $redirect = $this->getConfig('redirect');

        if ($redirect == 403) {
            throw new ForbiddenException();
        }

        return $this->controller->redirect($redirect);
    }

    /**
     * Check if user has access to the requested action
     * @return boolean
     * @throws InternalErrorException
     * @throws ForbiddenException
     */
    private function checkActionAccess()
    {
        $config = $this->getConfig();

        if (!isset($config['actions']) || !isset($config['actions'][$this->actionName])) {
            return $this->checkControllerAccess();
        }

        $actions = $config['actions'][$this->actionName];

        if ($actions == '*' || in_array($config['group'], $actions)) {
            return true;
        }

        return false;
    }

    /**
     * Check if user has access to the requested controller
     * @return boolean|void - passes on true, redirect on false, do nothing on void
     * @throws InternalErrorException
     * @throws ForbiddenException
     */
    private function checkControllerAccess()
    {
        // allow all
        if ($this->getConfig('allow') == '*') {
            return true;
        }

        // allow group
        if (is_array($this->getConfig('allow')) && in_array($this->getConfig('group'), $this->getConfig('allow'))) {
            return true;
        }

        return false;
    }

    /**
     * Throws exception if missing a required component
     * @throws InternalErrorException
     */
    private function checkComponents()
    {
        if (!isset($this->controller->Auth)) {
            throw new InternalErrorException(__('YummyAcl requires the AuthComponent'));
        }

        if (!isset($this->controller->Flash)) {
            throw new InternalErrorException(__('YummyAcl requires the FlashComponent'));
        }
    }

    /**
     * Whether to use the flat file config or not
     * @return boolean
     * @throws InternalErrorException
     */
    private function whichConfig()
    {
        // check if we are using a config file or not, if not then exit
        if ($this->getConfig('use_config_file') !== true) {
            return true;
        }

        // attempt loading config/yummy_acl.php
        $config = Configure::read('YummyAcl');

        $name = $this->controller->getName();

        if (!$config) {
            throw new InternalErrorException(__('YummyAcl config is missing. Please create config/yummy_acl.php'));
        }

        if (!isset($config[$name])) {
            throw new InternalErrorException(__('Controller is missing from config/yummy_acl.php'));
        }

        $this->configShallow($config[$name]);

        return true;
    }

    /**
     * Sets the redirect url or throws an exception if unable to determine redirect url
     * @return boolean
     * @throws InternalErrorException
     */
    private function setRedirect()
    {
        if ($this->getConfig('redirect') != null) {
            return true;
        }

        $authConfig = $this->Auth->getConfig();

        if ($authConfig['unauthorizedRedirect'] == true) {
            $this->setConfig('redirect', $this->getController()->request->referer(true));
            return true;
        }

        if (is_string($authConfig['unauthorizedRedirect'])) {
            $this->setConfig('redirect', $authConfig['unauthorizedRedirect']);
            return true;
        }

        if ($authConfig['unauthorizedRedirect'] == false) {
            $this->setConfig('redirect', 403);
            return true;
        }

        throw new InternalErrorException(__('YummyAcl requires the "redirect" option in config or Auth.loginAction or '
                . 'Auth.unauthorizedRedirect'));
    }
}

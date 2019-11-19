<?php
namespace SimpleSAML\Module\ntnuTopdesk\Auth\Process;

use Webmozart\Assert\Assert;
use SimpleSAML\Logger;

/**
 * Filter to set name in a smart way, based on available name attributes.
 *
 * @author Ricardo Iván Vieitez Parra - NTNU.
 * @package SimpleSAMLphp
 */
class TopdeskUserCreator extends \SimpleSAML\Auth\ProcessingFilter
{

    private $baseUrl;
    private $username;
    private $password;
    private $branchId;

    /**
     * Initialize TopDesk user creator module.
     *
     * Validates and parses the configuration.
     *
     * @param array $config Configuration information.
     * @param mixed $reserved For future use.
     *
     * @throws \SimpleSAML\Error\Exception if the configuration is not valid.
     */
    public function __construct($config, $reserved)
    {
        assert(is_array($config));
        parent::__construct($config, $reserved);

        if (!isset($config['baseURL']) || !is_string($config['baseURL'])) {
            throw new \SimpleSAML\Error\Exception(
                'TopdeskUserCreator: A TopDesk base URL string must be given'
            );
        } else {
            $this->baseUrl = $config['baseURL'];
        }

       if (!isset($config['username']) || !is_string($config['username'])) {
            throw new \SimpleSAML\Error\Exception(
                'TopdeskUserCreator: A TopDesk username string must be given'
            );
       } else {
          $this->username = $config['username'];
       }

        if (!isset($config['password']) || !is_string($config['password'])) {
            throw new \SimpleSAML\Error\Exception(
                'TopdeskUserCreator: A TopDesk password string must be given'
            );
        } else {
            $this->password = $config['password'];
        }

        if (!isset($config['branchId']) || !is_string($config['branchId'])) {
            throw new \SimpleSAML\Error\Exception(
                'TopdeskUserCreator: A TopDesk branch ID string must be given'
            );
        } else {
            $this->branchId = $config['branchId'];
        }
    }

    private function checkUserExists(&$attributes) {
        $ch = curl_init();
        $exists = false;
        $sspLoginName = $attributes['mail'][0];

        if ($ch === FALSE) {
            throw new \SimpleSAML\Error\Exception(
                'TopdeskUserCreator: Unable to initialise cURL session'
            );
        }

        try {
            curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/persons/?ssp_login_name=' . urlencode($sspLoginName));
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
            curl_setopt($ch, CURLOPT_NOBODY, 1);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERNAME, $this->username);
            curl_setopt($ch, CURLOPT_PASSWORD, $this->password);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            if (curl_exec($ch) === FALSE) {
                Logger::error('TopdeskUserCreator: Unable to retrieve user ' . $sspLoginName);
                throw new \SimpleSAML\Error\Exception(
                    'TopdeskUserCreator: Unable to retrieve user'
                );
            } else {
                $rc = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                Logger::debug(
                    'TopdeskUserCreator: received response code ' .
                    ( $rc ?: '(none)' )  .
                    ' for user ' . $sspLoginName
                );
                if ($rc !== 200 && $rc !== 204) {
                    Logger::error(
                        'TopdeskUserCreator: unexpected response code ' .
                        ( $rc ?: '(none)' )  .
                        ' for user ' . $sspLoginName
                    );
                    throw new \SimpleSAML\Error\Exception(
                        'TopdeskUserCreator: Unexpected response code'
                    );
                }
                $exists = ($rc === 200);
            }
        } catch(Exception $e) {
            curl_close($ch);
            throw $e;
        }

        return $exists;
    }

    private function createUser(&$attributes) {
        $sspLoginName = $attributes['mail'][0];
        $displayName = (
                isset($attributes['displayName']) &&
                is_array($attributes['displayName']) &&
                count($attributes['displayName']) === 1
            ) ? $attributes['displayName'][0]
              : ($attributes['givenName'][0] . ' ' . $attributes['sn'][0]);

        $ch = curl_init();

        $data = [
            'surName'         => substr($attributes['sn'][0], 0, 50),
            'firstName'       => substr($attributes['givenName'][0], 0, 30),
            'email'           => substr($attributes['mail'][0], 0, 100),
            'tasLoginName'    => substr($sspLoginName, 0, 100),
            'branch'          => [
                'id' => $this->branchId,
            ],
            'optionalFields1' => [
                'text1' => substr($displayName, 0, 100),
            ],
        ];

        if ($ch === FALSE) {
            throw new \SimpleSAML\Error\Exception(
                'TopdeskUserCreator: Unable to initialise cURL session'
            );
        }

        try {
            curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/persons');
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_NOBODY, 0);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERNAME, $this->username);
            curl_setopt($ch, CURLOPT_PASSWORD, $this->password);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            if (($r = curl_exec($ch)) === FALSE) {
                Logger::error('TopdeskUserCreator: Unable to create user ' . $sspLoginName);
                throw new \SimpleSAML\Error\Exception(
                    'TopdeskUserCreator: Unable to create user'
                );
            } else {
                $rc = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                Logger::debug(
                    'TopdeskUserCreator: received response code ' .
                    ( $rc ?: '(none)' )  .
                    ' creating user ' . $sspLoginName .
                    ' and the body was ' . ( $r ?: '(none)' )
                );
                if ($rc !== 201) {
                    Logger::error(
                        'TopdeskUserCreator: unexpected response code ' .
                        ( $rc ?: '(none)' )  .
                        ' creating user ' . $sspLoginName .
                        ' and the body was ' . ( $r ?: '(none)' )
                    );
                    throw new \SimpleSAML\Error\Exception(
                        'TopdeskUserCreator: Unexpected response code'
                    );
                }
            }
        } catch(Exception $e) {
            curl_close($ch);
            throw $e;
        }
    }

    /**
     * Create or update TopDesk user
     *
     * Use the TopDesk API to ensure a user exists
     * 
     * @param array &$request  The current request
     * @return void
     */
    public function process(&$request) {
        Assert::isArray($request);
        Assert::keyExists($request, 'Attributes');

        $attributes = &$request['Attributes'];

        Assert::keyExists($attributes, 'mail');
        Assert::isArray($attributes['mail']);
        Assert::count($attributes['mail'], 1);
        Assert::string($attributes['mail'][0]);
        Assert::lengthBetween($attributes['mail'][0], 3, 100);

        if (!$this->checkUserExists($attributes)) {
            Logger::stats('user does not exist');
        
            Assert::isArray($attributes['sn']);
            Assert::count($attributes['sn'], 1);
            Assert::string($attributes['sn'][0]);
            Assert::minLength($attributes['sn'][0], 1);
            Assert::isArray($attributes['givenName']);
            Assert::count($attributes['givenName'], 1);
            Assert::string($attributes['givenName'][0]);
            Assert::minLength($attributes['givenName'][0], 1);

            Logger::stats('creating user');

            $this->createUser($attributes);
        } else {
            Logger::stats('user exists');
        }
    }
}

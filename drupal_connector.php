<?php

require_once(INCLUDE_DIR . 'class.usersession.php');

class NoDrupalFormFoundException extends RuntimeException {

    // Empty on purpose
}

class DrupalAuth {

    /** @var DrupalPluginConfig */
    public $config;

    public $access_token;

    public function __construct($config) {
        $this->config = $config;
    }

    /**
     * @param  array   $fields   with user and pass
     * @param  string  $context  can be either "client" or "agent".
     *
     * @return bool TRUE if login was successful
     * @throws \NoDrupalFormFoundException
     */
    public function authenticate($fields, $context) {
        $ch = $this->get_curl();

        // Set POST fields using what we extracted from the form:
        $fields += $this->getFormFields(curl_exec($ch));
        $post_fields_string = http_build_query($fields);

        // Set POST options:
        $ch = $this->get_curl();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields_string);

        // Perform login:
        curl_exec($ch);
        curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $ch = $this->get_curl();
        curl_setopt($ch, CURLOPT_URL, $this->config->get("drupal-$context-url"));
        curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return $http_status == 200;
    }

    private function getFormFields($html) {
        if (preg_match('/(<form .*?<\/form>)/is', $html, $matches)) {
            return $this->getInputs($matches[1]);
        }

        throw new NoDrupalFormFoundException(
            'Didn’t find a login form. Please check the plugin configuration.'
        );
    }

    private function getInputs($form) {
        $inputs = [];

        $elements = preg_match_all('/(<input[^>]+>)/is', $form, $matches);

        if ($elements > 0) {
            for ($i = 0; $i < $elements; $i++) {
                $el = preg_replace('/\s{2,}/', ' ', $matches[1][$i]);

                if (preg_match('/name=(?:["\'])?([^"\'\s]*)/i', $el, $name)) {
                    $name = $name[1];
                    $value = '';

                    if (preg_match('/value=\"(.+?)\"/i', $el, $value)) {
                        $value = $value[1];
                    }

                    $inputs[$name] = $value;
                }
            }
        }

        return $inputs;
    }

    /**
     * @return resource
     */
    private function get_curl() {
        if (!function_exists('curl_init') || !$ch = curl_init()) {
            die('You need to enable the curl extension before using this plugin.');
        }

        // extra headers
        $headers[] = "Accept: */*";
        $headers[] = "Connection: Keep-Alive";

        // basic curl options for all requests
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

        static $cookie_file_path;
        if (!$cookie_file_path) {
            $cookie_file_path = '/tmp/drupal_cookie_jar.' . mt_rand();
            register_shutdown_function(
                static function () use ($cookie_file_path) {
                    @unlink($cookie_file_path);
                }
            );
        }
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file_path);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file_path);

        curl_setopt($ch, CURLOPT_URL, $this->config->get('drupal-login-url'));

        return $ch;
    }

}

class DrupalStaffAuthBackend extends StaffAuthenticationBackend {

    use DrupalBackendTrait;
    public static $id = "drupal";

    public static $name = "Drupal";

    /** @var DrupalPluginConfig */
    public $config;

    /** @var DrupalAuth */
    private $drupal;

    public function __construct($config) {
        $this->config = $config;
        $this->drupal = new DrupalAuth($config);
    }

    public function authenticate($typed_username, $password = FALSE) {
        global $cfg;

        $username = $typed_username;
        $domain = $this->config->get('drupal-email-domain');
        if ($domain) {
            // Attach @example.com if needed.
            $username .= stripos($typed_username, $domain) === FALSE
                ? $domain
                : '';
        }
        try {
            $is_authenticated = $this->drupal->authenticate(
                ['name' => $username, 'pass' => $password],
                'agent'
            );
        } catch (NoDrupalFormFoundException $e) {
            return false;
        }

        if ($is_authenticated) {
            $ost_username = str_ireplace($domain, '', $typed_username);
            if ($acct = StaffSession::lookup($ost_username)) {
                return $acct;
            }

            $msg_template = Plugin::translate('auth-drupal')[0](
                "User name and password are correct, but you don't have an account for this ticket website yet. Please contact %s.");
            $msg = sprintf($msg_template, $cfg->getAdminEmail());
            return new AccessDenied($msg);
        }
        return FALSE;
    }

}

class DrupalClientAuthBackend extends UserAuthenticationBackend {

    use DrupalBackendTrait;

    public static $id = "drupal.client";

    public static $name = "Drupal";

    /** @var DrupalPluginConfig */
    private $config;

    /** @var DrupalAuth */
    private $drupal;

    public function __construct($config) {
        $this->config = $config;
        $this->drupal = new DrupalAuth($config);
    }

    public function authenticate($username, $password = FALSE) {
        if ($this->drupal->authenticate(
            ['name' => $username, 'pass' => $password],
            'agent')) {
            if ($acct = UserSession::lookup($username)) {
                return $acct;
            }
        }
        return FALSE;
    }

}

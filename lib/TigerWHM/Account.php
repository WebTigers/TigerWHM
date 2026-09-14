<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The account holder's side: what the cPanel page does, expressed without any HTML so it can be
 * tested with a fake UAPI. Everything cPanel-specific — which domains the account has, which PHP
 * each runs, creating the database — comes through the $api seam; the install itself is the engine.
 *
 * The flow for one click:
 *   domains()  → the user picks one (or names a new subdomain)
 *   gate()     → the domain's PHP is ≥ the host minimum; the engine's `check` passes
 *   provision() → database + user + grant through UAPI (the one step our web installer can't do)
 *   spec()     → the headless spec from form + domain + db + host defaults
 *   install()  → the engine; the result is what the page shows
 */
class TigerWHM_Account
{
    /** @var TigerWHM_Cpanel */
    protected $_api;
    /** @var array host defaults */
    protected $_cfg;
    /** @var string */
    protected $_home;
    /** @var string */
    protected $_user;

    public function __construct(TigerWHM_Cpanel $api, array $config, $home, $user)
    {
        $this->_api  = $api;
        $this->_cfg  = $config;
        $this->_home = rtrim((string) $home, '/');
        $this->_user = (string) $user;
    }

    // ------------------------------------------------------------------------------- domains

    /**
     * Every domain on the account with its docroot and PHP version — the picker's rows.
     * @return array<int,array{domain:string,docroot:string,kind:string,php:string,php_ok:bool}>
     */
    public function domains()
    {
        $data = $this->_api->uapi('DomainInfo', 'domains_data', ['format' => 'hash']);
        $rows = [];
        $push = static function ($d, $kind) use (&$rows) {
            if (!is_array($d) || empty($d['domain'])) { return; }
            $rows[] = ['domain' => (string) $d['domain'], 'docroot' => (string) ($d['documentroot'] ?? ''), 'kind' => $kind];
        };
        $push($data['main_domain'] ?? null, 'main');
        foreach (($data['sub_domains'] ?? []) as $d)   { $push($d, 'sub'); }
        foreach (($data['addon_domains'] ?? []) as $d) { $push($d, 'addon'); }

        $php = [];
        foreach ((array) $this->_api->uapi('LangPHP', 'php_get_vhost_versions', []) as $v) {
            if (is_array($v) && !empty($v['vhost'])) { $php[(string) $v['vhost']] = (string) ($v['version'] ?? ''); }
        }
        $min = (int) preg_replace('/\D/', '', $this->_cfg['min_php'] ?? 'ea-php81');
        foreach ($rows as &$r) {
            $r['php']    = $php[$r['domain']] ?? ($php[$rows[0]['domain']] ?? '');
            $r['php_ok'] = (int) preg_replace('/\D/', '', $r['php']) >= $min;
        }
        unset($r);
        return $rows;
    }

    /** Create a subdomain of the account's main domain (cPanel proposes public_html/<sub>). */
    public function addSubdomain($sub, $rootDomain)
    {
        $sub = strtolower(trim((string) $sub));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $sub)) { throw new InvalidArgumentException('Subdomain must be letters, digits and hyphens.'); }
        $this->_api->uapi('SubDomain', 'addsubdomain', ['domain' => $sub, 'rootdomain' => $rootDomain, 'dir' => 'public_html/' . $sub]);
        return $sub . '.' . $rootDomain;
    }

    // ------------------------------------------------------------------------------ database

    /**
     * Names for a new database + user for a domain, inside cPanel's prefix and length rules.
     * @return array{name:string,user:string,password:string}
     */
    public function dbNamesFor($domain)
    {
        $r = $this->_api->uapi('Mysql', 'get_restrictions', []);
        $prefix = (string) ($r['prefix'] ?? ($this->_user . '_'));
        $maxDb  = (int) ($r['max_database_name_length'] ?? 64);
        $maxUsr = (int) ($r['max_username_length'] ?? 32);
        // "tg" + the first label of the domain, cut to fit the tighter (username) limit.
        $label = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', (string) $domain)[0]));
        $short = 'tg' . substr($label, 0, max(1, $maxUsr - strlen($prefix) - 2));
        $name  = substr($prefix . $short, 0, $maxDb);
        $user  = substr($prefix . $short, 0, $maxUsr);
        return ['name' => $name, 'user' => $user, 'password' => self::password()];
    }

    /** Database + user + ALL PRIVILEGES, through UAPI (the step a user-space installer cannot do). */
    public function provision(array $names)
    {
        $this->_api->uapi('Mysql', 'create_database', ['name' => $names['name']]);
        $this->_api->uapi('Mysql', 'create_user',     ['name' => $names['user'], 'password' => $names['password']]);
        $this->_api->uapi('Mysql', 'set_privileges_on_database', ['user' => $names['user'], 'database' => $names['name'], 'privileges' => 'ALL PRIVILEGES']);
        return $names;
    }

    /** A DB password with no `"` (the installer writes INI) and no shell-hostile characters. */
    public static function password($len = 24)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) { $out .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        return $out;
    }

    // ---------------------------------------------------------------------------------- spec

    /** The headless spec for one install — pure, so it can be asserted exactly. */
    public function spec(array $form, array $domain, array $db)
    {
        $host = $domain['domain'];
        $spec = [
            'db'      => ['host' => 'localhost', 'name' => $db['name'], 'user' => $db['user'], 'password' => $db['password']],
            'paths'   => ['app_root' => $this->_home . '/' . $host . '/tiger-app', 'docroot' => $domain['docroot']],
            'layout'  => 'above-docroot',
            'site'    => ['url' => (!empty($form['https']) ? 'https' : 'http') . '://' . $host, 'name' => (string) ($form['site_name'] ?? $host)],
            'admin'   => [
                'username' => (string) ($form['username'] ?? ''),
                'email'    => (string) ($form['email'] ?? ''),
                'password' => (string) ($form['password'] ?? ''),
                'org'      => (string) ($form['site_name'] ?? $host),
            ],
            'locale'  => (string) ($form['locale'] ?? ($this->_cfg['locale'] ?? 'en')),
            'modules' => array_values(array_filter(array_map('strval', (array) ($form['modules'] ?? [])))),
            'theme'   => (string) ($form['theme'] ?? ''),
            'agent'   => !empty($form['agent']) && !empty($this->_cfg['allow_agent']),
            'config'  => TigerWHM_Config::specConfig($this->_cfg),
        ];
        return $spec;
    }

    /** Form-level problems the page can show before anything is created. */
    public static function formProblems(array $form)
    {
        $p = [];
        if (!filter_var((string) ($form['email'] ?? ''), FILTER_VALIDATE_EMAIL)) { $p[] = 'Enter a valid admin email address.'; }
        if (($form['username'] ?? '') !== '' && !preg_match('/^[a-z0-9_.\-]{3,32}$/i', (string) $form['username'])) { $p[] = 'Username: 3–32 letters, digits, dots, dashes or underscores.'; }
        if (strlen((string) ($form['password'] ?? '')) < 12) { $p[] = 'Admin password: at least 12 characters.'; }
        if (($form['password'] ?? '') !== ($form['password2'] ?? '')) { $p[] = 'The two admin passwords do not match.'; }
        if (($form['domain'] ?? '') === '' && ($form['new_sub'] ?? '') === '') { $p[] = 'Choose a domain, or name a new subdomain.'; }
        return $p;
    }
}

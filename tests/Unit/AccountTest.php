<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The account holder's flow with a fake UAPI: the domain picker, the database naming inside
 * cPanel's rules, the exact spec handed to the engine, and the form gate.
 */
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    private function api(array $over = []): TigerWHM_Cpanel
    {
        return TigerWHM_Cpanel::fake($over + [
            'DomainInfo::domains_data' => [
                'main_domain'   => ['domain' => 'example.com', 'documentroot' => '/home/cpuser/public_html'],
                'sub_domains'   => [['domain' => 'app.example.com', 'documentroot' => '/home/cpuser/public_html/app']],
                'addon_domains' => [['domain' => 'other.net', 'documentroot' => '/home/cpuser/other.net']],
            ],
            'LangPHP::php_get_vhost_versions' => [
                ['vhost' => 'example.com', 'version' => 'ea-php82'],
                ['vhost' => 'app.example.com', 'version' => 'ea-php74'],
            ],
            'Mysql::get_restrictions' => ['prefix' => 'cpuser_', 'max_database_name_length' => 64, 'max_username_length' => 32],
            'Mysql::create_database' => null, 'Mysql::create_user' => null, 'Mysql::set_privileges_on_database' => null,
            'SubDomain::addsubdomain' => null,
        ]);
    }

    private function acct(?TigerWHM_Cpanel $api = null, array $cfg = []): TigerWHM_Account
    {
        return new TigerWHM_Account($api ?: $this->api(), TigerWHM_Config::normalize($cfg + ['theme' => 'theme-grey-mist', 'modules' => ['docs']] + TigerWHM_Config::defaults()), '/home/cpuser', 'cpuser');
    }

    public function testDomainsCarryDocrootKindAndPhpGate(): void
    {
        $d = $this->acct()->domains();
        $this->assertSame(['example.com', 'app.example.com', 'other.net'], array_column($d, 'domain'));
        $this->assertSame(['main', 'sub', 'addon'], array_column($d, 'kind'));
        $this->assertSame('/home/cpuser/public_html/app', $d[1]['docroot']);
        $this->assertTrue($d[0]['php_ok']);
        $this->assertFalse($d[1]['php_ok'], 'ea-php74 is below ea-php81');
        $this->assertSame('ea-php82', $d[2]['php'], 'a domain with no entry inherits the main domain\'s PHP');
    }

    public function testDatabaseNamesRespectPrefixAndLengths(): void
    {
        $n = $this->acct()->dbNamesFor('averyveryverylongsubdomainname.example.com');
        $this->assertStringStartsWith('cpuser_tg', $n['name']);
        $this->assertLessThanOrEqual(32, strlen($n['user']), 'MySQL usernames cap at 32 incl. the prefix');
        $this->assertLessThanOrEqual(64, strlen($n['name']));
        $this->assertSame(24, strlen($n['password']));
        $this->assertStringNotContainsString('"', $n['password']);
        $this->assertSame('cpuser_tgapp', $this->acct()->dbNamesFor('app.example.com')['name']);
    }

    public function testDatabaseOverridesKeepThePrefixAndValidate(): void
    {
        $a = $this->acct(); $base = ['name' => 'cpuser_tgapp', 'user' => 'cpuser_tgapp', 'password' => 'GeneratedPw123456'];
        $this->assertSame($base, $a->dbOverrides($base, []), 'blank = generated');
        $o = $a->dbOverrides($base, ['db_name' => 'shop', 'db_user' => 'cpuser_shopper', 'db_password' => 'MyOwnPassword99']);
        $this->assertSame(['name' => 'cpuser_shop', 'user' => 'cpuser_shopper', 'password' => 'MyOwnPassword99'], $o);
        foreach ([['db_name' => 'bad-name'], ['db_user' => str_repeat('x', 40)], ['db_password' => 'short'], ['db_password' => 'has"quote12345']] as $bad) {
            try { $a->dbOverrides($base, $bad); $this->fail(json_encode($bad) . ' should be refused'); }
            catch (InvalidArgumentException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
    }

    public function testProvisionMakesTheThreeUapiCallsInOrder(): void
    {
        $api = $this->api(); $a = $this->acct($api);
        $a->provision(['name' => 'cpuser_tgapp', 'user' => 'cpuser_tgapp', 'password' => 'pw']);
        $calls = array_map(fn ($c) => $c[0] . '::' . $c[1], $api->calls);
        $this->assertSame(['Mysql::create_database', 'Mysql::create_user', 'Mysql::set_privileges_on_database'], $calls);
        $this->assertSame('ALL PRIVILEGES', $api->calls[2][2]['privileges']);
    }

    public function testSpecIsAboveDocrootUnderTheCpanelConventionWithHostDefaults(): void
    {
        $a = $this->acct(null, ['mail' => ['transport' => 'smtp', 'host' => 'relay.host', 'port' => 25], 'locale' => 'es']);
        $spec = $a->spec(
            ['site_name' => 'My App', 'email' => 'o@example.com', 'username' => 'owner', 'password' => 'Correct-Horse-9x', 'modules' => ['docs'], 'theme' => 'theme-grey-mist', 'agent' => true, 'https' => true],
            ['domain' => 'app.example.com', 'docroot' => '/home/cpuser/public_html/app'],
            ['name' => 'cpuser_tgapp', 'user' => 'cpuser_tgapp', 'password' => 'dbpw']
        );
        $this->assertSame('/home/cpuser/app.example.com/tiger-app', $spec['paths']['app_root']);
        $this->assertSame('/home/cpuser/public_html/app', $spec['paths']['docroot']);
        $this->assertSame('above-docroot', $spec['layout']);
        $this->assertSame('https://app.example.com', $spec['site']['url']);
        $this->assertSame('My App', $spec['admin']['org']);
        $this->assertSame('es', $spec['locale']);
        $this->assertSame(['mail.transport' => 'smtp', 'mail.smtp.host' => 'relay.host', 'mail.smtp.port' => '25', 'mail.smtp.ssl' => '', 'mail.smtp.auth' => ''], $spec['config']);
        $this->assertTrue($spec['agent']);
        // And the spec the engine will validate is valid.
        $this->assertSame('cpuser_tgapp', (new Tiger_Headless_Spec($spec))->get('db.name'));
    }

    public function testAgentIsOffWhenTheHostDisallowsIt(): void
    {
        $a = $this->acct(null, ['allow_agent' => false]);
        $spec = $a->spec(['email' => 'o@example.com', 'password' => 'x', 'agent' => true], ['domain' => 'example.com', 'docroot' => '/home/cpuser/public_html'], ['name' => 'n', 'user' => 'u', 'password' => 'p']);
        $this->assertFalse($spec['agent']);
    }

    public function testAddSubdomainValidatesAndReturnsTheFqdn(): void
    {
        $api = $this->api(); $a = $this->acct($api);
        $this->assertSame('shop.example.com', $a->addSubdomain('Shop', 'example.com'));
        $this->assertSame(['domain' => 'shop', 'rootdomain' => 'example.com', 'dir' => 'public_html/shop'], end($api->calls)[2]);
        $this->expectException(InvalidArgumentException::class);
        $a->addSubdomain('bad_name!', 'example.com');
    }

    public function testFormProblems(): void
    {
        $this->assertSame([], TigerWHM_Account::formProblems(['email' => 'o@example.com', 'password' => 'Correct-Horse-9x', 'password2' => 'Correct-Horse-9x', 'domain' => 'example.com']));
        $p = TigerWHM_Account::formProblems(['email' => 'nope', 'password' => 'short', 'password2' => 'other', 'username' => 'a']);
        $this->assertCount(5, $p);
    }

    public function testUapiFailuresSurfaceCpanelsOwnText(): void
    {
        $api = TigerWHM_Cpanel::fake(['Mysql::create_database' => function () { throw new RuntimeException('Mysql::create_database failed: The database “cpuser_tgapp” already exists.'); }]);
        $this->expectExceptionMessage('already exists');
        $this->acct($api)->provision(['name' => 'cpuser_tgapp', 'user' => 'u', 'password' => 'p']);
    }
}

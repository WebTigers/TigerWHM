<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The host's side with a fake whmapi1 + a fake engine subprocess: the join of discover() to WHM's
 * vhost table, the per-account update command (as the user, under the vhost's PHP), the PHP gate,
 * the Directory filter, the host-defaults normalizer, and the CGI request parser.
 */
use PHPUnit\Framework\TestCase;

final class FleetAndEngineTest extends TestCase
{
    private array $cmds = [];

    protected function setUp(): void
    {
        $this->cmds = [];
        TigerWHM_Engine::$exec = function ($cmd, $stdin) {
            $this->cmds[] = [$cmd, $stdin];
            if (str_contains($cmd, "'discover'")) {
                return [0, json_encode(['ok' => true, 'verb' => 'discover', 'summary' => ['live' => 2, 'updates_available' => 1, 'latest' => '1.7.0'], 'installs' => [
                    ['app_root' => '/home/alice/app.alice.com/tiger-app', 'docroot' => '/home/alice/public_html/app', 'layout' => 'above-docroot', 'version' => '1.6.4', 'installed' => true, 'db' => ['name' => 'alice_tgapp'], 'update_available' => true, 'latest' => '1.7.0'],
                    ['app_root' => '/home/bob/tiger-app', 'docroot' => '/home/bob/public_html', 'layout' => 'above-docroot', 'version' => '1.7.0', 'installed' => true, 'db' => ['name' => 'bob_tg'], 'update_available' => false, 'latest' => '1.7.0'],
                    ['app_root' => '/home/carol/site', 'docroot' => null, 'layout' => null, 'version' => '1.7.0', 'installed' => null, 'db' => ['name' => 'carol_x'], 'db_error' => 'denied', 'update_available' => false, 'latest' => '1.7.0'],
                ]]), ''];
            }
            if (str_contains($cmd, "'upgrade'")) { return [0, json_encode(['ok' => true, 'verb' => 'upgrade', 'version' => '1.7.0', 'steps' => []]), '']; }
            if (str_contains($cmd, "'login'"))   { return [0, json_encode(['ok' => true, 'verb' => 'login', 'path' => '/auth/magic/id/x/t/y', 'expires_in' => 120, 'email' => 'o@x']), '']; }
            if (str_contains($cmd, "'check'"))   { return [0, json_encode(['ok' => true, 'verb' => 'check', 'steps' => [['step' => 'requirements', 'status' => 'ok', 'detail' => 'fine']]]), '']; }
            return [1, '', 'boom'];
        };
    }

    protected function tearDown(): void { TigerWHM_Engine::$exec = null; }

    private function whm(): Closure
    {
        return function ($fn, $args) {
            if ($fn === 'php_get_vhost_versions') {
                return ['versions' => [
                    ['vhost' => 'alice.com',     'account' => 'alice', 'homedir' => '/home/alice', 'documentroot' => '/home/alice/public_html',     'version' => 'ea-php82', 'main_domain' => 1],
                    ['vhost' => 'app.alice.com', 'account' => 'alice', 'homedir' => '/home/alice', 'documentroot' => '/home/alice/public_html/app', 'version' => 'ea-php81', 'main_domain' => 0],
                    ['vhost' => 'bob.net',       'account' => 'bob',   'homedir' => '/home/bob',   'documentroot' => '/home/bob/public_html',       'version' => 'ea-php74', 'main_domain' => 1],
                ]];
            }
            return [];
        };
    }

    public function testFleetJoinsDiscoverToWhmVhosts(): void
    {
        $f = new TigerWHM_Fleet(TigerWHM_Config::defaults(), $this->whm(), '/opt/cpanel/ea-php83/root/usr/bin/php');
        $r = $f->installs(true);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['summary']['updates_available']);
        [$alice, $bob, $carol] = $r['installs'];
        $this->assertSame('alice', $alice['account']); $this->assertSame('app.alice.com', $alice['vhost']); $this->assertSame('ea-php81', $alice['php']);
        $this->assertSame('bob', $bob['account']);     $this->assertSame('bob.net', $bob['vhost']);       $this->assertSame('ea-php74', $bob['php']);
        $this->assertNull($bob['php_bin'], 'ea-php74 is below the minimum → no binary to run under');
        $this->assertSame('carol', $carol['account'], 'no vhost match (docroot layout) → account from the home path');
        $this->assertSame('', $carol['vhost']);
        $this->assertStringContainsString("'discover' '--root=/home' '--depth=4' '--check-updates'", $this->cmds[0][0]);
    }

    public function testUpgradeRunsAsTheAccountUserUnderItsPhp(): void
    {
        $f = new TigerWHM_Fleet(TigerWHM_Config::defaults(), $this->whm(), '/opt/cpanel/ea-php83/root/usr/bin/php');
        $row = ['app_root' => '/home/alice/app.alice.com/tiger-app', 'account' => 'alice', 'php_bin' => '/opt/cpanel/ea-php81/root/usr/bin/php'];
        $u = $f->upgrade($row);
        $this->assertTrue($u['ok']);
        $cmd = end($this->cmds)[0];
        $this->assertStringStartsWith("su -s /bin/sh 'alice' -c ", $cmd);
        $this->assertStringContainsString('ea-php81/root/usr/bin/php', $cmd);
        $this->assertStringContainsString("upgrade", $cmd);
        $this->assertStringContainsString("--app-root=/home/alice/app.alice.com/tiger-app", $cmd);
        // Below-minimum PHP → REFUSED (TIGER-134): never a different binary than the one serving the site.
        $n = count($this->cmds);
        $u2 = $f->upgrade(['app_root' => '/home/bob/tiger-app', 'account' => 'bob', 'php' => 'ea-php74', 'php_bin' => null]);
        $this->assertFalse($u2['ok']);
        $this->assertSame('php', $u2['error']['step']);
        $this->assertStringContainsString('MultiPHP Manager', $u2['error']['message']);
        $this->assertCount($n, $this->cmds, 'nothing was run');
        $u3 = $f->upgrade(['app_root' => '/x', 'account' => 'root', 'php_bin' => '/opt/cpanel/ea-php81/root/usr/bin/php']);
        $this->assertFalse($u3['ok']);
    }

    public function testBelowMinimumListsMainDomainsOnly(): void
    {
        $f = new TigerWHM_Fleet(TigerWHM_Config::defaults(), $this->whm(), '/php');
        $this->assertSame([['account' => 'bob', 'vhost' => 'bob.net', 'version' => 'ea-php74']], $f->belowMinimum());
    }

    public function testEngineSynthesizesAFailureWhenTheSubprocessGivesNoJson(): void
    {
        $e = new TigerWHM_Engine('/php');
        $r = $e->status('/nowhere');
        $this->assertFalse($r['ok']); $this->assertSame('engine', $r['error']['step']); $this->assertSame('boom', $r['error']['message']);
        $this->assertSame(1, $r['exit']);
    }

    public function testEnginePassesTheSpecOnStdinNeverArgv(): void
    {
        (new TigerWHM_Engine('/php'))->check(['db' => ['password' => 'S3cret']]);
        [$cmd, $stdin] = end($this->cmds);
        $this->assertStringNotContainsString('S3cret', $cmd);
        $this->assertStringContainsString('"S3cret"', $stdin);
        $this->assertStringContainsString("'--spec=-'", $cmd);
    }

    public function testLoginVerbReturnsThePathAndNeverPutsAnEmailInArgvUnescaped(): void
    {
        $r = (new TigerWHM_Engine('/php'))->login('/home/a/site/tiger-app', "o'x@example.com");
        $this->assertSame('/auth/magic/id/x/t/y', $r['path']);
        $this->assertStringContainsString("'--email=o'\\''x@example.com'", end($this->cmds)[0], 'shell-escaped');
    }

    public function testPhpForRespectsTheMinimum(): void
    {
        $this->assertNull(TigerWHM_Engine::phpFor('ea-php74'));
        $this->assertNull(TigerWHM_Engine::phpFor('garbage'));
        // ≥ minimum but not present on this (dev) machine → null too; the path shape is asserted by the fleet test above.
        $this->assertNull(TigerWHM_Engine::phpFor('ea-php81'));
    }

    public function testDirectoryFilterKeepsFreeInstallablesOnly(): void
    {
        $idx = ['modules' => [
            ['slug' => 'docs', 'module' => 'TigerDocs', 'type' => 'app', 'repository' => 'https://x', 'pricing' => ['model' => 'free'], 'version' => '1.0.3'],
            ['slug' => 'theme-grey-mist', 'module' => 'Grey Mist', 'type' => 'theme', 'repository' => 'https://x', 'pricing' => ['model' => 'free']],
            ['slug' => 'tiger-sdk-aws', 'module' => 'AWS SDK', 'type' => 'developer', 'repository' => 'https://x', 'pricing' => ['model' => 'free']],
            ['slug' => 'paidthing', 'module' => 'Paid', 'type' => 'app', 'repository' => 'https://x', 'pricing' => ['model' => 'licensed']],
            ['slug' => 'norepo', 'module' => 'X', 'type' => 'app', 'pricing' => ['model' => 'free']],
        ]];
        $f = TigerWHM_Directory::filter($idx);
        $this->assertTrue($f['available']);
        $this->assertSame(['theme-grey-mist'], array_column($f['themes'], 'slug'));
        $this->assertSame(['docs'], array_column($f['modules'], 'slug'));
        $this->assertFalse(TigerWHM_Directory::filter(null)['available']);
    }

    public function testCatalogNormalizesAndExpandsPacks(): void
    {
        $doc = json_decode((string) file_get_contents(__DIR__ . '/../../cpanel/catalog.snapshot.json'), true);
        $c = TigerWHM_Catalog::normalize($doc);
        $this->assertSame('theme-grey-mist', $c['featured']['theme']);
        $this->assertSame(['docs'], $c['featured']['modules']);
        $this->assertSame(['web-design', 'content', 'development', 'documents'], array_column($c['skill_packs'], 'id'));
        $skills = TigerWHM_Catalog::skillsFor($c, ['web-design', 'content']);
        $this->assertCount(9, $skills);
        $this->assertSame(['repo' => 'WebTigers/Skills', 'path' => 'skills/tiger-design', 'ref' => 'main'], $skills[0]);
        $this->assertSame('master', $skills[8]['ref'], 'a pack entry may pin a ref');
        // Junk upstream degrades, never fatals.
        $j = TigerWHM_Catalog::normalize(['skill_packs' => [['id' => 'Bad Id', 'skills' => [['repo' => 'x', 'path' => 'y']]], ['id' => 'ok', 'skills' => [['repo' => 'a/b', 'path' => '../x'], ['repo' => 'a/b', 'path' => 'good']]]]]);
        $this->assertSame(['ok'], array_column($j['skill_packs'], 'id'));
        $this->assertCount(1, $j['skill_packs'][0]['skills']);
        $this->assertSame(['featured' => ['theme' => '', 'modules' => []], 'skill_packs' => [], 'intro' => null], TigerWHM_Catalog::normalize('garbage'));
        // The page copy: plain text, https only; a bad card is dropped, a bad hero drops the whole intro.
        $this->assertSame('Build a website by talking to AI.', $c['intro']['hero']['title']);
        $this->assertCount(3, $c['intro']['cards']);
        $this->assertSame('https://webtigers.com/shop', $c['intro']['cards'][1]['link_url']);
        $hero = ['title' => 'T', 'text' => 'x', 'link_label' => 'l', 'link_url' => 'https://webtigers.com'];
        $i = TigerWHM_Catalog::normalize(['intro' => ['hero' => $hero + ['title' => '<b>T</b>'], 'cards' => [
            ['kicker' => 'k', 'title' => 't', 'text' => 'x', 'link_label' => 'l', 'link_url' => 'javascript:alert(1)'],
            ['kicker' => 'k', 'title' => 't', 'text' => 'x', 'link_label' => 'l', 'link_url' => 'https://webtigers.com/cms'],
        ]]])['intro'];
        $this->assertSame('T', $i['hero']['title'], 'tags stripped');
        $this->assertCount(1, $i['cards'], 'the javascript: card is gone');
        $this->assertNull(TigerWHM_Catalog::normalize(['intro' => ['hero' => ['title' => 'no text'], 'cards' => []]])['intro']);
    }

    public function testHostDefaultsFollowTheCatalogUnlessOverridden(): void
    {
        $cat = TigerWHM_Catalog::normalize(json_decode((string) file_get_contents(__DIR__ . '/../../cpanel/catalog.snapshot.json'), true));
        $pre = TigerWHM_Config::preselect(TigerWHM_Config::normalize(TigerWHM_Config::defaults()), $cat);
        $this->assertSame(['theme' => 'theme-grey-mist', 'modules' => ['docs'], 'skill_packs' => ['web-design', 'content']], $pre);
        $pre = TigerWHM_Config::preselect(TigerWHM_Config::normalize(['theme' => '', 'modules' => ['docs', 'tigershield'], 'skill_packs' => ['development']]), $cat);
        $this->assertSame(['theme' => '', 'modules' => ['docs', 'tigershield'], 'skill_packs' => ['development']], $pre, 'the host chose: no theme, two modules, one pack');
    }

    public function testConfigNormalizeCoercesAndRefusesJunk(): void
    {
        $c = TigerWHM_Config::normalize(['theme' => 'Theme Grey!', 'modules' => ['docs', 'Docs', 'bad slug'], 'locale' => 'en_US', 'branding' => '<b>Acme</b> Hosting', 'mail' => ['transport' => 'sendmail', 'host' => 'relay;rm', 'port' => 99999], 'min_php' => 'php8', 'allow_agent' => '1']);
        $this->assertSame('', $c['theme'], 'a set-but-bad theme becomes "" (Tiger default), not null (follow the catalog)');
        $this->assertSame(['docs'], $c['modules']);
        $this->assertSame('en', $c['locale']);
        $this->assertSame('Acme Hosting', $c['branding']);
        $this->assertSame(['transport' => '', 'host' => '', 'port' => 65535], $c['mail']);
        $this->assertSame('ea-php81', $c['min_php']);
        $this->assertTrue($c['allow_agent']);
        $this->assertSame([], TigerWHM_Config::specConfig($c), 'no relay → no config keys');
    }

    public function testCgiRequestParsingUnderCli(): void
    {
        $_GET = $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['QUERY_STRING'] = 'a=1&b=x%20y';
        $r = TigerWHM_Http::fromEnv();
        $this->assertSame('1', $r->g('a')); $this->assertSame('x y', $r->g('b')); $this->assertSame('', $r->g('zz'));
        $this->assertSame('&lt;b&gt;&quot;', TigerWHM_Http::e('<b>"'));
        $f = sys_get_temp_dir() . '/tigerwhm-nonce-' . bin2hex(random_bytes(3));
        $n = TigerWHM_Http::nonce($f);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $n);
        $this->assertSame($n, TigerWHM_Http::nonce($f), 'stable within the day');
        $this->assertTrue(TigerWHM_Http::nonceOk($f, $n)); $this->assertFalse(TigerWHM_Http::nonceOk($f, 'nope'));
        @unlink($f);
    }

    /** TIGER-136: the host chooses live `main` or two pinned commits; junk pins fall back to live, and the URLs follow. */
    public function testCatalogTrustModeIsLiveUnlessTwoFullShasPinIt(): void
    {
        $live = TigerWHM_Config::normalize(TigerWHM_Config::defaults());
        $this->assertSame('live', $live['catalog']['mode']);
        $this->assertStringContainsString('/TigerCatalog/main/catalog.json', TigerWHM_Catalog::url($live));
        $this->assertStringContainsString('/TigerVendors/main/data/index.json', TigerWHM_Directory::url($live));
        $half = TigerWHM_Config::normalize(['catalog' => ['mode' => 'pinned', 'catalog_ref' => str_repeat('a', 40), 'directory_ref' => 'main']] + TigerWHM_Config::defaults());
        $this->assertSame('live', $half['catalog']['mode'], 'a branch name is not a pin');
        $pin = TigerWHM_Config::normalize(['catalog' => ['mode' => 'pinned', 'catalog_ref' => str_repeat('A', 40), 'directory_ref' => str_repeat('b', 40)]] + TigerWHM_Config::defaults());
        $this->assertSame('pinned', $pin['catalog']['mode']);
        $this->assertSame('https://raw.githubusercontent.com/WebTigers/TigerCatalog/' . str_repeat('a', 40) . '/catalog.json', TigerWHM_Catalog::url($pin));
        $this->assertSame('https://raw.githubusercontent.com/WebTigers/TigerVendors/' . str_repeat('b', 40) . '/data/index.json', TigerWHM_Directory::url($pin));
        $this->assertLessThanOrEqual(5, TigerWHM_Catalog::FETCH_TIMEOUT, 'a page render never waits long on GitHub (TIGER-135)');
    }
}

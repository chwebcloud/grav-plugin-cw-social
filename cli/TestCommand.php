<?php

/**
 * Verbindungstest fuer cw-social: prueft Page-ID und Access-Token gegen die
 * Graph API und, wenn eingeschaltet, das verbundene Instagram-Konto -- ohne
 * etwas zu posten.
 *   bin/plugin cw-social test
 */

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\CwSocial\FacebookPublisher;
use Grav\Plugin\CwSocial\InstagramPublisher;

require_once __DIR__ . '/../classes/GraphApi.php';
require_once __DIR__ . '/../classes/FacebookPublisher.php';
require_once __DIR__ . '/../classes/InstagramPublisher.php';

class TestCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('test')->setDescription('Test the connection: Facebook page and Instagram account');
    }

    protected function serve(): int
    {
        $grav = Grav::instance();
        $grav['config']->init();
        $cfg = (array) $grav['config']->get('plugins.cw-social');

        if (empty($cfg['page_id']) || empty($cfg['access_token'])) {
            $this->output->writeln('<error>page_id or access_token missing (Admin -> Plugins -> CHWEB Social).</error>');
            return 1;
        }

        $version = (string) ($cfg['api_version'] ?? 'v21.0');
        $fb  = new FacebookPublisher((string) $cfg['page_id'], (string) $cfg['access_token'], $version);
        $res = $fb->test();
        if (!$res['ok']) {
            $this->output->writeln('<error>Facebook: connection failed: ' . $res['error'] . '</error>');
            return 1;
        }
        $this->output->writeln(sprintf('<info>Facebook: connected to page "%s"</info>', $res['name']));

        if (empty($cfg['instagram'])) {
            $this->output->writeln('Instagram: switched off (Admin -> Plugins -> CHWEB Social).');
            return 0;
        }
        $ig  = new InstagramPublisher((string) $cfg['page_id'], (string) $cfg['access_token'], $version, (string) ($cfg['ig_user_id'] ?? ''));
        $acc = $ig->account();
        if (!$acc['ok']) {
            $this->output->writeln('<error>Instagram: ' . $acc['error'] . '</error>');
            return 1;
        }
        $this->output->writeln(sprintf('<info>Instagram: connected to @%s (ID %s)</info>', $acc['username'], $acc['id']));
        return 0;
    }
}

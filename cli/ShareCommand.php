<?php

/**
 * Manuelles oder geplantes Teilen ueber die CLI (cron-tauglich).
 *
 * Standard ist ein Trockenlauf ueber alle faelligen Seiten:
 *   bin/plugin cw-social share                          # zeigt, was geteilt wuerde
 *   bin/plugin cw-social share --force                  # teilt alle faelligen
 *   bin/plugin cw-social share /events/chilbi --force   # genau eine Seite
 */

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\CwSocial\ShareWorker;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

require_once __DIR__ . '/../classes/GraphApi.php';
require_once __DIR__ . '/../classes/FacebookPublisher.php';
require_once __DIR__ . '/../classes/InstagramPublisher.php';
require_once __DIR__ . '/../classes/ShareWorker.php';

class ShareCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('share')
            ->setDescription('Share due pages on Facebook and Instagram (dry run unless --force)')
            ->addArgument('route', InputArgument::OPTIONAL, 'Route of a single page, for example /news/my-article')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Actually post (without it: show only)');
    }

    protected function serve(): int
    {
        $grav = Grav::instance();
        $grav['config']->init();
        $grav['uri']->init();
        $grav['pages']->init();

        $cfg = (array) $grav['config']->get('plugins.cw-social');
        if (empty($cfg['page_id']) || empty($cfg['access_token'])) {
            $this->output->writeln('<error>page_id or access_token missing (Admin -> Plugins -> CHWEB Social).</error>');
            return 1;
        }

        $worker = new ShareWorker($grav, $cfg);
        $force  = (bool) $this->input->getOption('force');
        $route  = $this->input->getArgument('route');

        if ($route) {
            $page = $grav['pages']->find($route);
            if (!$page) {
                $this->output->writeln('<error>Page not found: ' . $route . '</error>');
                return 1;
            }
            $kanaele = $worker->faellig($page);
            if (!$kanaele) {
                $this->output->writeln('Nothing due for ' . $route . ': sharing off, not published, dated in the future, or already shared (clear the post ID to share again).');
                return 0;
            }
            if (!$force) {
                $this->output->writeln('Dry run: would share -> ' . $route . ' on ' . implode(', ', $kanaele) . ' (add --force to post)');
                return 0;
            }
            $res = $worker->share($page, $kanaele);
            $this->report([$res]);
            return $res['ok'] ? 0 : 1;
        }

        $results = $worker->run(!$force);
        if (!$results) {
            $this->output->writeln('Nothing to share (no due page with sharing switched on).');
            return 0;
        }
        $this->report($results);
        foreach ($results as $r) {
            if (!$r['ok']) {
                return 1;
            }
        }
        return 0;
    }

    private function report(array $results): void
    {
        foreach ($results as $r) {
            $tag = $r['ok'] ? '<info>OK</info>' : '<error>FAILED</error>';
            $this->output->writeln(sprintf('%s  %s  %s', $tag, $r['route'], $r['detail']));
        }
    }
}
